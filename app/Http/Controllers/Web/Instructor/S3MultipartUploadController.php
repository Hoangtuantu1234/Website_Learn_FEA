<?php

namespace App\Http\Controllers\Web\Instructor;

use App\Http\Controllers\Controller;
use App\Jobs\ConvertContentUpdateVideoToHLS;
use App\Jobs\ConvertVideoToHLS;
use App\Models\ContentUpdate;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVersion;
use App\Services\AwsS3UploadService;
use App\Services\ContentUpdateService;
use App\Services\ContentVersionService;
use App\Services\InstructorCourseCategoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class S3MultipartUploadController extends Controller
{
    private const MEDIA_TYPE_COURSE_PREVIEW = 'course_preview';

    public function __construct(
        private AwsS3UploadService $s3Service,
        private InstructorCourseCategoryAccess $courseCategoryAccess,
    ) {}

    /**
     * Khởi tạo Multipart Upload session trên S3
     */
    public function create(Request $request, Course $course): JsonResponse
    {
        if ($this->isCoursePreviewRequest($request)) {
            return $this->createCoursePreview($request, $course);
        }

        $this->authorizeCourse($course);

        $maxVideoBytes = max(1, (int) config('video.upload.max_bytes'));
        $maxVideoMegabytes = (int) ceil($maxVideoBytes / 1048576);
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['nullable', 'string', 'max:100'],
            'lesson_id' => [
                'nullable',
                'integer',
            ],
            'content_update_id' => ['nullable', 'integer'],
            'file_size' => [
                'required',
                'integer',
                'min:1',
                'max:'.$maxVideoBytes,
            ],
            'key' => ['nullable', 'string', 'max:1024'],
        ], [
            'file_size.required' => 'Không xác định được dung lượng video.',
            'file_size.max' => "Dung lượng video tối đa là {$maxVideoMegabytes}MB.",
        ]);

        $filename = $validated['filename'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $contentType = $validated['content_type'] ?: 'video/'.($extension === 'mov' ? 'quicktime' : $extension);
        $lessonId = $validated['lesson_id'] ?? null;

        $lesson = $lessonId
            ? Lesson::query()->where('course_id', $course->id)->find($lessonId)
            : null;
        if (! $lesson && $lessonId) {
            $lesson = $this->materializeLegacyDraftCreateLesson(
                $course,
                (int) $lessonId,
                (int) ($validated['content_update_id'] ?? 0),
                $request->user(),
            );
        }
        if ($lessonId && ! $lesson) {
            throw ValidationException::withMessages(['lesson_id' => 'Bài học không thuộc khóa học này.']);
        }

        $contentUpdate = null;
        $candidate = null;
        if ($course->isPublished() && $lesson) {
            $requestedUpdateId = (int) ($validated['content_update_id'] ?? 0);
            $contentUpdate = $requestedUpdateId > 0
                ? ContentUpdate::query()
                    ->whereKey($requestedUpdateId)
                    ->where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_LESSON)
                    ->where('action', ContentUpdate::ACTION_CREATE)
                    ->where('entity_id', $lesson->id)
                    ->where('created_by', $request->user()->id)
                    ->where('status', ContentUpdate::STATUS_DRAFT)
                    ->first()
                : null;
            $contentUpdate ??= app(ContentUpdateService::class)->ensureLessonUpdateDraft($course, $lesson, $request->user());
            if ((int) $contentUpdate->created_by !== (int) $request->user()->id) {
                throw ValidationException::withMessages([
                    'content_update_id' => 'Bản nháp video không thuộc giảng viên đang thao tác.',
                ]);
            }
            if ($contentUpdate->action === ContentUpdate::ACTION_UPDATE) {
                $candidate = LessonVersion::query()
                    ->where('content_update_id', $contentUpdate->id)
                    ->where('lesson_id', $lesson->id)
                    ->where('status', LessonVersion::STATUS_DRAFT)
                    ->firstOrFail();
                if ((int) $lesson->fresh()->draft_version_id !== (int) $candidate->id) {
                    throw ValidationException::withMessages([
                        'content_update_id' => 'Phiên bản nháp video không khớp với bài học.',
                    ]);
                }
            }
        }

        // Sinh hoặc sử dụng S3 object key bảo mật theo cấu trúc quy định
        $key = $validated['key'] ?? ($candidate
            ? $this->s3Service->generateDraftVideoObjectKey($course->id, $lesson->id, $contentUpdate->id, $candidate->version_number, $filename)
            : $this->s3Service->generateVideoObjectKey($course->id, $lesson?->id ?? $lessonId, $filename));
        if ($contentUpdate && $candidate && ! Str::startsWith($key, $this->draftVideoKeyPrefix($course, $lesson, $contentUpdate, $candidate))) {
            throw ValidationException::withMessages([
                'key' => 'Video không thuộc đúng bản nháp bài học đang chỉnh sửa.',
            ]);
        }
        if (! $candidate && ! empty($validated['key'])) {
            $this->validateKeyPrefix($course, $key);
        }

        try {
            $uploadId = $this->s3Service->createMultipartUpload($key, $contentType);

            if ($contentUpdate && $candidate) {
                $contentUpdate = app(ContentUpdateService::class)->updateDraft($contentUpdate, [
                    'original_video_key' => $key,
                    'upload_status' => 'pending',
                    'processing_status' => 'pending',
                ]);
                $preparedCandidate = app(ContentVersionService::class)->prepareDraftCandidate($contentUpdate, $request->user());
                if (! $preparedCandidate || (int) $preparedCandidate->id !== (int) $candidate->id) {
                    throw ValidationException::withMessages([
                        'content_update_id' => 'Phiên bản nháp video đã thay đổi khi khởi tạo tải lên.',
                    ]);
                }
            }

            return response()->json([
                'uploadId' => $uploadId,
                'key' => $key,
                'lessonId' => $lesson?->id,
                'status' => 'initialized',
                'bucket' => $this->s3Service->getBucket(),
                'contentUpdateId' => $contentUpdate?->id,
                'versionNumber' => $candidate?->version_number,
            ]);
        } catch (Throwable $e) {
            Log::error('S3 Multipart Upload create failed: '.$e->getMessage(), [
                'course_id' => $course->id,
                'key' => $key,
            ]);

            return response()->json([
                'message' => 'Không thể khởi tạo phiên tải lên S3. Vui lòng thử lại sau.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lấy Presigned URL cho từng Part
     */
    public function getPartUrl(Request $request, Course $course): JsonResponse
    {
        if ($this->isCoursePreviewRequest($request)) {
            return $this->signCoursePreviewPart($request, $course);
        }

        $this->authorizeCourse($course);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            'uploadId' => ['required', 'string', 'max:1024'],
            'partNumber' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $key = $validated['key'];
        $this->validateKeyPrefix($course, $key);

        try {
            $presignedUrl = $this->s3Service->createPresignedPartUrl(
                $key,
                $validated['uploadId'],
                $validated['partNumber']
            );

            return response()->json([
                'url' => $presignedUrl,
                'partNumber' => $validated['partNumber'],
            ]);
        } catch (Throwable $e) {
            Log::error('S3 Multipart Upload sign part failed: '.$e->getMessage(), [
                'course_id' => $course->id,
                'key' => $key,
                'partNumber' => $validated['partNumber'],
            ]);

            return response()->json([
                'message' => 'Không thể tạo URL tải lên cho part '.$validated['partNumber'],
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch lấy Presigned URLs cho nhiều Parts cùng một lúc
     */
    public function batchSignParts(Request $request, Course $course): JsonResponse
    {
        if ($this->isCoursePreviewRequest($request)) {
            return $this->batchSignCoursePreviewParts($request, $course);
        }

        $this->authorizeCourse($course);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            'uploadId' => ['required', 'string', 'max:1024'],
            'partNumbers' => ['required', 'array', 'min:1', 'max:1000'],
            'partNumbers.*' => ['integer', 'min:1', 'max:10000'],
        ]);

        $key = $validated['key'];
        $this->validateKeyPrefix($course, $key);

        $urls = [];
        try {
            foreach ($validated['partNumbers'] as $partNumber) {
                $urls[$partNumber] = $this->s3Service->createPresignedPartUrl(
                    $key,
                    $validated['uploadId'],
                    $partNumber
                );
            }

            return response()->json([
                'urls' => $urls,
            ]);
        } catch (Throwable $e) {
            Log::error('S3 Multipart Upload batch sign parts failed: '.$e->getMessage(), [
                'course_id' => $course->id,
                'key' => $key,
            ]);

            return response()->json([
                'message' => 'Không thể tạo URLs tải lên cho danh sách parts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hoàn tất quá trình Multipart Upload
     */
    public function complete(Request $request, Course $course): JsonResponse
    {
        if ($this->isCoursePreviewRequest($request)) {
            return $this->completeCoursePreview($request, $course);
        }

        $this->authorizeCourse($course);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            'uploadId' => ['required', 'string', 'max:1024'],
            'parts' => ['required', 'array', 'min:1', 'max:10000'],
            'parts.*.PartNumber' => ['required', 'integer', 'min:1', 'max:10000', 'distinct'],
            'parts.*.ETag' => ['required', 'string', 'max:255'],
            'duration' => ['nullable', 'numeric', 'min:0'],
            'lesson_id' => [
                'nullable',
                'integer',
                Rule::exists('lessons', 'id')->where('course_id', $course->id),
            ],
            'content_update_id' => ['nullable', 'integer'],
        ]);

        $key = $validated['key'];
        $duration = (int) round((float) ($validated['duration'] ?? 0));
        $lessonId = $validated['lesson_id'] ?? null;
        $this->validateKeyPrefix($course, $key);

        $completedKey = null;
        try {
            return DB::transaction(function () use ($request, $course, $validated, $lessonId, $key, $duration, &$completedKey) {
                $course = Course::query()->lockForUpdate()->findOrFail($course->id);
                $this->authorizeCourse($course);

                // Resolve and lock the exact lesson before completing the S3 upload.
                $lesson = $lessonId
                    ? Lesson::query()->where('course_id', $course->id)->lockForUpdate()->find($lessonId)
                    : null;
                if (! $lesson) {
                    $lesson = Lesson::where('course_id', $course->id)
                        ->when($lessonId, fn ($q) => $q->whereKey($lessonId), fn ($q) => $q->where('original_video_key', $key))
                        ->lockForUpdate()->first();
                }

                $contentUpdate = null;
                $candidate = null;
                if ($course->isContentApproved()) {
                    if (! $lesson) {
                        throw ValidationException::withMessages([
                            'lesson_id' => 'Hãy lưu bài học trước khi tải video cho khóa học đã xuất bản.',
                        ]);
                    }

                    $requestedUpdateId = (int) ($validated['content_update_id'] ?? 0);
                    if ($requestedUpdateId <= 0) {
                        throw ValidationException::withMessages([
                            'content_update_id' => 'Thiếu định danh bản nháp video đang tải lên.',
                        ]);
                    }

                $contentUpdate = ContentUpdate::query()
                    ->whereKey($requestedUpdateId)
                    ->where('course_id', $course->id)
                    ->where('type', ContentUpdate::TYPE_LESSON)
                    ->whereIn('action', [ContentUpdate::ACTION_CREATE, ContentUpdate::ACTION_UPDATE])
                        ->where('entity_id', $lesson->id)
                        ->where('created_by', $request->user()->id)
                        ->where('status', ContentUpdate::STATUS_DRAFT)
                        ->lockForUpdate()
                        ->first();
                    if (! $contentUpdate) {
                        throw ValidationException::withMessages([
                            'content_update_id' => 'Bản nháp video không hợp lệ hoặc không còn có thể chỉnh sửa.',
                        ]);
                    }

                    if ($contentUpdate->action === ContentUpdate::ACTION_UPDATE) {
                        $candidate = LessonVersion::query()
                            ->where('content_update_id', $contentUpdate->id)
                            ->where('lesson_id', $lesson->id)
                            ->where('status', LessonVersion::STATUS_DRAFT)
                            ->lockForUpdate()
                            ->first();
                        if (! $candidate || (int) $lesson->draft_version_id !== (int) $candidate->id) {
                            throw ValidationException::withMessages([
                                'content_update_id' => 'Phiên bản nháp video không khớp với bài học đang chỉnh sửa.',
                            ]);
                        }

                        if (! Str::startsWith($key, $this->draftVideoKeyPrefix($course, $lesson, $contentUpdate, $candidate))) {
                            throw ValidationException::withMessages([
                                'key' => 'Video không thuộc đúng bản nháp bài học đang chỉnh sửa.',
                            ]);
                        }
                    }
                }

                $result = $this->s3Service->completeMultipartUpload($key, $validated['uploadId'], $validated['parts']);
                $completedKey = $key;

                // A published-course video can belong to either an existing
                // lesson update (with a LessonVersion candidate) or a new
                // draft lesson (CREATE, intentionally without a candidate).
                // Both cases must finish the multipart upload through the
                // content-update pipeline so HLS is queued after S3 confirms
                // every part.
                if ($contentUpdate) {
                    $payload = $contentUpdate->payload ?? [];
                    $changes = [
                        'original_video_key' => $key,
                        'upload_status' => 'uploaded',
                    ];
                    if ($duration > 0 && empty($payload['duration']) && empty($payload['duration_seconds'])) {
                        $changes['duration'] = $duration;
                        $changes['duration_seconds'] = $duration;
                    }
                    $contentUpdate = app(ContentUpdateService::class)->updateDraft($contentUpdate, $changes);
                    // A CREATE update owns a draft-only Lesson row. Keeping the
                    // original object key on that real row makes the upload-to-
                    // lesson mapping durable without touching a live lesson.
                    if ($contentUpdate->action === ContentUpdate::ACTION_CREATE) {
                        $lesson->update([
                            'original_video_key' => $key,
                            'upload_status' => 'uploaded',
                            'processing_status' => 'pending',
                            ...($duration > 0 ? ['duration' => $duration, 'duration_seconds' => $duration] : []),
                        ]);
                    }
                    if ($candidate) {
                        $preparedCandidate = app(ContentVersionService::class)->prepareDraftCandidate($contentUpdate, $request->user());
                        if (! $preparedCandidate || (int) $preparedCandidate->id !== (int) $candidate->id) {
                            throw ValidationException::withMessages([
                                'content_update_id' => 'Phiên bản nháp video đã thay đổi trong khi hoàn tất tải lên.',
                            ]);
                        }
                    }
                    Log::info('[S3 MULTIPART COMPLETE] DISPATCH HLS JOB for ContentUpdate', ['content_update_id' => $contentUpdate->id, 'lesson_version_id' => $candidate?->id, 'key' => $key]);
                    ConvertContentUpdateVideoToHLS::dispatch($contentUpdate, $key)->afterCommit();

                    return response()->json([
                        'status' => 'success',
                        'key' => $key,
                        'location' => $result['location'] ?? null,
                        'content_update_id' => $contentUpdate->id,
                        'contentUpdateId' => $contentUpdate->id,
                        'versionNumber' => $candidate?->version_number,
                    ]);
                }

                if ($lesson) {
                    $videoData = [
                        'original_video_key' => $key,
                        'upload_status' => 'uploaded',
                        'processing_status' => 'pending',
                    ];
                    if ($duration > 0) {
                        $videoData += ['duration' => $duration, 'duration_seconds' => $duration];
                    }

                    // Re-check after upload; approval may have changed while S3 was receiving parts.
                    abort_unless(in_array($course->status, [Course::STATUS_DRAFT, Course::STATUS_REJECTED], true), 409);
                    $lesson->update($videoData);
                    ConvertVideoToHLS::dispatch($lesson)->afterCommit();
                }

                return response()->json([
                    'status' => 'success',
                    'key' => $key,
                    'content_update_id' => null,
                    'location' => $result['location'] ?? null,
                ]);
            });
        } catch (ValidationException $e) {
            $this->deleteCompletedUploadIfOrphaned($completedKey);

            return response()->json([
                'message' => $e->validator->errors()->first() ?: $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            $this->deleteCompletedUploadIfOrphaned($completedKey);
            Log::error('S3 multipart upload completion failed.', [
                'exception' => $e,
                'course_id' => $course->id,
                'part_count' => count($validated['parts']),
            ]);

            return response()->json([
                'message' => 'Không thể hoàn tất tải video lên lúc này. Vui lòng thử lại.',
            ], 500);
        }
    }

    /**
     * Hủy Multipart Upload khi có lỗi hoặc người dùng bấm cancel
     */
    public function abort(Request $request, Course $course): JsonResponse
    {
        if ($this->isCoursePreviewRequest($request)) {
            return $this->abortCoursePreview($request, $course);
        }

        $this->authorizeCourse($course);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            'uploadId' => ['required', 'string', 'max:1024'],
        ]);

        $key = $validated['key'];
        $this->validateKeyPrefix($course, $key);

        $this->s3Service->abortMultipartUpload($key, $validated['uploadId']);

        return response()->json([
            'status' => 'aborted',
        ]);
    }

    /**
     * The preview branch is deliberately self-contained. The legacy lesson
     * branch above remains unchanged when media_type is absent or different.
     */
    private function createCoursePreview(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($course);

        $maxBytes = max(1, (int) config('video.course_preview.max_bytes'));
        $maxMegabytes = (int) ceil($maxBytes / 1048576);
        $validated = $request->validate([
            'media_type' => ['required', Rule::in([self::MEDIA_TYPE_COURSE_PREVIEW])],
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['nullable', 'string', 'max:100'],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
        ], [
            'file_size.max' => "Dung lượng video giới thiệu tối đa là {$maxMegabytes}MB.",
        ]);

        $extension = strtolower(pathinfo($validated['filename'], PATHINFO_EXTENSION));
        if (! in_array($extension, config('video.course_preview.allowed_extensions', ['mp4']), true)) {
            throw ValidationException::withMessages([
                'filename' => 'Video giới thiệu chỉ hỗ trợ định dạng MP4.',
            ]);
        }

        $contentType = strtolower((string) ($validated['content_type'] ?: 'video/mp4'));
        if (! in_array($contentType, ['video/mp4', 'application/octet-stream'], true)) {
            throw ValidationException::withMessages([
                'content_type' => 'Video giới thiệu phải có định dạng MP4 hợp lệ.',
            ]);
        }

        $key = $this->s3Service->generateCoursePreviewObjectKey($course->id, $validated['filename']);

        try {
            return response()->json([
                'uploadId' => $this->s3Service->createMultipartUpload($key, 'video/mp4'),
                'key' => $key,
                'mediaType' => self::MEDIA_TYPE_COURSE_PREVIEW,
                'status' => 'initialized',
                'bucket' => $this->s3Service->getBucket(),
            ]);
        } catch (Throwable $e) {
            Log::error('S3 course preview multipart upload create failed: '.$e->getMessage(), [
                'course_id' => $course->id,
                'key' => $key,
            ]);

            return response()->json([
                'message' => 'Không thể khởi tạo tải video giới thiệu lên S3. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    private function signCoursePreviewPart(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($course);
        $validated = $request->validate([
            'media_type' => ['required', Rule::in([self::MEDIA_TYPE_COURSE_PREVIEW])],
            'key' => ['required', 'string', 'max:1024'],
            'uploadId' => ['required', 'string', 'max:1024'],
            'partNumber' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $this->validateCoursePreviewKey($course, $validated['key']);

        try {
            return response()->json([
                'url' => $this->s3Service->createPresignedPartUrl(
                    $validated['key'],
                    $validated['uploadId'],
                    $validated['partNumber'],
                ),
                'partNumber' => $validated['partNumber'],
            ]);
        } catch (Throwable $e) {
            Log::error('S3 course preview part signing failed: '.$e->getMessage(), [
                'course_id' => $course->id,
                'key' => $validated['key'],
                'partNumber' => $validated['partNumber'],
            ]);

            return response()->json(['message' => 'Không thể tạo URL tải lên cho video giới thiệu.'], 500);
        }
    }

    private function batchSignCoursePreviewParts(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($course);
        $validated = $request->validate([
            'media_type' => ['required', Rule::in([self::MEDIA_TYPE_COURSE_PREVIEW])],
            'key' => ['required', 'string', 'max:1024'],
            'uploadId' => ['required', 'string', 'max:1024'],
            'partNumbers' => ['required', 'array', 'min:1', 'max:1000'],
            'partNumbers.*' => ['integer', 'min:1', 'max:10000'],
        ]);
        $this->validateCoursePreviewKey($course, $validated['key']);

        try {
            $urls = [];
            foreach ($validated['partNumbers'] as $partNumber) {
                $urls[$partNumber] = $this->s3Service->createPresignedPartUrl(
                    $validated['key'],
                    $validated['uploadId'],
                    $partNumber,
                );
            }

            return response()->json(['urls' => $urls]);
        } catch (Throwable $e) {
            Log::error('S3 course preview batch part signing failed: '.$e->getMessage(), [
                'course_id' => $course->id,
                'key' => $validated['key'],
            ]);

            return response()->json(['message' => 'Không thể tạo URLs tải lên cho video giới thiệu.'], 500);
        }
    }

    private function completeCoursePreview(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($course);
        $maxDuration = max(1, (int) config('video.course_preview.max_duration_seconds', 180));
        $validated = $request->validate([
            'media_type' => ['required', Rule::in([self::MEDIA_TYPE_COURSE_PREVIEW])],
            'key' => ['required', 'string', 'max:1024'],
            'uploadId' => ['required', 'string', 'max:1024'],
            'parts' => ['required', 'array', 'min:1', 'max:10000'],
            'parts.*.PartNumber' => ['required', 'integer', 'min:1', 'max:10000', 'distinct'],
            'parts.*.ETag' => ['required', 'string', 'max:255'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:'.$maxDuration],
        ], [
            'duration.max' => 'Video giới thiệu không được dài quá 3 phút.',
        ]);
        $this->validateCoursePreviewKey($course, $validated['key']);

        try {
            $result = $this->s3Service->completeMultipartUpload(
                $validated['key'],
                $validated['uploadId'],
                $validated['parts'],
            );

            // Deliberately no Lesson mutation and no HLS queue dispatch here.
            return response()->json([
                'status' => 'success',
                'key' => $validated['key'],
                'location' => $result['location'] ?? null,
                'mediaType' => self::MEDIA_TYPE_COURSE_PREVIEW,
            ]);
        } catch (Throwable $e) {
            Log::error('S3 course preview multipart upload completion failed.', [
                'exception' => $e,
                'course_id' => $course->id,
                'key' => $validated['key'],
            ]);

            return response()->json(['message' => 'Không thể hoàn tất tải video giới thiệu. Vui lòng thử lại.'], 500);
        }
    }

    private function abortCoursePreview(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($course);
        $validated = $request->validate([
            'media_type' => ['required', Rule::in([self::MEDIA_TYPE_COURSE_PREVIEW])],
            'key' => ['required', 'string', 'max:1024'],
            'uploadId' => ['required', 'string', 'max:1024'],
        ]);
        $this->validateCoursePreviewKey($course, $validated['key']);

        $this->s3Service->abortMultipartUpload($validated['key'], $validated['uploadId']);

        return response()->json(['status' => 'aborted']);
    }

    private function isCoursePreviewRequest(Request $request): bool
    {
        return $request->input('media_type') === self::MEDIA_TYPE_COURSE_PREVIEW;
    }

    private function validateCoursePreviewKey(Course $course, string $key): void
    {
        abort_unless(
            $this->s3Service->isCoursePreviewObjectKeyForCourse($course->id, $key),
            403,
            'Đường dẫn S3 video giới thiệu không hợp lệ hoặc không thuộc khóa học này.',
        );
    }

    private function deleteCompletedUploadIfOrphaned(?string $key): void
    {
        if (! filled($key)) {
            return;
        }

        try {
            $this->s3Service->getS3Client()->deleteObject([
                'Bucket' => $this->s3Service->getBucket(),
                'Key' => $key,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not delete orphaned S3 object after failed lesson save.', [
                'key' => $key,
            ]);
        }
    }

    private function authorizeCourse(Course $course): void
    {
        abort_unless(
            $this->courseCategoryAccess->canManageCourse(auth()->user(), $course),
            403,
            'Bạn không có quyền chỉnh sửa nội dung khóa học này.'
        );
    }

    private function validateKeyPrefix(Course $course, string $key): void
    {
        $expectedPrefix = "originals/courses/{$course->id}/";
        if (! Str::startsWith($key, $expectedPrefix)) {
            abort(403, 'Đường dẫn S3 không hợp lệ hoặc không thuộc khóa học này.');
        }
    }

    private function draftVideoKeyPrefix(
        Course $course,
        Lesson $lesson,
        ContentUpdate $contentUpdate,
        LessonVersion $candidate
    ): string {
        return "originals/courses/{$course->id}/lessons/{$lesson->id}/content-updates/{$contentUpdate->id}/versions/v{$candidate->version_number}/";
    }

    /**
     * Legacy draft-create rows used their ContentUpdate ID as a temporary UI
     * lesson ID. Materialize one real draft Lesson only for that exact record,
     * so the existing multipart security checks can continue using lesson_id.
     */
    private function materializeLegacyDraftCreateLesson(Course $course, int $providedLessonId, int $contentUpdateId, $user): ?Lesson
    {
        if ($contentUpdateId <= 0 || $providedLessonId !== $contentUpdateId) {
            return null;
        }

        return DB::transaction(function () use ($course, $contentUpdateId, $user): ?Lesson {
            $update = ContentUpdate::query()
                ->whereKey($contentUpdateId)
                ->where('course_id', $course->id)
                ->where('type', ContentUpdate::TYPE_LESSON)
                ->where('action', ContentUpdate::ACTION_CREATE)
                ->where('status', ContentUpdate::STATUS_DRAFT)
                ->where('created_by', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $update) {
                return null;
            }

            if ($update->entity_id) {
                return Lesson::query()->where('course_id', $course->id)->find($update->entity_id);
            }

            $payload = $update->payload ?? [];
            $sectionId = (int) ($payload['section_id'] ?? 0);
            $sectionExists = $sectionId > 0
                && $course->courseSections()->whereKey($sectionId)->exists();
            if (! $sectionExists) {
                throw ValidationException::withMessages(['lesson_id' => 'Chương học của bài học nháp không hợp lệ.']);
            }

            $lesson = Lesson::create([
                ...array_intersect_key($payload, array_flip([
                    'title', 'type', 'video_url', 'video_path', 'original_video_key',
                    'hls_manifest_key', 'upload_status', 'processing_status',
                    'video_original_name', 'video_mime', 'video_size', 'content',
                    'document_file', 'duration', 'duration_seconds', 'is_preview',
                    'is_required', 'sort_order', 'attachments', 'subtitles',
                ])),
                'course_id' => $course->id,
                'section_id' => $sectionId,
                'status' => Lesson::STATUS_DRAFT,
            ]);
            $update->update(['entity_id' => $lesson->id]);

            Log::info('[S3 MULTIPART] Materialized legacy draft lesson identity.', [
                'course_id' => $course->id,
                'content_update_id' => $update->id,
                'lesson_id' => $lesson->id,
            ]);

            return $lesson;
        });
    }
}
