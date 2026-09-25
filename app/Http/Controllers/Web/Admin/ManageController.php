<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminCourseReviewRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\ContentUpdate;
use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use App\Models\CourseReview;
use App\Models\CourseReviewItem;
use App\Models\CourseVersion;
use App\Models\Enrollment;
use App\Models\HomepageSetting;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ContentUpdateDiffService;
use App\Services\ContentUpdateService;
use App\Services\CourseReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ManageController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status');
        $instructorId = $request->query('instructor');
        $categoryId = $request->query('category');
        $pricing = (string) $request->query('pricing');
        $sort = (string) $request->query('sort', 'newest');

        $courses = Course::query()
            ->with([
                'instructor:id,name,email',
                'category:id,parent_id,name',
                'category.parent:id,name',
                'courseSections.lessons:id,course_id,section_id,title',
                'chapters.lessons:id,course_id,chapter_id,title',
            ])
            ->withCount([
                'courseSections as sections_count',
                'chapters as chapters_count',
                'enrollments as active_enrollments_count' => fn ($query) => $query->where('status', 'active'),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($instructorId, fn ($query) => $query->where('instructor_id', $instructorId))
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when(in_array($status, Course::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($pricing === 'free', fn ($query) => $query->whereRaw('COALESCE(discount_price, sale_price, price) <= 0'))
            ->when($pricing === 'paid', fn ($query) => $query->whereRaw('COALESCE(discount_price, sale_price, price) > 0'));

        match ($sort) {
            'oldest' => $courses->orderBy('created_at'),
            'students' => $courses->orderByDesc('active_enrollments_count')->orderByDesc('created_at'),
            default => $courses->orderByDesc('created_at'),
        };

        $courses = $courses->paginate(12)->withQueryString();

        $instructors = User::query()
            ->whereIn('id', Course::query()->select('instructor_id')->whereNotNull('instructor_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $categories = Category::query()
            ->whereIn('id', Course::query()->select('category_id')->whereNotNull('category_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $statusCounts = Course::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.courses.index', [
            'courses' => $courses,
            'instructors' => $instructors,
            'categories' => $categories,
            'statusCounts' => $statusCounts,
            'statusLabels' => $this->statusLabels(),
            'statusBadgeClasses' => $this->statusBadgeClasses(),
            'filters' => compact('search', 'status', 'instructorId', 'categoryId', 'pricing', 'sort'),
        ]);
    }

    public function pendingCourses(): View
    {
        $courses = Course::whereIn('status', [Course::STATUS_SUBMITTED, CourseStatus::PendingReview->value])
            ->with([
                'instructor:id,name,email',
                'category:id,parent_id,name',
                'category.parent:id,name',
                'courseSections.lessons:id,course_id,section_id,duration_seconds,duration',
                'chapters.lessons:id,course_id,chapter_id,duration_seconds,duration',
                'lessons:id,course_id,duration_seconds,duration',
            ])
            ->orderByDesc('submitted_at')
            ->orderBy('created_at')
            ->paginate(10);

        return view('admin.courses.pending', compact('courses'));
    }

    public function show(Course $course): View
    {
        $course->load([
            'instructor:id,name,email,avatar,bio',
            'category:id,parent_id,name,slug',
            'category.parent:id,name,slug',
            'courseSections.lessons' => fn ($query) => $query->orderBy('sort_order'),
            'chapters.lessons' => fn ($query) => $query->orderBy('sort_order'),
        ])->loadCount([
            'courseSections as sections_count',
            'chapters as chapters_count',
            'lessons',
        ]);

        $studentCount = Enrollment::query()
            ->where('course_id', $course->id)
            ->whereIn('status', [Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED])
            ->distinct()
            ->count('user_id');

        $curriculumSections = $course->courseSections->isNotEmpty()
            ? $course->courseSections
            : $course->chapters;

        $totalLessons = $curriculumSections->sum(fn ($section) => $section->lessons->count());
        $previewLessons = $curriculumSections
            ->flatMap(fn ($section) => $section->lessons)
            ->where('is_preview', true)
            ->count();

        $instructorCourseCount = Course::where('instructor_id', $course->instructor_id)->count();
        $instructorStudentCount = Enrollment::query()
            ->whereIn('status', [Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED])
            ->whereHas('course', fn ($query) => $query->where('instructor_id', $course->instructor_id))
            ->distinct('user_id')
            ->count('user_id');
        $previewVideoUrl = $course->previewVideoUrl();

        return view('admin.courses.show', [
            'course' => $course,
            'curriculumSections' => $curriculumSections,
            'totalLessons' => $totalLessons,
            'previewLessons' => $previewLessons,
            'studentCount' => $studentCount,
            'instructorCourseCount' => $instructorCourseCount,
            'instructorStudentCount' => $instructorStudentCount,
            'courseRevenue' => $this->courseRevenue($course),
            'statusLabels' => $this->statusLabels(),
            'statusBadgeClasses' => $this->statusBadgeClasses(),
            'previewVideoUrl' => $previewVideoUrl,
        ]);
    }

    public function review(Course $course): View|RedirectResponse
    {
        $course->load([
            'instructor:id,name,username,email,avatar,bio,role,instructor_status,account_status,locked_at,locked_reason',
            'instructor.instructorCertificates',
            'instructor.instructorProfile',
            'instructor.instructorApplication',
            'category:id,parent_id,name',
            'category.parent:id,name',
        ]);

        $instructorPendingCoursesCount = Course::where('instructor_id', $course->instructor_id)
            ->whereIn('status', [CourseStatus::PendingReview->value, CourseStatus::PendingUpdate->value])
            ->count();

        $instructorTotalCoursesCount = Course::where('instructor_id', $course->instructor_id)->count();

        // The Admin review is a frozen view of the submitted batch. Drafts
        // created afterwards belong to the next batch and must not leak here.
        $curriculumSections = app(ContentUpdateService::class)->mergeCurriculumWithUpdates(
            $course,
            [ContentUpdate::STATUS_PENDING],
        );

        $allLessons = $curriculumSections->flatMap(fn ($section) => $section->lessons);
        $totalLessons = $allLessons->count();
        $totalVideoDurationSeconds = $course->totalVideoDurationSeconds();
        $totalVideoDurationMinutes = $course->totalVideoDurationMinutes();

        $videoLessons = $allLessons
            ->filter(function ($lesson) {
                if ($lesson->type !== 'video') {
                    return false;
                }
                $source = $lesson->draft_update?->payload ?? $lesson;

                return filled(data_get($source, 'original_video_key'))
                    || filled(data_get($source, 'video_path'));
            })
            ->map(fn ($lesson) => [
                'id' => $lesson->draft_update ? 'update_les_'.$lesson->draft_update->id : $lesson->id,
                'title' => $lesson->title,
            ])
            ->values();

        $attachments = $allLessons
            ->flatMap(function ($lesson) {
                $files = collect();

                if (filled($lesson->document_file)) {
                    $files->push([
                        'lesson_title' => $lesson->title,
                        'name' => basename($lesson->document_file),
                        'url' => asset('storage/'.$lesson->document_file),
                        'type' => 'document',
                    ]);
                }

                foreach ($lesson->attachments ?? [] as $attachment) {
                    if (! is_array($attachment)) {
                        continue;
                    }

                    $path = $attachment['path'] ?? $attachment['file'] ?? null;
                    if (! filled($path)) {
                        continue;
                    }

                    $files->push([
                        'lesson_title' => $lesson->title,
                        'name' => $attachment['name'] ?? basename((string) $path),
                        'url' => str_starts_with((string) $path, 'http')
                            ? $path
                            : asset('storage/'.$path),
                        'type' => $attachment['type'] ?? 'file',
                    ]);
                }

                return $files;
            })
            ->values();

        $reviewUpdateDiffs = ContentUpdate::query()
            ->where('course_id', $course->id)
            ->where('status', ContentUpdate::STATUS_PENDING)
            ->orderByRaw("CASE type WHEN 'course' THEN 1 WHEN 'chapter' THEN 2 WHEN 'lesson' THEN 3 WHEN 'assignment' THEN 4 WHEN 'quiz' THEN 5 ELSE 6 END")
            ->orderBy('id')
            ->get()
            ->map(fn (ContentUpdate $update): array => [
                'update' => $update,
                'diff' => app(ContentUpdateDiffService::class)->build($update),
            ]);

        $reviewPreviewVideo = $course->preview_video;
        $pendingCourseUpdate = ContentUpdate::query()
            ->where('course_id', $course->id)
            ->where('type', ContentUpdate::TYPE_COURSE)
            ->where('action', ContentUpdate::ACTION_UPDATE)
            ->where('status', ContentUpdate::STATUS_PENDING)
            ->latest('id')
            ->first();
        if ($pendingCourseUpdate) {
            $candidate = CourseVersion::query()
                ->where('course_id', $course->id)
                ->where('content_update_id', $pendingCourseUpdate->id)
                ->first();
            $reviewPreviewVideo = $candidate?->preview_video
                ?? data_get($pendingCourseUpdate->payload, 'preview_video', $reviewPreviewVideo);
        }
        $reviewPreviewVideoUrl = $course->previewVideoUrl($reviewPreviewVideo);

        return view('admin.courses.review', [
            'course' => $course,
            'curriculumSections' => $curriculumSections,
            'totalLessons' => $totalLessons,
            'totalVideoDurationSeconds' => $totalVideoDurationSeconds,
            'totalVideoDurationMinutes' => $totalVideoDurationMinutes,
            'attachments' => $attachments,
            'videoLessons' => $videoLessons,
            'reviewUpdateDiffs' => $reviewUpdateDiffs,
            'checklistKeys' => CourseReviewItem::ADMIN_CHECKLIST_KEYS,
            'checklistLabels' => CourseReviewItem::ITEM_LABELS,
            'instructorPendingCoursesCount' => $instructorPendingCoursesCount,
            'instructorTotalCoursesCount' => $instructorTotalCoursesCount,
            'reviewPreviewVideo' => $reviewPreviewVideo,
            'reviewPreviewVideoUrl' => $reviewPreviewVideoUrl,
        ]);
    }

    public function students(Course $course): View
    {
        $course->load([
            'instructor:id,name,email',
            'category:id,parent_id,name',
            'category.parent:id,name',
            'courseSections.lessons:id,course_id,section_id,title',
            'chapters.lessons:id,course_id,chapter_id,title',
        ]);

        $enrollments = Enrollment::query()
            ->where('course_id', $course->id)
            ->with('user:id,name,email,avatar')
            ->orderByDesc('enrolled_at')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        $curriculumSections = $course->courseSections->isNotEmpty()
            ? $course->courseSections
            : $course->chapters;
        $lessonIds = $curriculumSections
            ->flatMap(fn ($section) => $section->lessons->pluck('id'))
            ->values();
        $totalLessons = $lessonIds->count();
        $completedLessonCounts = collect();

        if (Schema::hasTable('lesson_progress') && $lessonIds->isNotEmpty() && $enrollments->isNotEmpty()) {
            $completedLessonCounts = DB::table('lesson_progress')
                ->select('user_id', DB::raw('COUNT(*) as completed_count'))
                ->whereIn('lesson_id', $lessonIds)
                ->whereIn('user_id', $enrollments->getCollection()->pluck('user_id'))
                ->where('is_completed', true)
                ->groupBy('user_id')
                ->pluck('completed_count', 'user_id');
        }

        return view('admin.courses.students', [
            'course' => $course,
            'enrollments' => $enrollments,
            'totalLessons' => $totalLessons,
            'completedLessonCounts' => $completedLessonCounts,
        ]);
    }

    public function approve(Request $request, Course $course, CourseReviewService $reviewService): RedirectResponse
    {
        $pendingStatuses = [Course::STATUS_SUBMITTED, CourseStatus::PendingReview->value, CourseStatus::PendingUpdate->value, Course::STATUS_PENDING_UPDATE];

        if (! in_array($course->status, $pendingStatuses, true)) {
            return back()->with('error', 'Chỉ khóa học đang chờ duyệt mới có thể được duyệt.');
        }

        if ($course->status === Course::STATUS_SUBMITTED) {
            $course->update(['status' => CourseStatus::PendingReview->value]);
            $course->refresh();
        }

        $this->authorize('approve', $course);

        $checklist = collect(config('course.admin_review_checklist', []))
            ->mapWithKeys(fn ($label, $key) => [$key => true])
            ->all();

        $reviewService->approve($course, $request->user(), $checklist, true);

        return back()->with('success', "Đã duyệt khóa học \"{$course->title}\".");
    }

    public function reject(Request $request, Course $course, CourseReviewService $reviewService): RedirectResponse
    {
        $pendingStatuses = [Course::STATUS_SUBMITTED, CourseStatus::PendingReview->value, CourseStatus::PendingUpdate->value, Course::STATUS_PENDING_UPDATE];

        if (! in_array($course->status, $pendingStatuses, true)) {
            return back()->with('error', 'Chỉ khóa học đang chờ duyệt mới có thể bị từ chối.');
        }

        if ($course->status === Course::STATUS_SUBMITTED) {
            $course->update(['status' => CourseStatus::PendingReview->value]);
            $course->refresh();
        }

        $this->authorize('reject', $course);

        $request->merge([
            'comment' => $request->input('comment', $request->input('reject_reason', $request->input('reason'))),
        ]);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'min:'.config('course.reject_reason_min_length', 10), 'max:1000'],
        ]);

        $reviewService->reject($course, $request->user(), $validated['comment']);

        return back()->with('success', 'Đã từ chối khóa học.');
    }

    public function submitReview(AdminCourseReviewRequest $request, Course $course, CourseReviewService $reviewService): RedirectResponse
    {
        if (! in_array($course->status, [Course::STATUS_SUBMITTED, CourseStatus::PendingReview->value, CourseStatus::PendingUpdate->value, Course::STATUS_PENDING_UPDATE], true)) {
            return redirect()
                ->route('admin.course-reviews.index')
                ->with('error', 'Chỉ khóa học đang chờ duyệt mới có thể được kiểm duyệt.');
        }

        $action = $request->input('action');
        $comment = trim((string) $request->input('comment'));
        $checklist = $request->input('checklist', []);

        // Process per-lesson review notes & requirements
        $lessonNotes = $request->input('lesson_notes', []);
        foreach ($lessonNotes as $lessonId => $noteData) {
            $adminNote = trim((string) ($noteData['admin_note'] ?? ''));
            $requireReupload = ! empty($noteData['require_reupload']);
            $lessonStatus = (string) ($noteData['status'] ?? 'pass');

            $update = null;
            if (str_starts_with((string) $lessonId, 'update_les_')) {
                $updateId = str_replace('update_les_', '', $lessonId);
                $update = ContentUpdate::query()
                    ->whereKey($updateId)
                    ->where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_LESSON)
                    ->first();
            } else {
                $update = ContentUpdate::where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_LESSON)
                    ->where('entity_id', $lessonId)
                    ->whereIn('status', [ContentUpdate::STATUS_DRAFT, ContentUpdate::STATUS_PENDING, ContentUpdate::STATUS_REJECTED])
                    ->first();

                if (! $update) {
                    $lesson = Lesson::query()
                        ->whereKey($lessonId)
                        ->where('course_id', $course->id)
                        ->first();
                    if ($lesson) {
                        $update = app(ContentUpdateService::class)->recordPendingUpdate(
                            ContentUpdate::TYPE_LESSON,
                            ContentUpdate::ACTION_UPDATE,
                            $course->id,
                            $lesson->id,
                            [
                                'title' => $lesson->title,
                                'type' => $lesson->type,
                                'video_path' => $lesson->video_path,
                                'video_url' => $lesson->video_url,
                            ],
                            $request->user(),
                            ContentUpdate::STATUS_PENDING,
                        );
                    }
                }
            }

            if ($update) {
                // Reviewed updates are terminal history and cannot be
                // rewritten by the legacy review form.
                if ($update->isApproved() || $update->isRejected()) {
                    continue;
                }

                if (! $update->isPending()) {
                    continue;
                }

                $payload = $update->payload ?? [];
                $payload['admin_note'] = $adminNote;
                $payload['require_reupload'] = $requireReupload;
                $payload['review_status'] = $lessonStatus;
                $update->update(['payload' => $payload]);

                // PASS leaves the update pending for the canonical course
                // approval. A failed lesson uses the guarded rejection path.
                if ($lessonStatus === 'fail' || $lessonStatus === 'need_revision') {
                    app(ContentUpdateService::class)->rejectUpdate(
                        $update,
                        $request->user(),
                        $adminNote ?: $comment,
                    );
                }
            }
        }

        if ($action === CourseReview::ACTION_APPROVED) {
            $reviewService->approve(
                $course,
                $request->user(),
                $this->legacyChecklistToConfig($checklist),
                true,
            );
        } else {
            if (strlen($comment) < config('course.reject_reason_min_length', 10)) {
                return back()->withErrors([
                    'comment' => 'Lý do / ghi chú bắt buộc khi yêu cầu chỉnh sửa hoặc từ chối (tối thiểu '.config('course.reject_reason_min_length', 10).' ký tự).',
                ])->withInput();
            }

            $reviewService->reject($course, $request->user(), $comment, $checklist);
        }

        $actionLabels = [
            CourseReview::ACTION_APPROVED => 'Đã duyệt',
            CourseReview::ACTION_NEED_REVISION => 'Đã yêu cầu chỉnh sửa',
            CourseReview::ACTION_REJECTED => 'Đã từ chối',
        ];

        $label = $actionLabels[$action] ?? 'Đã xử lý';

        return redirect()
            ->route('admin.courses.pending')
            ->with('success', "{$label} khóa học \"{$course->title}\".");
    }

    public function saveLessonNote(Request $request, Course $course, string|int $lessonId)
    {
        $adminNote = trim((string) $request->input('admin_note'));
        $requireReupload = (bool) $request->input('require_reupload', false);
        $reviewStatus = (string) $request->input('status', 'pass');

        $update = null;
        if (str_starts_with((string) $lessonId, 'update_les_')) {
            $updateId = str_replace('update_les_', '', $lessonId);
            $update = ContentUpdate::query()
                ->whereKey($updateId)
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_LESSON)
                ->first();
        } else {
            // First check if lessonId matches a ContentUpdate primary key directly
            $update = ContentUpdate::where('course_id', $course->id)
                ->where('id', $lessonId)
                ->where('type', ContentUpdate::TYPE_LESSON)
                ->first();

            if (! $update) {
                $update = ContentUpdate::where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_LESSON)
                    ->where('entity_id', $lessonId)
                    ->whereIn('status', [ContentUpdate::STATUS_DRAFT, ContentUpdate::STATUS_PENDING, ContentUpdate::STATUS_REJECTED])
                    ->first();
            }

            if (! $update) {
                $lesson = Lesson::query()
                    ->whereKey($lessonId)
                    ->where('course_id', $course->id)
                    ->first();
                if ($lesson) {
                    $update = app(ContentUpdateService::class)->recordPendingUpdate(
                        ContentUpdate::TYPE_LESSON,
                        ContentUpdate::ACTION_UPDATE,
                        $course->id,
                        $lesson->id,
                        [
                            'title' => $lesson->title,
                            'type' => $lesson->type,
                            'video_path' => $lesson->video_path,
                            'video_url' => $lesson->video_url,
                        ],
                        $request->user(),
                        ContentUpdate::STATUS_PENDING,
                    );
                }
            }
        }

        if ($update) {
            if ($update->isApproved() || $update->isRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thay đổi đã ở trạng thái kết thúc và không thể chỉnh sửa.',
                ], 422);
            }

            if (! $update->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chỉ thay đổi đang chờ duyệt mới có thể được kiểm duyệt.',
                ], 422);
            }

            $payload = $update->payload ?? [];
            $payload['admin_note'] = $adminNote;
            $payload['require_reupload'] = $requireReupload;
            $payload['review_status'] = $reviewStatus;
            $update->payload = $payload;

            if ($reviewStatus !== 'pass') {
                $update->save();
                app(ContentUpdateService::class)->rejectUpdate(
                    $update,
                    $request->user(),
                    $adminNote
                );
            } else {
                // A pass only records review metadata. Canonical course approval
                // performs the actual pending -> approved transition atomically.
                $update->save();
            }

            if ($reviewStatus === 'pass') {
                $update->refresh();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu ghi chú bài học.',
        ]);
    }

    /**
     * @param  array<string, array{status?: string, note?: string|null}>  $checklist
     * @return array<string, bool>
     */
    private function legacyChecklistToConfig(array $checklist): array
    {
        foreach ($checklist as $item) {
            if (($item['status'] ?? CourseReviewItem::STATUS_PASS) !== CourseReviewItem::STATUS_PASS) {
                abort(422, 'Tất cả mục checklist phải đạt trước khi duyệt khóa học.');
            }
        }

        return collect(config('course.admin_review_checklist', []))
            ->mapWithKeys(fn ($label, $key) => [$key => true])
            ->all();
    }

    /**
     * Xuất bản khóa học đã được duyệt (approved → published).
     */
    public function publish(Request $request, Course $course): RedirectResponse
    {
        if ($course->status !== Course::STATUS_APPROVED) {
            return back()->with('error', 'Chỉ khóa học đã duyệt mới có thể xuất bản.');
        }

        $instructor = $course->relationLoaded('instructor') ? $course->instructor : $course->instructor()->first();
        abort_unless(
            $instructor && app(\App\Services\InstructorCourseCategoryAccess::class)->canManageCourse($instructor, $course),
            422,
            'Ngành của giảng viên chưa được duyệt.'
        );

        $course->update([
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);

        ActivityLogService::log(auth()->id(), 'publish_course', Course::class, $course->id, null, $request);

        return back()->with('success', "Đã xuất bản khóa học \"{$course->title}\".");
    }

    public function archive(Request $request, Course $course): RedirectResponse
    {
        if ($course->status !== Course::STATUS_PUBLISHED) {
            return back()->with('error', 'Chỉ khóa học đã xuất bản mới có thể ẩn/lưu trữ.');
        }

        $course->update([
            'status' => Course::STATUS_ARCHIVED,
            'is_published' => false,
        ]);

        ActivityLogService::log(auth()->id(), 'archive_course', Course::class, $course->id, null, $request);

        return back()->with('success', "Đã ẩn khóa học \"{$course->title}\".");
    }

    public function restore(Request $request, Course $course): RedirectResponse
    {
        if ($course->status !== Course::STATUS_ARCHIVED) {
            return back()->with('error', 'Chỉ khóa học đã ẩn mới có thể khôi phục.');
        }

        $course->update([
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => $course->published_at ?? now(),
        ]);

        ActivityLogService::log(auth()->id(), 'restore_course', Course::class, $course->id, null, $request);

        return back()->with('success', "Đã khôi phục khóa học \"{$course->title}\".");
    }

    public function toggleFeatured(Request $request, Course $course): RedirectResponse
    {
        $course->update([
            'is_featured' => ! $course->is_featured,
        ]);

        $message = $course->is_featured
            ? "Đã bật nổi bật khóa học \"{$course->title}\" thành công."
            : "Đã bỏ nổi bật khóa học \"{$course->title}\" thành công.";

        ActivityLogService::log(auth()->id(), 'toggle_featured_course', Course::class, $course->id, null, $request);

        return back()->with('success', $message);
    }

    public function revenue(Request $request): View
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,'.(now()->year + 1)],
        ]);

        $query = Order::where('status', 'paid');

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

        $totalGross = (float) $query->sum('total_amount');
        $totalOrders = $query->count();

        $orderIds = (clone $query)->pluck('id');

        $totalCommission = (float) DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->sum('commission_amount');
        $totalInstructorEarning = (float) DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->sum('instructor_earning');

        // Legacy orders without order_items still need to reconcile with gross revenue.
        $legacyGross = (float) (clone $query)
            ->whereDoesntHave('items')
            ->sum('total_amount');
        $defaultCommissionRate = (float) SystemSetting::get(
            'default_commission_rate',
            config('course.default_commission_rate', 20.00)
        );
        $totalCommission += $legacyGross * ($defaultCommissionRate / 100);
        $totalInstructorEarning += $legacyGross * (1 - ($defaultCommissionRate / 100));

        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', orders.created_at)"
            : "DATE_FORMAT(orders.created_at, '%Y-%m')";

        $monthlyQuery = Order::where('status', 'paid');
        if (! empty($filters['start_date'])) {
            $monthlyQuery->whereDate('created_at', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $monthlyQuery->whereDate('created_at', '<=', $filters['end_date']);
        }
        if (! empty($filters['month'])) {
            $monthlyQuery->whereMonth('created_at', $filters['month']);
        }
        if (! empty($filters['year'])) {
            $monthlyQuery->whereYear('created_at', $filters['year']);
        }

        $monthly = $monthlyQuery
            ->selectRaw("{$monthExpr} as month, SUM(total_amount) as total, COUNT(*) as count")
            ->groupBy('month')
            ->orderByDesc('month')
            ->limit(12)
            ->get();

        // Single query aggregation for monthly financials
        $monthlyFinancialsQuery = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'paid');
        if (! empty($filters['start_date'])) {
            $monthlyFinancialsQuery->whereDate('orders.created_at', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $monthlyFinancialsQuery->whereDate('orders.created_at', '<=', $filters['end_date']);
        }
        if (! empty($filters['month'])) {
            $monthlyFinancialsQuery->whereMonth('orders.created_at', $filters['month']);
        }
        if (! empty($filters['year'])) {
            $monthlyFinancialsQuery->whereYear('orders.created_at', $filters['year']);
        }
        $monthlyFinancials = $monthlyFinancialsQuery
            ->selectRaw("{$monthExpr} as month, SUM(order_items.commission_amount) as commission, SUM(order_items.instructor_earning) as instructor_earning")
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        $monthlyLegacy = (clone $monthlyQuery)
            ->whereDoesntHave('items')
            ->selectRaw("{$monthExpr} as month, SUM(total_amount) as total")
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        foreach ($monthly as $row) {
            $monthData = $monthlyFinancials->get($row->month);
            $legacyMonthGross = (float) ($monthlyLegacy->get($row->month)?->total ?? 0);
            $legacyCommission = $legacyMonthGross * ($defaultCommissionRate / 100);
            $row->commission = (float) ($monthData?->commission ?? 0) + $legacyCommission;
            $row->instructor_earning = (float) ($monthData?->instructor_earning ?? 0)
                + ($legacyMonthGross - $legacyCommission);
        }

        $courseRevenue = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('courses', 'courses.id', '=', 'order_items.course_id')
            ->leftJoin('users as instructors', 'instructors.id', '=', 'courses.instructor_id')
            ->whereIn('orders.id', $orderIds)
            ->select(
                'courses.id as course_id',
                'courses.title as course_title',
                'instructors.name as instructor_name',
                DB::raw('COUNT(DISTINCT order_items.order_id) as sales_count'),
                DB::raw('SUM(order_items.commission_amount + order_items.instructor_earning) as gross_amount'),
                DB::raw('SUM(order_items.commission_amount) as commission_amount'),
                DB::raw('SUM(order_items.instructor_earning) as instructor_earning')
            )
            ->groupBy('courses.id', 'courses.title', 'instructors.name')
            ->orderByDesc('gross_amount')
            ->get();

        $filters = array_merge([
            'start_date' => null,
            'end_date' => null,
            'month' => null,
            'year' => null,
        ], $filters);

        return view('admin.revenue', compact('totalGross', 'totalCommission', 'totalInstructorEarning', 'totalOrders', 'monthly', 'courseRevenue', 'filters'));
    }

    public function activityLogs(): View
    {
        $logs = ActivityLog::with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('admin.activity-logs', compact('logs'));
    }

    public function homepage(): View
    {
        $settings = HomepageSetting::pluck('value', 'key');

        return view('admin.homepage', compact('settings'));
    }

    public function updateHomepage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'banner_title' => 'required|string|max:255',
            'banner_subtitle' => 'required|string|max:500',
            'announcement' => 'nullable|string|max:500',
        ]);

        HomepageSetting::updateOrCreate(
            ['key' => 'banner'],
            ['value' => ['title' => $validated['banner_title'], 'subtitle' => $validated['banner_subtitle']]]
        );

        if ($validated['announcement']) {
            HomepageSetting::updateOrCreate(['key' => 'announcement'], ['value' => $validated['announcement']]);
        }

        return back()->with('success', 'Cập nhật trang chủ thành công!');
    }

    private function statusLabels(): array
    {
        return Course::STATUS_LABELS;
    }

    private function statusBadgeClasses(): array
    {
        return [
            Course::STATUS_DRAFT => 'bg-slate-50 text-slate-700 ring-1 ring-slate-200',
            Course::STATUS_SUBMITTED => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
            CourseStatus::PendingReview->value => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
            Course::STATUS_NEED_REVISION => 'bg-orange-50 text-orange-700 ring-1 ring-orange-200',
            Course::STATUS_APPROVED => 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',
            Course::STATUS_PUBLISHED => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
            Course::STATUS_REJECTED => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
            Course::STATUS_ARCHIVED => 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-200',
        ];
    }

    private function courseRevenue(Course $course): float
    {
        return (float) OrderItem::query()
            ->where('course_id', $course->id)
            ->whereHas('order', fn (Builder $q) => $q->where('status', 'paid'))
            ->sum('price');
    }

    public function toggleHideReply(Review $review): RedirectResponse
    {
        abort_unless($review->isReply(), 404);

        $review->update([
            'is_hidden' => ! $review->is_hidden,
        ]);

        $statusMsg = $review->is_hidden ? 'Đã ẩn phản hồi.' : 'Đã hiển thị phản hồi.';

        return back()->with('success', $statusMsg);
    }
}
