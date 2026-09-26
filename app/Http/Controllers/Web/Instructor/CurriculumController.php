<?php

namespace App\Http\Controllers\Web\Instructor;

use App\Exceptions\HistoricalQuizDeletionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\StoreChapterRequest;
use App\Http\Requests\Instructor\StoreLessonRequest;
use App\Jobs\ConvertContentUpdateVideoToHLS;
use App\Jobs\ConvertVideoToHLS;
use App\Models\ContentUpdate;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Services\ContentUpdateService;
use App\Services\ContentVersionService;
use App\Services\CurriculumLessonService;
use App\Services\HistoricalQuizDeletionGuard;
use App\Services\InstructorCourseCategoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CurriculumController extends Controller
{
    public function __construct(private readonly InstructorCourseCategoryAccess $courseCategoryAccess) {}

    public function index(Course $course): View
    {
        $this->authorizeCourse($course);

        $contentUpdates = app(ContentUpdateService::class);
        $curriculumSections = $contentUpdates->mergeCurriculumWithUpdates($course);
        $reviewState = $contentUpdates->instructorReviewState($course, auth()->user());

        return view('instructor.courses.curriculum', [
            'course' => $course,
            'submissionCheck' => $course->submissionCheck(),
            'curriculumSections' => $curriculumSections,
            'reviewState' => $reviewState,
            'lessonTypes' => $this->lessonTypes(),
            'lessonStatuses' => $this->lessonStatuses(),
        ]);
    }

    public function storeSection(StoreChapterRequest $request, Course $course): RedirectResponse
    {
        $this->authorizeCourse($course);

        $validated = $request->validated();

        if ($course->isPublished()) {
            app(ContentUpdateService::class)->recordPendingUpdate(
                ContentUpdate::TYPE_CHAPTER,
                ContentUpdate::ACTION_CREATE,
                $course->id,
                null,
                array_merge($validated, ['sort_order' => $course->courseSections()->count()]),
                $request->user()
            );

            return back()->with('success', 'Đã lưu bản cập nhật chương học mới. Chương học sẽ xuất hiện sau khi Admin duyệt.');
        }

        CourseSection::create([
            ...$validated,
            'course_id' => $course->id,
            'sort_order' => $course->courseSections()->count(),
        ]);

        return back()->with('success', 'Đã thêm chương học.');
    }

    public function updateSection(StoreChapterRequest $request, Course $course, $section): RedirectResponse
    {
        abort_unless(! $section instanceof CourseSection || (int) $section->course_id === (int) $course->id, 404);
        $sectionModel = $section instanceof CourseSection
            ? $section
            : CourseSection::query()->whereKey($section)->where('course_id', $course->id)->first();
        $sectionId = $sectionModel ? $sectionModel->id : (int) (is_object($section) ? ($section->id ?? 0) : $section);

        if ($sectionModel) {
            $this->authorizeSection($course, $sectionModel);
        } else {
            $this->authorizeCourse($course);
        }

        $syntheticSectionUpdate = ! $sectionModel
            ? ContentUpdate::query()
                ->whereKey($sectionId)
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_CHAPTER)
                ->where('action', ContentUpdate::ACTION_CREATE)
                ->whereIn('status', [ContentUpdate::STATUS_DRAFT, ContentUpdate::STATUS_PENDING])
                ->first()
            : null;
        if (! $sectionModel && ! $syntheticSectionUpdate) {
            return back()->withErrors(['section' => 'Chương học không tồn tại trong khóa học này.']);
        }

        $validated = $request->validated();

        if ($course->isPublished()) {
            $pendingContentUpdate = ContentUpdate::query()
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_CHAPTER)
                ->where('status', ContentUpdate::STATUS_PENDING)
                ->when(
                    $sectionModel,
                    fn ($query) => $query->where('entity_id', $sectionId),
                    fn ($query) => $query->whereKey($sectionId)->where('action', ContentUpdate::ACTION_CREATE),
                )
                ->exists();

            if ($pendingContentUpdate) {
                return back()->withErrors(['section' => 'Chương học đang chờ Admin duyệt và không thể chỉnh sửa.']);
            }

            $contentUpdate = ContentUpdate::query()
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_CHAPTER)
                ->where('status', ContentUpdate::STATUS_DRAFT)
                ->when(
                    $sectionModel,
                    fn ($query) => $query->where('entity_id', $sectionId)->where('action', ContentUpdate::ACTION_UPDATE),
                    fn ($query) => $query->whereKey($sectionId)->where('action', ContentUpdate::ACTION_CREATE),
                )
                ->latest('id')
                ->first();

            if ($contentUpdate) {
                $changes = $validated;
                $legacyChapterId = $this->legacyChapterIdForSection($course, $sectionModel);
                if ($legacyChapterId) {
                    $changes['legacy_chapter_id'] = $legacyChapterId;
                }
                app(ContentUpdateService::class)->updateDraft($contentUpdate, $changes);

                return back()->with('success', 'Đã cập nhật bản nháp chương học.');
            }

            $payload = $validated;
            $legacyChapterId = $this->legacyChapterIdForSection($course, $sectionModel);
            if ($legacyChapterId) {
                $payload['legacy_chapter_id'] = $legacyChapterId;
            }

            app(ContentUpdateService::class)->recordPendingUpdate(
                ContentUpdate::TYPE_CHAPTER,
                ContentUpdate::ACTION_UPDATE,
                $course->id,
                $sectionId,
                $payload,
                $request->user()
            );

            return back()->with('success', 'Đã lưu bản cập nhật chương học. Thay đổi sẽ áp dụng sau khi Admin duyệt.');
        }

        if ($sectionModel) {
            $sectionModel->update($validated);
        }

        return back()->with('success', 'Đã cập nhật chương học.');
    }

    public function destroySection(Course $course, $section): RedirectResponse
    {
        abort_unless(! $section instanceof CourseSection || (int) $section->course_id === (int) $course->id, 404);
        $sectionModel = $section instanceof CourseSection
            ? $section
            : CourseSection::query()->whereKey($section)->where('course_id', $course->id)->first();
        $sectionId = $sectionModel ? $sectionModel->id : (int) (is_object($section) ? ($section->id ?? 0) : $section);

        if ($sectionModel) {
            $this->authorizeSection($course, $sectionModel);
        } else {
            $this->authorizeCourse($course);
        }

        $syntheticSectionUpdate = ! $sectionModel
            ? ContentUpdate::query()
                ->whereKey($sectionId)
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_CHAPTER)
                ->where('action', ContentUpdate::ACTION_CREATE)
                ->whereIn('status', [ContentUpdate::STATUS_DRAFT, ContentUpdate::STATUS_PENDING])
                ->first()
            : null;
        if (! $sectionModel && ! $syntheticSectionUpdate) {
            return back()->withErrors(['section' => 'Chương học không tồn tại trong khóa học này.']);
        }

        if ($course->isPublished()) {
            $pendingContentUpdate = $sectionModel
                ? ContentUpdate::query()
                    ->where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_CHAPTER)
                    ->where('entity_id', $sectionId)
                    ->where('status', ContentUpdate::STATUS_PENDING)
                    ->exists()
                : ContentUpdate::query()
                    ->whereKey($sectionId)
                    ->where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_CHAPTER)
                    ->where('action', ContentUpdate::ACTION_CREATE)
                    ->where('status', ContentUpdate::STATUS_PENDING)
                    ->exists();

            if ($pendingContentUpdate) {
                return back()->withErrors(['section' => 'Chương học đang chờ Admin duyệt và không thể chỉnh sửa hoặc xóa.']);
            }

            // Only a draft create record represents an uncommitted section.
            // Never delete an arbitrary ContentUpdate by primary-key collision.
            $contentUpdate = ! $sectionModel
                ? ContentUpdate::query()
                    ->whereKey($sectionId)
                    ->where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_CHAPTER)
                    ->where('action', ContentUpdate::ACTION_CREATE)
                    ->where('status', ContentUpdate::STATUS_DRAFT)
                    ->first()
                : null;

            if ($contentUpdate) {
                // Kiểm tra nếu có bài học nháp thuộc chương này
                $hasDraftLessons = ContentUpdate::where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_LESSON)
                    ->whereIn('status', [ContentUpdate::STATUS_DRAFT, ContentUpdate::STATUS_PENDING])
                    ->whereJsonContains('payload->section_id', $sectionId)
                    ->exists();

                if ($hasDraftLessons) {
                    return back()->with('error', 'Chương học này đang có bài học. Vui lòng xóa hết bài học trong chương trước khi xóa chương.');
                }

                app(ContentUpdateService::class)->deleteDraft($contentUpdate);

                return back()->with('success', 'Đã xóa bản nháp chương học.');
            }

            if ($sectionModel && $sectionModel->lessons()->exists()) {
                return back()->with('error', 'Chương học này đang có bài học. Vui lòng xóa hết bài học trong chương trước khi xóa chương.');
            }

            $payload = [];
            $legacyChapterId = $this->legacyChapterIdForSection($course, $sectionModel);
            if ($legacyChapterId) {
                $payload['legacy_chapter_id'] = $legacyChapterId;
            }

            app(ContentUpdateService::class)->recordPendingUpdate(
                ContentUpdate::TYPE_CHAPTER,
                ContentUpdate::ACTION_DELETE,
                $course->id,
                $sectionId,
                $payload,
                auth()->user()
            );

            return back()->with('success', 'Đã gửi yêu cầu xóa chương học. Yêu cầu sẽ áp dụng sau khi Admin duyệt.');
        }

        if ($sectionModel) {
            if ($sectionModel->lessons()->exists()) {
                return back()->with('error', 'Chương học này đang có bài học. Vui lòng xóa hết bài học trong chương trước khi xóa chương.');
            }

            try {
                $lessons = $sectionModel->lessons()->get();
                app(HistoricalQuizDeletionGuard::class)->assertSectionCanBeHardDeleted($sectionModel);

                DB::transaction(function () use ($sectionModel, $lessons): void {
                    $lessons->each(fn (Lesson $lesson) => $lesson->delete());
                    $sectionModel->delete();
                });
            } catch (HistoricalQuizDeletionException $exception) {
                return back()->withErrors(['section' => $exception->getMessage()]);
            }

            $lessons->each(fn (Lesson $lesson) => $this->deleteLessonFiles($lesson));
        }

        return back()->with('success', 'Đã xóa chương học.');
    }

    public function storeLesson(
        StoreLessonRequest $request,
        Course $course,
        $section,
        CurriculumLessonService $lessonService
    ): RedirectResponse|JsonResponse {
        abort_unless(! $section instanceof CourseSection || (int) $section->course_id === (int) $course->id, 404);
        $sectionModel = $section instanceof CourseSection
            ? $section
            : CourseSection::query()->whereKey($section)->where('course_id', $course->id)->first();
        $sectionId = $sectionModel ? $sectionModel->id : (int) (is_object($section) ? ($section->id ?? 0) : $section);

        if ($sectionModel) {
            $this->authorizeSection($course, $sectionModel);
        } else {
            $this->authorizeCourse($course);
        }

        $syntheticSectionUpdate = ! $sectionModel
            ? ContentUpdate::query()
                ->whereKey($sectionId)
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_CHAPTER)
                ->where('action', ContentUpdate::ACTION_CREATE)
                ->whereIn('status', [ContentUpdate::STATUS_DRAFT, ContentUpdate::STATUS_PENDING])
                ->first()
            : null;
        if (! $sectionModel && ! $syntheticSectionUpdate) {
            $message = 'Chương học không tồn tại trong khóa học này.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->withErrors(['section' => $message]);
        }
        if ($syntheticSectionUpdate?->isPending()) {
            $message = 'Chương học đang chờ Admin duyệt và không thể chỉnh sửa.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->withErrors(['section' => $message]);
        }

        abort_unless($lessonService->canCreateForManual($course), 403);

        $result = $lessonService->createForManual(
            $course,
            $sectionModel ?? $sectionId,
            $request->validated(),
            $request->user(),
        );

        if ($result instanceof ContentUpdate) {
            Log::info('[UPLOAD TRACE] RETURN RESPONSE (ContentUpdate)');

            if ($request->wantsJson() || $request->ajax()) {
                $lesson = $result->entity_id
                    ? Lesson::query()->where('course_id', $course->id)->find($result->entity_id)
                    : null;
                if (! $lesson) {
                    $payload = $result->payload ?? [];
                    $lesson = new Lesson([
                        'course_id' => $course->id,
                        'section_id' => $sectionId,
                        'title' => $payload['title'] ?? 'Bài học mới',
                        'type' => $payload['type'] ?? Lesson::TYPE_VIDEO,
                        'sort_order' => $payload['sort_order'] ?? 0,
                        'status' => Lesson::STATUS_DRAFT,
                        'upload_status' => $payload['upload_status'] ?? 'pending',
                        'processing_status' => $payload['processing_status'] ?? 'pending',
                    ]);
                    $lesson->id = $result->id;
                }
                $lesson->draft_update = $result;
                $lesson->update_status = $result->status;
                $lesson->is_draft_create = true;
                $lessonHtml = view('instructor.courses.partials.lesson-item', [
                    'course' => $course,
                    'section' => $sectionModel,
                    'lesson' => $lesson,
                    'lessonTypes' => $this->lessonTypes(),
                    'lessonStatuses' => $this->lessonStatuses(),
                ])->render();

                return response()->json([
                    'success' => true,
                    'lesson_id' => $lesson->id,
                    'content_update_id' => $result->id,
                    'title' => $lesson->title,
                    'lesson' => [
                        'id' => $lesson->id,
                        'section_id' => $lesson->section_id,
                        'title' => $lesson->title,
                        'type' => $lesson->type,
                        'sort_order' => $lesson->sort_order,
                        'status' => $lesson->status,
                        'upload_status' => $lesson->upload_status,
                        'processing_status' => $lesson->processing_status,
                    ],
                    'html' => $lessonHtml,
                    'message' => 'Đã lưu bản nháp bài học mới.',
                    ...$this->reviewStateResponseData($course, $request->user()),
                ]);
            }

            return back()->with('success', 'Đã lưu bản nháp bài học mới. Video đang được xử lý HLS ngầm.');
        }

        $lesson = $result;

        if ($lesson->type === Lesson::TYPE_QUIZ) {
            Log::info('[UPLOAD TRACE] RETURN RESPONSE (Quiz)');

            return redirect()
                ->route('instructor.courses.lessons.quiz.show', [$course, $lesson])
                ->with('success', 'Đã tạo bài quiz. Bạn có thể thêm câu hỏi ngay bên dưới.');
        }

        Log::info('[UPLOAD TRACE] RETURN RESPONSE (Success)');

        if ($request->wantsJson() || $request->ajax()) {
            $lessonHtml = view('instructor.courses.partials.lesson-item', [
                'course' => $course,
                'section' => $sectionModel,
                'lesson' => $lesson,
                'lessonTypes' => $this->lessonTypes(),
                'lessonStatuses' => $this->lessonStatuses(),
            ])->render();

            return response()->json([
                'success' => true,
                'lesson_id' => $lesson->id,
                'lesson' => [
                    'id' => $lesson->id,
                    'section_id' => $sectionId,
                    'title' => $lesson->title,
                    'type' => $lesson->type,
                    'sort_order' => $lesson->sort_order,
                    'duration' => (int) ($lesson->duration ?? $lesson->duration_seconds ?? 0),
                    'duration_formatted' => $this->formatDuration((int) ($lesson->duration ?? $lesson->duration_seconds ?? 0)),
                    'is_preview' => (bool) $lesson->is_preview,
                    'status' => $lesson->status,
                    'original_video_key' => $lesson->original_video_key,
                    'upload_status' => $lesson->upload_status,
                    'processing_status' => $lesson->processing_status,
                    'content' => $lesson->content,
                    'destroy_url' => route('instructor.courses.lessons.destroy', [$course, $lesson->id]),
                ],
                'html' => $lessonHtml,
                'title' => $lesson->title,
                'message' => 'Đã thêm bài học.',
                ...$this->reviewStateResponseData($course, $request->user()),
            ]);
        }

        return back()->with('success', 'Đã thêm bài học. Video đang được xử lý ngầm, vui lòng đợi trong giây lát.');
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'Chưa đặt';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $minutes > 0 ? $minutes.' phút'.($remaining ? ' '.$remaining.' giây' : '') : $remaining.' giây';
    }

    public function updateLesson(StoreLessonRequest $request, Course $course, Lesson $lesson): RedirectResponse|JsonResponse
    {
        $this->authorizeLesson($course, $lesson);

        if ($course->isPublished() && ContentUpdate::query()
            ->where('course_id', $course->id)
            ->where('type', ContentUpdate::TYPE_LESSON)
            ->where('entity_id', $lesson->id)
            ->where('status', ContentUpdate::STATUS_PENDING)
            ->exists()) {
            $message = 'Bài học đang có thay đổi chờ Admin duyệt và không thể chỉnh sửa.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->withErrors(['lesson' => $message]);
        }

        $validated = $request->validated();
        $contentUpdate = null;
        if ($course->isPublished()) {
            // Establish the canonical ContentUpdate + LessonVersion before any
            // new media is staged. The live published lesson remains untouched.
            $contentUpdate = app(ContentUpdateService::class)
                ->ensureLessonUpdateDraft($course, $lesson, $request->user());
        }
        $lessonData = $this->lessonData($validated);
        // Published edits stage new files; they must not delete the live V1
        // document/video before the ContentUpdate is approved.
        $liveLessonForStorage = $course->isPublished() ? null : $lesson;
        $lessonData = $this->storeLessonDocument($request, $lessonData, $liveLessonForStorage);

        Log::info('[UPLOAD TRACE] START STORE FILE (Update)');
        $lessonData = $this->storeLessonVideo($request, $lessonData, $liveLessonForStorage);
        Log::info('[UPLOAD TRACE] STORE FILE DONE (Update)', ['video_path' => $lessonData['video_path'] ?? null, 'original_video_key' => $lessonData['original_video_key'] ?? null]);

        if ($course->isPublished()) {
            $payload = array_merge($lessonData, array_intersect_key($validated, array_flip([
                'assignment_due_days',
                'assignment_max_score',
                'assignment_passing_score',
            ])), [
                'duration_seconds' => $lessonData['duration'] ?? 0,
                'is_preview' => $request->boolean('is_preview'),
                'sort_order' => $lessonData['sort_order'] ?? $lesson->sort_order,
                'status' => $lessonData['status'] ?? Lesson::STATUS_DRAFT,
            ]);

            Log::info('[UPLOAD TRACE] SAVE DATABASE (ContentUpdate Draft)');
            $contentUpdate = app(ContentUpdateService::class)->updateDraft($contentUpdate, $payload);
            $candidate = app(ContentVersionService::class)->prepareDraftCandidate($contentUpdate, $request->user());

            if (($lessonData['type'] ?? null) === Lesson::TYPE_VIDEO) {
                $hasReadyS3Upload = filled($payload['original_video_key'] ?? null)
                    && ($payload['upload_status'] ?? null) === 'uploaded'
                    && blank($payload['hls_manifest_key'] ?? null);

                if ($request->hasFile('video_file') || $hasReadyS3Upload) {
                    Log::info('[UPLOAD TRACE] DISPATCH HLS JOB (ContentUpdate Update)', [
                        'content_update_id' => $contentUpdate->id,
                        'source' => $request->hasFile('video_file') ? 'local' : 's3',
                    ]);
                    ConvertContentUpdateVideoToHLS::dispatch(
                        $contentUpdate,
                        data_get($contentUpdate->payload, 'original_video_key'),
                        data_get($contentUpdate->payload, 'video_path'),
                    );
                }
            }

            Log::info('[UPLOAD TRACE] RETURN RESPONSE (Update ContentUpdate)');

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'content_update_id' => $contentUpdate->id,
                    'version_number' => $candidate?->version_number,
                    'lesson_id' => $lesson->id,
                    'title' => $payload['title'] ?? $lesson->title,
                    'message' => 'Đã lưu bản cập nhật nội dung bài học.',
                    ...$this->reviewStateResponseData($course, $request->user()),
                ]);
            }

            return back()->with('success', 'Đã lưu bản cập nhật nội dung bài học. Video đang được xử lý HLS ngầm.');
        }

        Log::info('[UPLOAD TRACE] SAVE DATABASE (Update Lesson)');
        $lesson->update([
            ...$lessonData,
            'duration_seconds' => $lessonData['duration'] ?? 0,
            'is_preview' => $request->boolean('is_preview'),
            'sort_order' => $lessonData['sort_order'] ?? $lesson->sort_order,
            'status' => $lessonData['status'] ?? Lesson::STATUS_DRAFT,
        ]);

        if ($lesson->type === Lesson::TYPE_VIDEO) {
            if ($request->hasFile('video_file')) {
                Log::info('[UPLOAD TRACE] DISPATCH HLS JOB (Update Local video_file)', ['lesson_id' => $lesson->id]);
                ConvertVideoToHLS::dispatch($lesson);
            } elseif ($lesson->original_video_key && $lesson->upload_status === 'uploaded' && ! $lesson->isHlsReady()) {
                if (Storage::disk('s3')->exists($lesson->original_video_key)) {
                    Log::info('[UPLOAD TRACE] DISPATCH HLS JOB (Update S3 video ready)', ['lesson_id' => $lesson->id]);
                    ConvertVideoToHLS::dispatch($lesson);
                }
            }
        }

        $lesson->refresh();
        app(CurriculumLessonService::class)->syncAssignment($lesson, $validated);

        if ($lesson->type === Lesson::TYPE_QUIZ) {
            Log::info('[UPLOAD TRACE] RETURN RESPONSE (Update Quiz)');

            return redirect()
                ->route('instructor.courses.lessons.quiz.show', [$course, $lesson])
                ->with('success', 'Đã cập nhật bài quiz. Bạn có thể quản lý câu hỏi tại đây.');
        }

        if ($request->wantsJson() || $request->ajax()) {
            $lessonHtml = view('instructor.courses.partials.lesson-item', [
                'course' => $course,
                'section' => $lesson->section,
                'lesson' => $lesson,
                'lessonTypes' => $this->lessonTypes(),
                'lessonStatuses' => $this->lessonStatuses(),
            ])->render();

            return response()->json([
                'success' => true,
                'lesson_id' => $lesson->id,
                'lesson' => [
                    'id' => $lesson->id,
                    'section_id' => $lesson->section_id,
                    'title' => $lesson->title,
                    'type' => $lesson->type,
                    'sort_order' => $lesson->sort_order,
                    'duration' => (int) ($lesson->duration ?? $lesson->duration_seconds ?? 0),
                    'duration_formatted' => $this->formatDuration((int) ($lesson->duration ?? $lesson->duration_seconds ?? 0)),
                    'is_preview' => (bool) $lesson->is_preview,
                    'status' => $lesson->status,
                    'original_video_key' => $lesson->original_video_key,
                    'upload_status' => $lesson->upload_status,
                    'processing_status' => $lesson->processing_status,
                    'content' => $lesson->content,
                    'destroy_url' => route('instructor.courses.lessons.destroy', [$course, $lesson->id]),
                ],
                'html' => $lessonHtml,
                'title' => $lesson->title,
                'message' => 'Đã cập nhật bài học.',
                ...$this->reviewStateResponseData($course, $request->user()),
            ]);
        }

        return back()->with('success', 'Đã cập nhật bài học. Nếu có video mới, video đang được xử lý ngầm.');
    }

    public function destroyLesson(Course $course, Lesson $lesson): RedirectResponse
    {
        $this->authorizeLesson($course, $lesson);

        if ($course->isPublished()) {
            $pendingUpdate = ContentUpdate::query()
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_LESSON)
                ->where('entity_id', $lesson->id)
                ->where('status', ContentUpdate::STATUS_PENDING)
                ->exists();

            if ($pendingUpdate) {
                return back()->withErrors(['lesson' => 'Bài học đang có thay đổi chờ Admin duyệt và không thể chỉnh sửa hoặc xóa.']);
            }

            app(ContentUpdateService::class)->recordPendingUpdate(
                ContentUpdate::TYPE_LESSON,
                ContentUpdate::ACTION_DELETE,
                $course->id,
                $lesson->id,
                [],
                auth()->user()
            );

            return back()->with('success', 'Đã gửi yêu cầu xóa bài học. Yêu cầu sẽ áp dụng sau khi Admin duyệt.');
        }

        try {
            app(HistoricalQuizDeletionGuard::class)->assertLessonCanBeHardDeleted($lesson);
        } catch (HistoricalQuizDeletionException $exception) {
            return back()->withErrors(['lesson' => $exception->getMessage()]);
        }

        $this->deleteLessonFiles($lesson);
        $lesson->delete();

        return back()->with('success', 'Đã xóa bài học.');
    }

    public function updateContentUpdate(Request $request, Course $course, ContentUpdate $contentUpdate): RedirectResponse|JsonResponse
    {
        $this->authorizeCourse($course);
        abort_unless((int) $contentUpdate->course_id === (int) $course->id, 404);

        $changes = $request->except(['_token', '_method']);

        // Forms submit the canonical duration field, while lesson payloads retain
        // duration_seconds for compatibility with persisted lessons/imports.
        if ($request->exists('duration')) {
            $changes['duration_seconds'] = (int) $request->input('duration', 0);
        }

        app(ContentUpdateService::class)->updateDraft(
            $contentUpdate,
            $changes,
        );

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Đã lưu bản nháp.',
                ...$this->reviewStateResponseData($course, $request->user()),
            ]);
        }

        return back()->with('success', 'Đã lưu bản nháp.');
    }

    public function destroyContentUpdate(Course $course, ContentUpdate $contentUpdate): RedirectResponse
    {
        $this->authorizeCourse($course);
        abort_unless((int) $contentUpdate->course_id === (int) $course->id, 404);

        $draftLesson = $contentUpdate->type === ContentUpdate::TYPE_LESSON
            && $contentUpdate->action === ContentUpdate::ACTION_CREATE
            && $contentUpdate->entity_id
            ? Lesson::query()
                ->where('course_id', $course->id)
                ->where('status', Lesson::STATUS_DRAFT)
                ->find($contentUpdate->entity_id)
            : null;
        $payload = app(ContentUpdateService::class)->deleteDraft($contentUpdate);
        if ($draftLesson) {
            $this->deleteLessonFiles($draftLesson);
            $draftLesson->delete();
        }
        if (! empty($payload['video_path'])) {
            Storage::disk('local')->delete($payload['video_path']);
        }
        if (! empty($payload['document_file'])) {
            Storage::disk('public')->delete($payload['document_file']);
        }

        return back()->with('success', 'Đã xóa bản cập nhật.');
    }

    public function reviseRejectedContentUpdate(Course $course, ContentUpdate $contentUpdate): RedirectResponse
    {
        $this->authorizeCourse($course);
        abort_unless((int) $contentUpdate->course_id === (int) $course->id, 404);

        try {
            $revision = app(ContentUpdateService::class)->createRevisionFromRejected($contentUpdate, auth()->user());
        } catch (ValidationException $exception) {
            return back()->withErrors(['content_update' => $exception->validator->errors()->first()]);
        }

        return back()->with('success', "Đã tạo bản chỉnh sửa mới #{$revision->id}. Bạn có thể cập nhật bản nháp trước khi gửi duyệt.");
    }

    private function authorizeCourse(Course $course): void
    {
        abort_unless(
            $this->courseCategoryAccess->canManageCourse(auth()->user(), $course),
            403,
            'Bạn không có quyền chỉnh sửa nội dung khóa học này.'
        );
    }

    /** @return array<string, mixed> */
    private function reviewStateResponseData(Course $course, User $instructor): array
    {
        $course->refresh();
        $state = app(ContentUpdateService::class)->instructorReviewState($course, $instructor);

        return [
            'reviewState' => collect($state)->except('updates', 'activeUpdates', 'actionableRejectedUpdates')->all(),
            'draftCount' => $state['draftCount'],
            'pendingCount' => $state['pendingCount'],
            'canSubmit' => $state['canSubmitCourse'],
            'blockedReason' => $state['submissionBlockedReason'],
            'publishedVersionLabel' => $state['publishedVersionLabel'],
            'draftVersionLabels' => $state['draftVersionLabels'],
            'reviewStateHtml' => view('instructor.courses.partials.curriculum-review-state', [
                'course' => $course,
                'reviewState' => $state,
            ])->render(),
        ];
    }

    private function authorizeSection(Course $course, CourseSection $section): void
    {
        $this->authorizeCourse($course);
        abort_unless((int) $section->course_id === (int) $course->id, 403);
    }

    private function authorizeLesson(Course $course, Lesson $lesson): void
    {
        $this->authorizeCourse($course);
        abort_unless($lesson->belongsToCourse($course), 404);
    }

    private function legacyChapterIdForSection(Course $course, ?CourseSection $section): ?int
    {
        if (! $section) {
            return null;
        }

        $chapterId = Lesson::query()
            ->where('course_id', $course->id)
            ->where('section_id', $section->id)
            ->whereNotNull('chapter_id')
            ->value('chapter_id');

        if ($chapterId) {
            return (int) $chapterId;
        }

        $chapterId = $course->chapters()
            ->where('title', $section->title)
            ->where('sort_order', $section->sort_order)
            ->value('id');

        return $chapterId ? (int) $chapterId : null;
    }

    private function deleteLessonDocument(Lesson $lesson): void
    {
        if ($lesson->versions()->whereIn('status', ['published', 'superseded'])->exists()) {
            return; // Historical release assets must remain available.
        }
        if ($lesson->document_file) {
            Storage::disk('public')->delete($lesson->document_file);
        }
    }

    private function lessonData(array $validated): array
    {
        if (($validated['type'] ?? null) !== Lesson::TYPE_VIDEO) {
            unset(
                $validated['video_url'],
                $validated['video_path'],
                $validated['original_video_key'],
                $validated['hls_manifest_key'],
                $validated['video_original_name'],
                $validated['video_mime'],
                $validated['video_size']
            );
        } else {
            $validated['video_size'] = (int) ($validated['video_size'] ?? 0);
        }

        if (! in_array($validated['type'] ?? null, [Lesson::TYPE_VIDEO, Lesson::TYPE_DOCUMENT, Lesson::TYPE_ASSIGNMENT], true)) {
            unset($validated['content']);
        }

        return $validated;
    }

    private function storeLessonDocument(Request $request, array $validated, ?Lesson $lesson = null): array
    {
        if (! in_array($validated['type'] ?? null, [Lesson::TYPE_DOCUMENT, Lesson::TYPE_ASSIGNMENT], true) || ! $request->hasFile('document_file')) {
            return $validated;
        }

        $path = $request->file('document_file')->store('lesson-documents', 'public');

        if ($lesson) {
            $this->deleteLessonDocument($lesson);
        }

        return [
            ...$validated,
            'document_file' => $path,
        ];
    }

    private function storeLessonVideo(Request $request, array $validated, ?Lesson $lesson = null): array
    {
        unset($validated['video_file'], $validated['s3_key']);

        if (($validated['type'] ?? null) !== Lesson::TYPE_VIDEO) {
            return $validated;
        }

        // Ưu tiên S3 Multipart Direct Upload
        if ($request->filled('s3_key')) {
            $s3Key = (string) $request->input('s3_key');
            $originalName = (string) ($request->input('video_original_name') ?: basename($s3Key));
            $mime = (string) ($request->input('video_mime') ?: 'video/mp4');
            $size = $request->input('video_size') ? (int) $request->input('video_size') : 0;
            $isSameUploadedKey = $lesson && $lesson->original_video_key === $s3Key && $lesson->upload_status === 'uploaded';
            $s3Exists = ! $isSameUploadedKey && Storage::disk('s3')->exists($s3Key);

            $result = [
                ...$validated,
                'original_video_key' => $s3Key,
                'video_original_name' => $originalName,
                'video_mime' => $mime,
                'video_size' => $size,
                'upload_status' => ($isSameUploadedKey || $s3Exists) ? 'uploaded' : 'pending',
                'processing_status' => $isSameUploadedKey ? ($lesson->processing_status ?? 'pending') : 'pending',
            ];

            // Nếu thay đổi video mới, reset HLS cũ để tránh nhầm trạng thái đã xử lý
            if (! $isSameUploadedKey) {
                $result['hls_manifest_key'] = null;
                $result['video_path'] = null;
                $result['hls_playlist'] = null;
                $result['hls_path'] = null;
                $result['processing_status'] = 'pending';
            }

            return $result;
        }

        if (! $request->hasFile('video_file')) {
            return $validated;
        }

        $file = $request->file('video_file');
        // Store the original MP4 file in local disk so it's private
        $path = $file->store('lesson-videos-mp4', 'local');

        if ($lesson) {
            $this->deleteLessonVideo($lesson);
        }

        return [
            ...$validated,
            'video_path' => $path,
            'hls_manifest_key' => null,
            'hls_playlist' => null,
            'hls_path' => null,
            'video_original_name' => $file->getClientOriginalName(),
            'video_mime' => $file->getClientMimeType(),
            'video_size' => $file->getSize(),
            'upload_status' => 'uploaded',
            'processing_status' => 'pending',
        ];
    }

    private function deleteLessonFiles(Lesson $lesson): void
    {
        $this->deleteLessonDocument($lesson);
        $this->deleteLessonVideo($lesson);
    }

    private function deleteLessonVideo(Lesson $lesson): void
    {
        if ($lesson->versions()->whereIn('status', ['published', 'superseded'])->exists()) {
            return; // Historical release assets must remain available.
        }
        if ($lesson->video_path) {
            Storage::disk('local')->delete($lesson->video_path);
        }

        // Delete HLS directory if exists
        $hlsDir = 'lesson-hls/'.$lesson->id;
        if (Storage::disk('local')->exists($hlsDir)) {
            Storage::disk('local')->deleteDirectory($hlsDir);
        }

        if ($lesson->original_video_key) {
            Storage::disk('s3')->delete($lesson->original_video_key);
        }
        if ($lesson->hls_manifest_key) {
            $s3HlsDir = dirname($lesson->hls_manifest_key);
            Storage::disk('s3')->deleteDirectory($s3HlsDir);
        }
    }

    private function lessonTypes(): array
    {
        return [
            Lesson::TYPE_VIDEO => 'Video bài giảng',
            Lesson::TYPE_DOCUMENT => 'Tài liệu đọc',
            Lesson::TYPE_QUIZ => 'Quiz trắc nghiệm',
            Lesson::TYPE_ASSIGNMENT => 'Bài tập thực hành',
        ];
    }

    private function lessonStatuses(): array
    {
        return [
            Lesson::STATUS_DRAFT => 'Nháp',
            Lesson::STATUS_PUBLISHED => 'Đã sẵn sàng',
        ];
    }

    public function getHlsStatus(Course $course): JsonResponse
    {
        $this->authorizeCourse($course);

        $lessons = Lesson::where('course_id', $course->id)
            ->where('type', 'video')
            ->select(['id', 'title', 'type', 'processing_status', 'upload_status', 'hls_manifest_key', 'video_path', 'video_url', 'hls_playlist', 'hls_path', 'original_video_key', 'duration', 'duration_seconds'])
            ->get();

        $updates = ContentUpdate::where('course_id', $course->id)
            ->where('type', ContentUpdate::TYPE_LESSON)
            ->whereIn('status', [ContentUpdate::STATUS_DRAFT, ContentUpdate::STATUS_PENDING])
            // The newest candidate is authoritative when legacy data contains
            // more than one active draft for the same lesson.
            ->orderByDesc('id')
            ->get();

        $statuses = [];
        foreach ($lessons as $lesson) {
            $isReady = $lesson->isHlsReady();
            $isProcessing = $lesson->isProcessing();
            $isFailed = $lesson->hasFailedProcessing();
            $isMissingSource = ! $lesson->hasVideoSource();
            $isUploading = $lesson->upload_status === 'pending' && ! $isReady;
            $durationSec = (int) ($lesson->duration ?? $lesson->duration_seconds ?? 0);

            if ($isUploading) {
                $isProcessing = true;
                $isFailed = false;
            }

            $statuses['lesson_'.$lesson->id] = [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'upload_status' => $lesson->upload_status,
                'processing_status' => $lesson->processing_status,
                'is_ready' => $isReady,
                'is_processing' => $isProcessing,
                'is_failed' => $isFailed,
                'is_missing_source' => $isMissingSource,
                'is_uploading' => $isUploading,
                'duration' => $durationSec,
                'duration_formatted' => $this->formatDuration($durationSec),
                'status_message' => $isReady
                    ? 'Video đã được xử lý bảo mật thành công.'
                    : ($isUploading
                        ? 'Video đang tải lên trong hàng chờ...'
                        : ($isFailed
                            ? 'Video xử lý bảo mật thất bại.'
                            : ($isProcessing
                                ? 'Video đang trong quá trình xử lý bảo mật. Vui lòng chờ trong giây lát.'
                                : 'Đang chờ xử lý'))),
            ];
        }

        $seenUpdatedLessonIds = [];
        foreach ($updates as $update) {
            if ($update->action === ContentUpdate::ACTION_DELETE) {
                continue;
            }

            if ($update->action === ContentUpdate::ACTION_UPDATE && $update->entity_id) {
                if (isset($seenUpdatedLessonIds[$update->entity_id])) {
                    continue;
                }
                $seenUpdatedLessonIds[$update->entity_id] = true;
            }

            $payload = $update->payload ?? [];
            $effectiveType = $payload['type'] ?? $lessons->firstWhere('id', $update->entity_id)?->type;
            if ($effectiveType !== Lesson::TYPE_VIDEO) {
                continue;
            }

            $video = new Lesson([
                ...$payload,
                'type' => Lesson::TYPE_VIDEO,
            ]);
            $pStatus = $video->processing_status ?? 'pending';
            $isReady = $video->isHlsReady();
            $isFailed = $video->hasFailedProcessing();
            $isProcessing = $video->isProcessing();
            $isMissingSource = ! $video->hasVideoSource();
            $durationSec = (int) ($payload['duration'] ?? $payload['duration_seconds'] ?? 0);

            $key = $update->action === ContentUpdate::ACTION_CREATE
                ? 'update_'.$update->id
                : 'lesson_'.($update->entity_id ?? $update->id);

            $statuses[$key] = [
                'id' => $update->entity_id ?? $update->id,
                'update_id' => $update->id,
                'title' => $payload['title'] ?? 'Video lesson',
                'processing_status' => $pStatus,
                'is_ready' => $isReady,
                'is_processing' => $isProcessing,
                'is_failed' => $isFailed,
                'is_missing_source' => $isMissingSource,
                'duration' => $durationSec,
                'duration_formatted' => $this->formatDuration($durationSec),
                'status_message' => $isReady
                    ? 'Video đã được xử lý bảo mật thành công.'
                    : ($isFailed
                        ? 'Video xử lý bảo mật thất bại.'
                        : ($isProcessing
                            ? 'Video đang trong quá trình xử lý bảo mật. Vui lòng chờ trong giây lát.'
                            : 'Đang chờ xử lý')),
            ];
        }

        $totalVideos = count($statuses);
        $hasIncompleteHls = $course->hasIncompleteHlsVideos();
        $hasFailed = false;
        $hasProcessing = false;
        $missingSources = [];
        $uploadingTitles = [];
        $processingTitles = [];

        foreach ($statuses as $st) {
            if (! empty($st['is_missing_source'])) {
                $missingSources[] = $st['title'] ?? 'Video lesson';
            } elseif ($st['is_failed']) {
                $hasFailed = true;
            } elseif ($st['is_processing'] || ! $st['is_ready']) {
                $hasProcessing = true;
                if (! empty($st['is_uploading'])) {
                    $uploadingTitles[] = $st['title'] ?? 'Video lesson';
                } else {
                    $processingTitles[] = $st['title'] ?? 'Video lesson';
                }
            }
        }

        $submissionCheck = $course->submissionCheck();

        if ($totalVideos === 0) {
            $commonState = 'no_videos';
            $commonMessage = '';
        } elseif ($missingSources !== []) {
            $commonState = 'missing_source';
            $commonMessage = 'Còn '.count($missingSources).' video chưa có nguồn: '.implode(', ', $missingSources).'.';
        } elseif ($hasFailed) {
            $commonState = 'failed';
            $commonMessage = 'Video chưa xử lý hoàn tất. Vui lòng chờ quá trình xử lý video hoàn tất trước khi gửi duyệt.';
        } elseif ($hasProcessing || $hasIncompleteHls) {
            $commonState = 'processing';
            $commonMessage = $uploadingTitles !== []
                ? 'Còn '.count($uploadingTitles).' video đang chờ tải lên: '.implode(', ', $uploadingTitles).'.'
                : 'Còn '.count($processingTitles).' video đang xử lý bảo mật: '.implode(', ', $processingTitles).'.';
        } elseif (! $submissionCheck->passes()) {
            $commonState = 'incomplete';
            $commonMessage = $submissionCheck->summaryMessage();
        } else {
            $commonState = 'completed';
            $commonMessage = 'Tất cả video đã được xử lý bảo mật thành công.';
        }

        return response()->json([
            'total_videos' => $totalVideos,
            'statuses' => $statuses,
            'has_incomplete_hls' => $hasIncompleteHls,
            'can_submit' => $course->canBeSubmittedForReview()
                && $submissionCheck->passes()
                && ! $hasIncompleteHls
                && $missingSources === []
                && ! $hasFailed
                && ! $hasProcessing,
            'submission_message' => $submissionCheck->summaryMessage(),
            'common_state' => $commonState,
            'common_message' => $commonMessage,
            ...$this->reviewStateResponseData($course, auth()->user()),
        ]);
    }
}
