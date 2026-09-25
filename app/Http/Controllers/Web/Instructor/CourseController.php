<?php

namespace App\Http\Controllers\Web\Instructor;

use App\Data\CourseSubmissionCheckResult;
use App\Exceptions\HistoricalQuizDeletionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\StoreChapterRequest;
use App\Http\Requests\Instructor\StoreCourseRequest;
use App\Http\Requests\Instructor\StoreLessonRequest;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Chapter;
use App\Models\ContentUpdate;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CourseVersion;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\OrderItem;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;
use App\Services\ContentUpdateDiffService;
use App\Services\ContentUpdateService;
use App\Services\CourseReviewService;
use App\Services\CourseSubmissionValidator;
use App\Services\CurriculumLessonService;
use App\Services\HistoricalQuizDeletionGuard;
use App\Services\InstructorCourseCategoryAccess;
use App\Services\NotificationService;
use App\Services\QuizAttemptService;
use App\Services\QuizService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseController extends Controller
{
    public function __construct(private readonly InstructorCourseCategoryAccess $courseCategoryAccess) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $courses = Course::where('instructor_id', auth()->id())
            ->with(['category:id,parent_id,name,status', 'category.parent:id,name,status'])
            ->withCount('enrollments')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('short_description', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, Course::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        $statusOptions = $this->statusOptions();
        $submissionChecks = $this->buildSubmissionChecks($courses->getCollection());

        return view('instructor.courses.index', compact('courses', 'statusOptions', 'search', 'status', 'submissionChecks'));
    }

    public function create(): View
    {
        $categories = $this->courseCategoryAccess->selectableCategories(auth()->user());

        return view('instructor.courses.create', compact('categories'));
    }

    public function store(StoreCourseRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('thumbnail')) {
            $validated['thumbnail'] = $request->file('thumbnail')->store('course-thumbnails', 'public');
        }

        $course = Course::create([
            ...$validated,
            'instructor_id' => auth()->id(),
            'slug' => $this->uniqueSlug($validated['title']),
            'sale_price' => $validated['discount_price'] ?? null,
            'status' => Course::STATUS_DRAFT,
            'is_published' => false,
            'published_at' => null,
        ]);

        return redirect()->route('instructor.courses.edit', $course)
            ->with('success', 'Tạo khóa học thành công. Khóa học đang được lưu ở trạng thái nháp.');
    }

    public function show(Course $course): View
    {
        $this->ensureOwned($course);

        $course->load([
            'category.parent',
            'courseSections.lessons' => fn ($query) => $query->orderBy('sort_order'),
            'chapters.lessons' => fn ($query) => $query->orderBy('sort_order'),
            'courseReviews',
            'instructor:id,name,email,avatar',
        ]);
        $course->loadCount(['lessons', 'enrollments', 'reviews']);

        $curriculumSections = $course->courseSections->isNotEmpty()
            ? $course->courseSections
            : $course->chapters;

        $totalLessons = $course->lessons_count ?: $curriculumSections->sum(fn ($section) => $section->lessons->count());

        $courseIncome = (float) OrderItem::query()
            ->where('course_id', $course->id)
            ->whereHas('order', fn (Builder $q) => $q->where('status', 'paid'))
            ->sum('instructor_earning');

        $studentCount = Enrollment::query()
            ->where('course_id', $course->id)
            ->whereIn('status', [Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED])
            ->distinct()
            ->count('user_id');

        return view('instructor.courses.show', compact('course', 'curriculumSections', 'totalLessons', 'courseIncome', 'studentCount'));
    }

    public function edit(Course $course): View
    {
        $this->ensureOwned($course);

        $course->load([
            'courseSections.lessons' => fn ($query) => $query->orderBy('sort_order')->with('videoModeration'),
            'chapters.lessons' => fn ($query) => $query->orderBy('sort_order')->with('videoModeration'),
            'category.parent',
            'courseReviews',
            'courseReviews.reviewer:id,name,email',
        ]);
        $categories = $this->courseCategoryAccess->selectableCategories(auth()->user());
        $statusOptions = $this->statusOptions();
        $submissionCheck = $course->submissionCheck();
        $courseReviews = $course->courseReviews;
        $contentUpdates = app(ContentUpdateService::class);
        $reviewState = $contentUpdates->instructorReviewState($course, auth()->user());
        $activeCourseUpdate = $contentUpdates->activeCourseMetadataUpdate($course, auth()->user());
        $formCourse = clone $course;
        $activeCourseVersionContext = ['current' => $course->publishedVersion?->version_number, 'proposed' => null];

        if ($activeCourseUpdate) {
            $candidate = CourseVersion::query()
                ->where('content_update_id', $activeCourseUpdate->id)
                ->where('course_id', $course->id)
                ->first();
            $proposal = $candidate
                ? $candidate->only(['title', 'short_description', 'description', 'objectives', 'category_id', 'level', 'language', 'price', 'discount_price', 'sale_price', 'thumbnail', 'preview_video'])
                : ($activeCourseUpdate->payload ?? []);
            $formCourse->forceFill($proposal);
            if (array_key_exists('category_id', $proposal)) {
                $formCourse->setRelation('category', Category::with('parent')->find($proposal['category_id']));
            }
            $activeCourseVersionContext = app(ContentUpdateDiffService::class)->versionContext($activeCourseUpdate);
        }
        $formReadOnly = $activeCourseUpdate?->isPending() ?? false;

        return view('instructor.courses.edit', compact(
            'course',
            'formCourse',
            'categories',
            'statusOptions',
            'submissionCheck',
            'courseReviews',
            'reviewState',
            'activeCourseUpdate',
            'activeCourseVersionContext',
            'formReadOnly',
        ));
    }

    public function update(StoreCourseRequest $request, Course $course): RedirectResponse
    {
        $this->ensureOwned($course);

        $validated = $request->validated();

        if ($course->isPublished()) {
            $pendingCourseUpdate = ContentUpdate::query()
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_COURSE)
                ->where('action', ContentUpdate::ACTION_UPDATE)
                ->where('entity_id', $course->id)
                ->where('status', ContentUpdate::STATUS_PENDING)
                ->exists();
            if ($pendingCourseUpdate) {
                return back()->with('error', 'Phiên bản này đang chờ Admin duyệt.');
            }

            if ($request->hasFile('thumbnail')) {
                $validated['thumbnail'] = $request->file('thumbnail')->store('course-thumbnails', 'public');
            }

            $result = app(ContentUpdateService::class)->saveCourseMetadataDraft(
                $course,
                array_merge($validated, ['sale_price' => $validated['discount_price'] ?? null]),
                $request->user(),
            );

            if (! $result['changed']) {
                return back()->with(
                    $result['reverted'] ? 'success' : 'info',
                    $result['reverted']
                        ? 'Đã bỏ bản nháp vì thông tin đề xuất trùng với bản đang xuất bản.'
                        : 'Không có thay đổi mới để lưu.',
                );
            }

            return back()->with('success', 'Đã lưu bản cập nhật thông tin khóa học. Bản cập nhật sẽ được hiển thị sau khi Admin duyệt.');
        }

        if ($request->hasFile('thumbnail')) {
            $this->deleteThumbnail($course);
            $validated['thumbnail'] = $request->file('thumbnail')->store('course-thumbnails', 'public');
        }

        $course->update([
            ...$validated,
            'sale_price' => $validated['discount_price'] ?? null,
        ]);

        return back()->with('success', 'Đã lưu nháp khóa học.');
    }

    public function destroy(Course $course): RedirectResponse
    {
        $this->ensureOwned($course);

        if ($this->hasBusinessRecords($course)) {
            $course->update([
                'status' => Course::STATUS_ARCHIVED,
                'is_published' => false,
                'published_at' => null,
            ]);

            return redirect()->route('instructor.courses.index')
                ->with('success', 'Khóa học đã có dữ liệu học viên hoặc đơn hàng nên được chuyển sang trạng thái lưu trữ.');
        }

        try {
            $lessons = $course->lessons()->get();
            app(HistoricalQuizDeletionGuard::class)->assertCourseCanBeHardDeleted($course);

            DB::transaction(function () use ($course, $lessons): void {
                $lessons->each(fn (Lesson $lesson) => $lesson->delete());
                $course->delete();
            });
        } catch (HistoricalQuizDeletionException $exception) {
            return back()->withErrors(['course' => $exception->getMessage()]);
        }

        $this->deleteThumbnail($course);

        return redirect()->route('instructor.courses.index')
            ->with('success', 'Đã xóa khóa học.');
    }

    public function archive(Course $course): RedirectResponse
    {
        $this->ensureOwned($course);

        if ($course->status !== Course::STATUS_PUBLISHED) {
            return back()->with('error', 'Chỉ có thể ẩn khóa học đang được xuất bản.');
        }

        $course->update([
            'status' => Course::STATUS_ARCHIVED,
            'is_published' => false,
            'published_at' => null,
        ]);

        return back()->with('success', 'Đã ẩn khóa học khỏi trang học viên.');
    }

    public function addChapter(StoreChapterRequest $request, Course $course): RedirectResponse
    {
        $this->ensureOwned($course);
        $validated = $request->validated();

        if ($course->isPublished()) {
            app(ContentUpdateService::class)->recordPendingUpdate(
                ContentUpdate::TYPE_CHAPTER,
                ContentUpdate::ACTION_CREATE,
                $course->id,
                null,
                array_merge($validated, ['sort_order' => $course->courseSections()->count()]),
                $request->user(),
            );

            return back()->with('success', 'Đã lưu bản cập nhật chương học. Thay đổi sẽ áp dụng sau khi Admin duyệt.');
        }

        Chapter::create([
            'course_id' => $course->id,
            'title' => $validated['title'],
            'sort_order' => $course->chapters()->count(),
        ]);

        return back()->with('success', 'Đã thêm chương mới.');
    }

    public function addLesson(StoreLessonRequest $request, Chapter $chapter, CurriculumLessonService $lessonService): RedirectResponse
    {
        $this->ensureOwned($chapter->course);

        $validated = $request->validated();
        $course = $chapter->course;

        if ($course->isPublished()) {
            $sectionId = Lesson::query()
                ->where('course_id', $course->id)
                ->where('chapter_id', $chapter->id)
                ->whereNotNull('section_id')
                ->value('section_id');
            $section = $sectionId
                ? CourseSection::query()
                    ->where('course_id', $course->id)
                    ->find($sectionId)
                : CourseSection::query()
                    ->where('course_id', $course->id)
                    ->where('title', $chapter->title)
                    ->where('sort_order', $chapter->sort_order)
                    ->first();

            $sortOrder = Lesson::query()
                ->where('course_id', $course->id)
                ->where('chapter_id', $chapter->id)
                ->count();

            app(ContentUpdateService::class)->recordPendingUpdate(
                ContentUpdate::TYPE_LESSON,
                ContentUpdate::ACTION_CREATE,
                $course->id,
                null,
                array_merge($validated, [
                    'section_id' => $section?->id,
                    'chapter_id' => $chapter->id,
                    'sort_order' => $sortOrder,
                    'is_preview' => $request->boolean('is_preview'),
                    'status' => $validated['status'] ?? 'draft',
                ]),
                $request->user(),
                ContentUpdate::STATUS_DRAFT,
            );

            return back()->with('success', 'Đã lưu bản cập nhật bài học. Thay đổi sẽ áp dụng sau khi Admin duyệt.');
        }

        Lesson::create([
            ...$validated,
            'course_id' => $chapter->course_id,
            'chapter_id' => $chapter->id,
            'sort_order' => $chapter->lessons()->count(),
            'is_preview' => $request->boolean('is_preview'),
            'status' => $validated['status'] ?? 'draft',
        ]);

        return back()->with('success', 'Đã thêm bài giảng.');
    }

    public function submit(Request $request, Course $course, CourseReviewService $reviewService): RedirectResponse
    {
        $this->ensureOwned($course);

        // Repeated submission is idempotent at the controller boundary.
        if (in_array($course->status, [Course::STATUS_PENDING, Course::STATUS_PENDING_UPDATE, 'under_review'], true)) {
            return redirect()
                ->to($request->headers->get('referer') ?: route('instructor.courses.index'))
                ->with('info', 'Cập nhật này đã được gửi duyệt.');
        }

        abort_unless($course->isEditable(), 403, 'Khóa học không ở trạng thái cho phép gửi duyệt.');

        $reviewState = app(ContentUpdateService::class)->instructorReviewState($course, $request->user());
        $hasPublishedContent = (bool) $course->is_published || in_array($course->status, [
            Course::STATUS_APPROVED,
            Course::STATUS_PUBLISHED,
            Course::STATUS_PENDING_UPDATE,
            Course::STATUS_REJECTED_UPDATE,
        ], true);
        if ($hasPublishedContent && ! $reviewState['hasDraftUpdates']) {
            return back()->with('error', 'Không có thay đổi mới để gửi duyệt.');
        }
        if ($reviewState['hasPendingUpdates']) {
            return back()->with('error', 'Đang có một lượt duyệt chưa được xử lý.');
        }

        if (! $course->copyright_agreed) {
            $request->validate([
                'copyright_agreed' => ['required', 'accepted'],
            ], [
                'copyright_agreed.accepted' => 'Bạn phải đọc và đồng ý với cam kết bản quyền trước khi gửi duyệt.',
            ]);
        }

        if ($course->hasIncompleteHlsVideos()) {
            return back()->with('error', 'Khóa học chưa thể gửi duyệt vì video vẫn đang được xử lý bảo mật.');
        }

        $submissionCheck = $course->submissionCheck();
        if (! $submissionCheck->passes()) {
            return back()
                ->with('error', $submissionCheck->summaryMessage())
                ->withErrors(['submission' => $submissionCheck->errorMessages()]);
        }

        $submittedCount = $reviewState['draftCount'];
        $reviewService->submitForReview($course, $request->user());

        return redirect()
            ->to($request->headers->get('referer') ?: route('instructor.courses.index'))
            ->with(
                'success',
                $submittedCount > 0
                    ? "Đã gửi {$submittedCount} thay đổi để Admin duyệt."
                    : 'Đã gửi khóa học để Admin duyệt.',
            );
    }

    public function submitPage(Course $course): RedirectResponse
    {
        $this->ensureOwned($course);

        if (in_array($course->status, [Course::STATUS_PENDING, Course::STATUS_PENDING_UPDATE, 'under_review'], true)) {
            return redirect()
                ->route('instructor.courses.index')
                ->with('success', 'Khóa học đã được gửi và đang trong quá trình chờ Admin duyệt.');
        }

        if ($course->isEditable()) {
            return redirect()->route('instructor.courses.edit', $course);
        }

        return redirect()->route('instructor.courses.index');
    }

    public function students(Course $course, Request $request): View
    {
        $this->ensureOwned($course);

        $search = trim((string) $request->query('search'));
        $enrollments = $this->enrollmentQuery($course, $request)->paginate(20)->withQueryString();
        $stats = $this->studentStatsForCourse($course, $enrollments->pluck('user_id'));

        return view('instructor.courses.students', [
            'course' => $course,
            'enrollments' => $enrollments,
            'search' => $search,
            'latestProgress' => $stats['latestProgress'],
            'quizStats' => $stats['quizStats'],
            'labStats' => $stats['labStats'],
        ]);
    }

    public function studentDetail(Course $course, User $student): View
    {
        // 1. Authorization: Verify ownership or admin access
        abort_unless($course->isOwnedBy(auth()->user()) || (auth()->check() && auth()->user()->isAdmin()), 403, 'Bạn không có quyền truy cập thông tin học viên của khóa học này.');

        // 2. Fetch Enrollment (Returns 404 if student is not registered in this course)
        $enrollment = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        // 3. Fetch all lessons in this course using the standard logic
        $lessons = Lesson::query()
            ->where(function ($query) use ($course) {
                $query->where('course_id', $course->id)
                    ->orWhereHas('section', fn ($q) => $q->where('course_id', $course->id))
                    ->orWhereHas('chapter', fn ($q) => $q->where('course_id', $course->id));
            })
            ->with(['quiz', 'assignment'])
            ->orderBy('chapter_id')
            ->orderBy('section_id')
            ->orderBy('sort_order')
            ->get();

        // 4. Fetch lesson progress of the student for all lessons in this course
        $lessonProgress = LessonProgress::query()
            ->where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->get()
            ->keyBy('lesson_id');

        // 5. Fetch quiz attempts of the student for quizzes in this course
        $quizzes = Quiz::query()
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->get();

        $quizAttempts = QuizAttempt::query()
            ->where('user_id', $student->id)
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->orderByDesc('completed_at')
            ->get()
            ->groupBy('quiz_id');

        // 6. Fetch assignment submissions of the student for assignments in this course
        $assignments = Assignment::query()
            ->where('course_id', $course->id)
            ->get();

        $submissions = Submission::query()
            ->where('user_id', $student->id)
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy('assignment_id');

        // 7. Check if certificate is issued
        $certificate = Certificate::query()
            ->where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->first();

        // 8. Determine "Last active time" (Lần hoạt động gần nhất)
        // We will collect timestamps from multiple actual data sources:
        // - enrollment->last_accessed_at (Last access to the course)
        // - lesson_progress->last_watched_at (Last watch/read of a lesson)
        // - quiz_attempt->completed_at / started_at (Last quiz attempt completed)
        // - submission->submitted_at / graded_at (Last submission activity)
        $timestamps = collect();

        if ($enrollment->last_accessed_at) {
            $timestamps->push($enrollment->last_accessed_at);
        }

        foreach ($lessonProgress as $progress) {
            if ($progress->last_watched_at) {
                $timestamps->push($progress->last_watched_at);
            }
            if ($progress->completed_at) {
                $timestamps->push($progress->completed_at);
            }
        }

        foreach ($quizAttempts as $attempts) {
            foreach ($attempts as $attempt) {
                if ($attempt->completed_at) {
                    $timestamps->push($attempt->completed_at);
                }
                if ($attempt->started_at) {
                    $timestamps->push($attempt->started_at);
                }
            }
        }

        foreach ($submissions as $submission) {
            if ($submission->submitted_at) {
                $timestamps->push($submission->submitted_at);
            }
            if ($submission->graded_at) {
                $timestamps->push($submission->graded_at);
            }
        }

        // Sort all timestamps to find the absolute latest active date
        $lastActiveAt = $timestamps->isNotEmpty() ? $timestamps->max() : null;

        // 9. Build Activity Timeline
        $activities = collect();

        // Enrollment event
        $activities->push((object) [
            'type' => 'enrollment',
            'title' => 'Ghi danh khóa học',
            'description' => 'Đã tham gia và kích hoạt học tập trong khóa học.',
            'time' => $enrollment->enrolled_at ?? $enrollment->created_at,
        ]);

        // Lesson progress completion events
        foreach ($lessonProgress as $progress) {
            if ($progress->is_completed && $progress->completed_at) {
                $lesson = $lessons->firstWhere('id', $progress->lesson_id);
                $activities->push((object) [
                    'type' => 'lesson_completed',
                    'title' => 'Hoàn thành bài học',
                    'description' => $lesson ? "Đã hoàn thành bài học: \"{$lesson->title}\"" : 'Đã hoàn thành bài học',
                    'time' => $progress->completed_at,
                ]);
            }
        }

        // Quiz attempts events
        foreach ($quizAttempts as $quizId => $attempts) {
            $quiz = $quizzes->firstWhere('id', $quizId);
            foreach ($attempts as $attempt) {
                if ($attempt->completed_at) {
                    $statusStr = $attempt->passed ? 'Đạt' : 'Không đạt';
                    $activities->push((object) [
                        'type' => 'quiz_attempt',
                        'title' => 'Làm bài trắc nghiệm',
                        'description' => $quiz
                            ? "Làm bài trắc nghiệm: \"{$quiz->title}\" - Kết quả: {$attempt->score}/{$attempt->total_score} ({$attempt->percent}%) - {$statusStr}"
                            : "Làm bài trắc nghiệm - Kết quả: {$attempt->percent}% - {$statusStr}",
                        'time' => $attempt->completed_at,
                    ]);
                }
            }
        }

        // Submissions events
        foreach ($submissions as $assignmentId => $submission) {
            $assignment = $assignments->firstWhere('id', $assignmentId);
            if ($submission->submitted_at) {
                $activities->push((object) [
                    'type' => 'assignment_submission',
                    'title' => 'Nộp bài thực hành',
                    'description' => $assignment ? "Đã nộp bài thực hành: \"{$assignment->title}\"" : 'Đã nộp bài thực hành',
                    'time' => $submission->submitted_at,
                ]);
            }
            if ($submission->status === 'graded' && $submission->graded_at) {
                $activities->push((object) [
                    'type' => 'assignment_graded',
                    'title' => 'Bài thực hành được chấm điểm',
                    'description' => $assignment
                        ? "Bài thực hành \"{$assignment->title}\" đã được chấm: {$submission->score}/{$assignment->max_score} điểm"
                        : "Bài thực hành đã được chấm: {$submission->score} điểm",
                    'time' => $submission->graded_at,
                ]);
            }
        }

        // Certificate event
        if ($certificate && $certificate->issued_at) {
            $activities->push((object) [
                'type' => 'certificate',
                'title' => 'Nhận chứng chỉ',
                'description' => "Được cấp chứng chỉ hoàn thành khóa học (Mã số: {$certificate->certificate_code})",
                'time' => $certificate->issued_at,
            ]);
        }

        // Sort timeline chronologically (newest first)
        $activities = $activities->sortByDesc('time')->values();

        // 10. Compute Alerts (Cảnh báo học tập)
        $alerts = collect();

        // Warning: Chưa bắt đầu học
        $hasStarted = $lessonProgress->isNotEmpty() || $quizAttempts->isNotEmpty() || $submissions->isNotEmpty();
        if ($enrollment->progress_percent == 0 && ! $hasStarted) {
            $alerts->push('Học viên chưa bắt đầu học khóa học này.');
        }

        // Warning: Tiến độ thấp (đăng ký >= 7 ngày nhưng tiến độ học < 20%)
        $enrolledDays = ($enrollment->enrolled_at ?? $enrollment->created_at)->diffInDays(now());
        if ($enrolledDays >= 7 && $enrollment->progress_percent < 20) {
            $alerts->push("Tiến độ học tập chậm ({$enrollment->progress_percent}% hoàn thành sau {$enrolledDays} ngày ghi danh).");
        }

        // Warning: Quiz chưa đạt (có làm quiz nhưng tất cả các lần đều không qua)
        foreach ($quizzes as $quiz) {
            $attempts = $quizAttempts->get($quiz->id, collect());
            if ($attempts->isNotEmpty()) {
                $hasPassed = $attempts->where('passed', true)->isNotEmpty();
                if (! $hasPassed) {
                    $alerts->push("Bài trắc nghiệm \"{$quiz->title}\" chưa đạt điểm yêu cầu (Điểm đạt yêu cầu: {$quiz->pass_score}%).");
                }
            }
        }

        // Warning: Bài thực hành chưa nộp hoặc chưa đạt
        foreach ($assignments as $assignment) {
            $submission = $submissions->get($assignment->id);
            if (! $submission) {
                if ($assignment->is_required) {
                    $alerts->push("Học viên chưa nộp bài thực hành bắt buộc: \"{$assignment->title}\".");
                }
            } else {
                if ($submission->status === 'graded') {
                    $passingLimit = $assignment->passing_score ?? 70;
                    if ($submission->score < $passingLimit) {
                        $alerts->push("Bài thực hành \"{$assignment->title}\" chưa đạt điểm yêu cầu (Đạt {$submission->score}/{$assignment->max_score} điểm, yêu cầu tối thiểu {$passingLimit} điểm).");
                    }
                }
            }
        }

        // Warning: Không hoạt động quá 7 ngày (chỉ báo nếu học viên đã từng hoạt động)
        if ($hasStarted && $lastActiveAt) {
            $inactiveDays = $lastActiveAt->diffInDays(now());
            if ($inactiveDays >= 7) {
                $alerts->push("Học viên không có hoạt động học tập nào trong {$inactiveDays} ngày qua.");
            }
        }

        // 11. Statistical calculations
        // Quiz Average score
        $bestAttempts = collect();
        foreach ($quizAttempts as $quizId => $attempts) {
            $bestAttempts->put($quizId, $attempts->max('percent'));
        }
        $avgQuizScore = $bestAttempts->isNotEmpty() ? round($bestAttempts->avg(), 0) : null;
        $maxQuizScore = $bestAttempts->isNotEmpty() ? $bestAttempts->max() : null;

        // Assignments stats
        $totalAssignments = $assignments->count();
        $submittedAssignmentsCount = $submissions->count();
        $gradedAssignments = $submissions->where('status', 'graded');
        $gradedAssignmentsCount = $gradedAssignments->count();
        $averageAssignmentScore = $gradedAssignmentsCount > 0 ? round($gradedAssignments->avg('score'), 1) : null;

        return view('instructor.courses.student_detail', compact(
            'course',
            'student',
            'enrollment',
            'lessons',
            'lessonProgress',
            'quizzes',
            'quizAttempts',
            'assignments',
            'submissions',
            'certificate',
            'lastActiveAt',
            'activities',
            'alerts',
            'avgQuizScore',
            'maxQuizScore',
            'totalAssignments',
            'submittedAssignmentsCount',
            'gradedAssignmentsCount',
            'averageAssignmentScore'
        ));
    }

    public function studentQuizAttempt(
        Course $course,
        User $student,
        Quiz $quiz,
        QuizAttempt $attempt,
        QuizService $quizService,
    ): View {
        abort_unless($course->isOwnedBy(auth()->user()) || (auth()->check() && auth()->user()->isAdmin()), 403, 'Bạn không có quyền truy cập thông tin học viên của khóa học này.');

        abort_unless((int) $attempt->user_id === (int) $student->id, 404, 'Lần làm bài không thuộc về học viên này.');
        abort_unless((int) $attempt->quiz_id === (int) $quiz->id, 404, 'Lần làm bài không thuộc về bài quiz này.');

        $quizLesson = $quiz->lesson;
        $belongsToCourse = $quizLesson && (
            (int) $quizLesson->course_id === (int) $course->id ||
            $quizLesson->section?->course_id === $course->id ||
            $quizLesson->chapter?->course_id === $course->id
        );
        abort_unless($belongsToCourse, 404, 'Bài quiz không thuộc khóa học này.');

        $policy = app(QuizAttemptService::class)->reviewPolicy($attempt, auth()->user());
        $review = $quizService->buildAttemptReview($attempt, $policy);

        return view('instructor.courses.student_quiz_review', [
            'course' => $course,
            'student' => $student,
            'quiz' => $quiz,
            'attempt' => $review['attempt'],
            'review' => $review,
        ]);
    }

    public function exportStudents(Course $course, Request $request): StreamedResponse
    {
        $this->ensureOwned($course);

        $enrollments = $this->enrollmentQuery($course, $request)->get();
        $stats = $this->studentStatsForCourse($course, $enrollments->pluck('user_id'));
        $filename = 'hoc-vien-'.Str::slug($course->title).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($enrollments, $stats) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['Tên học viên', 'Email', 'Ngày ghi danh', 'Tiến độ (%)', 'Bài học gần nhất', 'Trạng thái hoàn thành']);

            foreach ($enrollments as $enrollment) {
                $userId = $enrollment->user_id;
                $progress = $stats['latestProgress']->get($userId);
                $enrolledAt = $enrollment->enrolled_at ?? $enrollment->created_at;

                fputcsv($handle, [
                    $enrollment->user->name,
                    $enrollment->user->email,
                    $enrolledAt?->format('d/m/Y') ?? '',
                    number_format((float) $enrollment->progress_percent, 0),
                    $progress?->lesson?->title ?? 'Chưa học bài nào',
                    $this->completionLabel($enrollment),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function sendNotification(Request $request, Course $course, User $student, NotificationService $notificationService): JsonResponse
    {
        $this->ensureOwned($course);

        $isEnrolled = Enrollment::where('course_id', $course->id)
            ->where('user_id', $student->id)
            ->exists();

        if (! $isEnrolled) {
            return response()->json(['success' => false, 'message' => 'Học viên này chưa đăng ký khóa học.'], 422);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
        ]);

        $notificationService->send(
            $student,
            trim($validated['title']),
            trim($validated['message']),
            'instructor_announcement',
            route('courses.show', $course->slug)
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã gửi thông báo thành công cho học viên '.$student->name.'.',
        ]);
    }

    public function revenue(Request $request): View
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,'.(now()->year + 1)],
        ]);

        $user = auth()->user();
        $instructorCourses = Course::where('instructor_id', $user->id)->get()->keyBy('id');
        $courseIds = $instructorCourses->keys()->toArray();

        if (empty($courseIds)) {
            return view('instructor.revenue', [
                'totalGross' => 0,
                'totalCommission' => 0,
                'totalRevenue' => 0,
                'courseSales' => [],
                'studentPurchases' => [],
                'recentOrders' => collect(),
            ]);
        }

        $applyOrderFilters = static function ($query) use ($filters): void {
            $query->where('status', 'paid');
            if (! empty($filters['start_date'])) {
                $query->whereDate('created_at', '>=', $filters['start_date']);
            }
            if (! empty($filters['end_date'])) {
                $query->whereDate('created_at', '<=', $filters['end_date']);
            }
            if (! empty($filters['month'])) {
                $query->whereMonth('created_at', $filters['month']);
            }
            if (! empty($filters['year'])) {
                $query->whereYear('created_at', $filters['year']);
            }
        };

        $itemQuery = OrderItem::query()
            ->whereIn('course_id', $courseIds)
            ->whereHas('order', $applyOrderFilters);

        $totals = (clone $itemQuery)
            ->selectRaw('COALESCE(SUM(commission_amount + instructor_earning), 0) as gross')
            ->selectRaw('COALESCE(SUM(commission_amount), 0) as commission')
            ->selectRaw('COALESCE(SUM(instructor_earning), 0) as earning')
            ->first();

        $totalGross = (float) $totals->gross;
        $totalCommission = (float) $totals->commission;
        $totalRevenue = (float) $totals->earning;

        $courseRevenue = (clone $itemQuery)
            ->select('course_id')
            ->selectRaw('COUNT(DISTINCT order_id) as sales')
            ->selectRaw('SUM(commission_amount + instructor_earning) as gross')
            ->selectRaw('SUM(commission_amount) as commission')
            ->selectRaw('SUM(instructor_earning) as total')
            ->groupBy('course_id')
            ->orderByDesc('gross')
            ->get()
            ->each(function (OrderItem $item) use ($instructorCourses): void {
                $item->setRelation('course', $instructorCourses->get($item->course_id));
            });

        $studentPurchases = (clone $itemQuery)
            ->with(['order.user:id,name,email,avatar', 'course:id,title'])
            ->get()
            ->sortByDesc(fn (OrderItem $item) => $item->order?->created_at)
            ->map(function (OrderItem $item): object {
                $commission = (float) $item->commission_amount;
                $earning = (float) $item->instructor_earning;

                return (object) [
                    'order_id' => $item->order_id,
                    'order_code' => $item->order?->order_code ?? ('ORD-'.$item->order_id),
                    'user' => $item->order?->user,
                    'course_title' => $item->course?->title ?? 'Khóa học',
                    'price' => $commission + $earning,
                    'commission_amount' => $commission,
                    'instructor_earning' => $earning,
                    'payment_method' => strtoupper($item->order?->payment_method ?? 'ONLINE'),
                    'purchased_at' => $item->order?->created_at,
                ];
            })
            ->values();

        $filters = array_merge([
            'start_date' => null,
            'end_date' => null,
            'month' => null,
            'year' => null,
        ], $filters);

        return view('instructor.revenue', compact('totalGross', 'totalCommission', 'totalRevenue', 'courseRevenue', 'studentPurchases', 'filters'));
    }

    protected function ensureOwned(Course $course): void
    {
        abort_unless(
            $this->courseCategoryAccess->canManageCourse(auth()->user(), $course),
            403,
            'Bạn không có quyền chỉnh sửa nội dung khóa học này.'
        );
    }

    private function enrollmentQuery(Course $course, Request $request)
    {
        $search = trim((string) $request->query('search'));

        return Enrollment::query()
            ->where('course_id', $course->id)
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->with('user:id,name,email,avatar')
            ->orderByDesc('enrolled_at')
            ->orderByDesc('created_at');
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $userIds
     * @return array{latestProgress: Collection<int, LessonProgress>, quizStats: Collection<int, float|null>, labStats: Collection<int, array{score: int, max: int}|null>}
     */
    private function studentStatsForCourse(Course $course, Collection|array $userIds): array
    {
        $userIds = collect($userIds)->filter()->unique()->values();

        if ($userIds->isEmpty()) {
            return [
                'latestProgress' => collect(),
                'quizStats' => collect(),
                'labStats' => collect(),
            ];
        }

        $latestProgress = LessonProgress::query()
            ->where('course_id', $course->id)
            ->whereIn('user_id', $userIds)
            ->with('lesson:id,title')
            ->orderByDesc('last_watched_at')
            ->orderByDesc('updated_at')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        $quizIds = Quiz::query()
            ->whereHas('lesson', fn ($query) => $query->where('course_id', $course->id))
            ->pluck('id');

        $quizStats = collect();
        if ($quizIds->isNotEmpty()) {
            $attempts = QuizAttempt::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('quiz_id', $quizIds)
                ->get()
                ->groupBy('user_id');

            foreach ($userIds as $userId) {
                $userAttempts = $attempts->get($userId, collect());
                if ($userAttempts->isEmpty()) {
                    $quizStats[$userId] = null;

                    continue;
                }

                $bestByQuiz = $userAttempts
                    ->groupBy('quiz_id')
                    ->map(fn (Collection $group) => (float) $group->max('percent'));

                $quizStats[$userId] = round($bestByQuiz->avg(), 0);
            }
        }

        $assignments = Assignment::query()
            ->where('course_id', $course->id)
            ->get(['id', 'max_score']);

        $labStats = collect();
        if ($assignments->isNotEmpty()) {
            $submissions = AssignmentSubmission::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('assignment_id', $assignments->pluck('id'))
                ->whereNotNull('score')
                ->get()
                ->groupBy('user_id');

            foreach ($userIds as $userId) {
                $userSubmissions = $submissions->get($userId, collect());
                if ($userSubmissions->isEmpty()) {
                    $labStats[$userId] = null;

                    continue;
                }

                $bestByAssignment = $userSubmissions
                    ->groupBy('assignment_id')
                    ->map(fn (Collection $group) => (int) $group->max('score'));

                $maxPossible = $assignments
                    ->whereIn('id', $bestByAssignment->keys())
                    ->sum('max_score') ?: $assignments->sum('max_score') ?: 10;

                $labStats[$userId] = [
                    'score' => $bestByAssignment->sum(),
                    'max' => (int) $maxPossible,
                ];
            }
        }

        return [
            'latestProgress' => $latestProgress,
            'quizStats' => $quizStats,
            'labStats' => $labStats,
        ];
    }

    private function completionLabel(Enrollment $enrollment): string
    {
        return $enrollment->status === Enrollment::STATUS_COMPLETED
            || $enrollment->isCourseCompleted()
            || (float) $enrollment->progress_percent >= 100
            ? 'Hoàn thành'
            : 'Đang học';
    }

    public function toggleFeatured(Course $course): RedirectResponse
    {
        abort(403, 'Chỉ Quản trị viên (Admin) mới có quyền thiết lập khóa học nổi bật.');
    }

    private function uniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title) ?: 'course';
        $slug = $baseSlug;
        $counter = 2;

        while (Course::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function deleteThumbnail(Course $course): void
    {
        if ($course->versions()->whereIn('status', ['published', 'superseded'])->where('thumbnail', $course->thumbnail)->exists()) {
            return;
        }
        if ($course->thumbnail) {
            Storage::disk('public')->delete($course->thumbnail);
        }
    }

    private function hasBusinessRecords(Course $course): bool
    {
        return $course->enrollments()->exists()
            || DB::table('cart_items')->where('course_id', $course->id)->exists()
            || DB::table('order_items')->where('course_id', $course->id)->exists();
    }

    /**
     * @param  Collection<int, Course>  $courses
     * @return array<int, CourseSubmissionCheckResult>
     */
    private function buildSubmissionChecks($courses): array
    {
        $validator = app(CourseSubmissionValidator::class);
        $checks = [];

        foreach ($courses as $course) {
            $checks[$course->id] = $validator->validate($course);
        }

        return $checks;
    }

    private function statusOptions(): array
    {
        return Course::STATUS_LABELS;
    }
}
