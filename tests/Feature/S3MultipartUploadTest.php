<?php

namespace Tests\Feature;

use App\Jobs\ConvertContentUpdateVideoToHLS;
use App\Jobs\ConvertVideoToHLS;
use App\Models\Category;
use App\Models\ContentUpdate;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\InstructorProfile;
use App\Models\InstructorTeachingField;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AwsS3UploadService;
use App\Services\ContentUpdateService;
use App\Services\ContentVersionService;
use App\Services\HlsVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class S3MultipartUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $localStorageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localStorageRoot = storage_path('framework/testing/s3-multipart/'.Str::uuid());
        config(['filesystems.disks.local.root' => $this->localStorageRoot]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        File::deleteDirectory(dirname($this->localStorageRoot));

        parent::tearDown();
    }

    public function test_guest_cannot_access_s3_multipart_routes(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        [$course] = $this->courseWithSection($instructor);

        $this->postJson(route('instructor.courses.s3.multipart.create', $course), [
            'filename' => 'video.mp4',
        ])->assertUnauthorized();
    }

    public function test_instructor_cannot_create_multipart_for_another_instructors_course(): void
    {
        $owner = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'email_verified_at' => now()]);
        $other = $this->signInInstructor();
        [$course] = $this->courseWithSection($owner);

        $this->postJson(route('instructor.courses.s3.multipart.create', $course), [
            'filename' => 'video.mp4',
        ])->assertForbidden();
    }

    public function test_instructor_can_initialize_s3_multipart_upload(): void
    {
        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);

        $this->mock(AwsS3UploadService::class, function (MockInterface $mock) use ($course) {
            $mock->shouldReceive('generateVideoObjectKey')
                ->once()
                ->andReturn("originals/courses/{$course->id}/lessons/new/test-uuid.mp4");
            $mock->shouldReceive('createMultipartUpload')
                ->once()
                ->andReturn('test-upload-id-12345');
            $mock->shouldReceive('getBucket')
                ->once()
                ->andReturn('test-bucket');
        });

        $response = $this->postJson(route('instructor.courses.s3.multipart.create', $course), [
            'filename' => 'lecture_1.mp4',
            'content_type' => 'video/mp4',
            'file_size' => 5368709120, // Exactly 5 GiB; metadata only, no real upload.
        ]);

        $response->assertOk()
            ->assertJson([
                'uploadId' => 'test-upload-id-12345',
                'key' => "originals/courses/{$course->id}/lessons/new/test-uuid.mp4",
            ]);

        $this->postJson(route('instructor.courses.s3.multipart.create', $course), [
            'filename' => 'too-large.mp4',
            'content_type' => 'video/mp4',
            'file_size' => 5368709121,
        ])->assertUnprocessable()->assertJsonValidationErrors('file_size');
    }

    public function test_multipart_initialization_rejects_video_over_configured_limit(): void
    {
        config()->set('video.upload.max_bytes', 1024);

        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);

        $this->mock(AwsS3UploadService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('generateVideoObjectKey');
            $mock->shouldNotReceive('createMultipartUpload');
        });

        $this->postJson(route('instructor.courses.s3.multipart.create', $course), [
            'filename' => 'too-large.mp4',
            'content_type' => 'video/mp4',
            'file_size' => 1025,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_size');
    }

    public function test_instructor_can_sign_s3_parts(): void
    {
        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);
        $key = "originals/courses/{$course->id}/lessons/1/uuid.mp4";

        $this->mock(AwsS3UploadService::class, function (MockInterface $mock) use ($key) {
            $mock->shouldReceive('createPresignedPartUrl')
                ->with($key, 'upload-123', 1)
                ->once()
                ->andReturn('https://s3.amazonaws.com/test-bucket/part1-signed-url');
        });

        $response = $this->postJson(route('instructor.courses.s3.multipart.sign-part', $course), [
            'key' => $key,
            'uploadId' => 'upload-123',
            'partNumber' => 1,
        ]);

        $response->assertOk()
            ->assertJson([
                'url' => 'https://s3.amazonaws.com/test-bucket/part1-signed-url',
            ]);
    }

    public function test_instructor_can_batch_sign_s3_parts(): void
    {
        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);
        $key = "originals/courses/{$course->id}/lessons/1/uuid.mp4";

        $this->mock(AwsS3UploadService::class, function (MockInterface $mock) use ($key) {
            foreach ([1, 2] as $partNumber) {
                $mock->shouldReceive('createPresignedPartUrl')
                    ->with($key, 'upload-123', $partNumber)
                    ->once()
                    ->andReturn("https://example.test/part-{$partNumber}");
            }
        });

        $this->postJson(route('instructor.courses.s3.multipart.batch-sign', $course), [
            'key' => $key,
            'uploadId' => 'upload-123',
            'partNumbers' => [1, 2],
        ])->assertOk()->assertJson([
            'urls' => [1 => 'https://example.test/part-1', 2 => 'https://example.test/part-2'],
        ]);
    }

    public function test_store_video_lesson_with_pending_upload_flag_creates_placeholder_for_s3(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Video chờ S3',
            'type' => 'video',
            'pending_video_upload' => '1',
            'video_original_name' => 'pending.mp4',
            'duration' => 90,
            'sort_order' => 1,
            'status' => 'draft',
        ])->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'title' => 'Video chờ S3',
            'type' => 'video',
            'upload_status' => 'pending',
            'processing_status' => 'pending',
        ]);
    }

    public function test_store_lesson_with_completed_s3_key_dispatches_hls_job(): void
    {
        Queue::fake();
        Storage::fake('s3');

        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        $s3Key = "originals/courses/{$course->id}/lessons/new/test-video.mp4";
        Storage::disk('s3')->put($s3Key, 'completed multipart video');
        $this->mock(AwsS3UploadService::class, function (MockInterface $mock) use ($s3Key): void {
            $mock->shouldReceive('doesObjectExist')
                ->once()
                ->with($s3Key)
                ->andReturnTrue();
        });

        $response = $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Bài học tải lên từ S3',
            'type' => 'video',
            's3_key' => $s3Key,
            'video_original_name' => 'bai_giang_1.mp4',
            'video_mime' => 'video/mp4',
            'video_size' => 143654912, // ~137MB
            'duration' => 1200,
            'sort_order' => 1,
            'status' => 'draft',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài học tải lên từ S3',
            'type' => 'video',
            'original_video_key' => $s3Key,
            'video_original_name' => 'bai_giang_1.mp4',
            'video_size' => 143654912,
            'upload_status' => 'uploaded',
            'processing_status' => 'pending',
            'duration_seconds' => 1200,
        ]);

        Queue::assertPushed(ConvertVideoToHLS::class, function (ConvertVideoToHLS $job) use ($course): bool {
            return $job->lesson->course_id === $course->id
                && $job->lesson->original_video_key === "originals/courses/{$course->id}/lessons/new/test-video.mp4";
        });
    }

    public function test_s3_multipart_complete_dispatches_hls_job_for_lesson(): void
    {
        Queue::fake();

        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        $s3Key = "originals/courses/{$course->id}/lessons/1/video.mp4";

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài 1',
            'type' => 'video',
            'original_video_key' => $s3Key,
            'upload_status' => 'pending',
            'processing_status' => 'pending',
        ]);

        $this->mock(AwsS3UploadService::class, function (MockInterface $mock) use ($s3Key) {
            $mock->shouldReceive('completeMultipartUpload')
                ->once()
                ->andReturn([
                    'location' => "https://s3.amazonaws.com/test-bucket/{$s3Key}",
                    'key' => $s3Key,
                ]);
        });

        $response = $this->postJson(route('instructor.courses.s3.multipart.complete', $course), [
            'key' => $s3Key,
            'uploadId' => 'upl-123',
            'parts' => [
                ['PartNumber' => 1, 'ETag' => '"etag-1"'],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'upload_status' => 'uploaded',
        ]);

        Queue::assertPushed(ConvertVideoToHLS::class, function ($job) use ($lesson) {
            return $job->lesson->id === $lesson->id;
        });
    }

    public function test_course_preview_multipart_uses_its_own_key_prefix_without_touching_lessons(): void
    {
        Queue::fake();

        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);
        $key = "previews/courses/{$course->id}/preview-uuid.mp4";

        $this->mock(AwsS3UploadService::class, function (MockInterface $mock) use ($course, $key): void {
            $mock->shouldReceive('generateCoursePreviewObjectKey')
                ->once()
                ->with($course->id, 'gioi-thieu.mp4')
                ->andReturn($key);
            $mock->shouldReceive('createMultipartUpload')
                ->once()
                ->with($key, 'video/mp4')
                ->andReturn('preview-upload-id');
            $mock->shouldReceive('getBucket')
                ->once()
                ->andReturn('test-bucket');
        });

        $this->postJson(route('instructor.courses.s3.multipart.create', $course), [
            'media_type' => 'course_preview',
            'filename' => 'gioi-thieu.mp4',
            'content_type' => 'video/mp4',
            'file_size' => 10 * 1024 * 1024,
        ])->assertOk()
            ->assertJsonPath('key', $key)
            ->assertJsonPath('mediaType', 'course_preview');

        $this->assertDatabaseCount('lessons', 0);
        Queue::assertNothingPushed();
    }

    public function test_course_preview_multipart_complete_does_not_save_a_lesson_or_dispatch_hls(): void
    {
        Queue::fake();

        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);
        $key = "previews/courses/{$course->id}/preview-uuid.mp4";

        $this->mock(AwsS3UploadService::class, function (MockInterface $mock) use ($course, $key): void {
            $mock->shouldReceive('isCoursePreviewObjectKeyForCourse')
                ->once()
                ->with($course->id, $key)
                ->andReturnTrue();
            $mock->shouldReceive('completeMultipartUpload')
                ->once()
                ->with($key, 'preview-upload-id', [
                    ['PartNumber' => 1, 'ETag' => '"preview-etag"'],
                ])
                ->andReturn([
                    'location' => "https://s3.amazonaws.com/test-bucket/{$key}",
                    'key' => $key,
                ]);
        });

        $this->postJson(route('instructor.courses.s3.multipart.complete', $course), [
            'media_type' => 'course_preview',
            'key' => $key,
            'uploadId' => 'preview-upload-id',
            'duration' => 120,
            'parts' => [
                ['PartNumber' => 1, 'ETag' => '"preview-etag"'],
            ],
        ])->assertOk()
            ->assertJsonPath('key', $key)
            ->assertJsonPath('mediaType', 'course_preview');

        $this->assertDatabaseCount('lessons', 0);
        Queue::assertNotPushed(ConvertVideoToHLS::class);
        Queue::assertNotPushed(ConvertContentUpdateVideoToHLS::class);
    }

    public function test_legacy_video_player_falls_back_to_local_disk_when_s3_key_is_null(): void
    {
        Storage::fake('s3');
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài học cũ',
            'type' => 'video',
            'is_preview' => true,
            'upload_status' => 'uploaded',
            'processing_status' => 'completed',
        ]);

        $lesson->update(['video_path' => 'lesson-hls/'.$lesson->id.'/playlist.m3u8']);

        Storage::disk('local')->put('lesson-hls/'.$lesson->id.'/playlist.m3u8', "#EXTM3U\n#EXTINF:10.0,\nsegment0.ts\n");

        $tokenResponse = $this->getJson("/api/video/{$lesson->id}/token");
        $tokenResponse->assertOk();
        $token = $tokenResponse->json('token');

        $playlistResponse = $this->get("/api/video/hls/{$lesson->id}/playlist.m3u8?token={$token}");
        $playlistResponse->assertOk()
            ->assertSee('segment0.ts?token='.$token);
    }

    public function test_instructor_can_fetch_hls_status_for_course_lessons(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        $lessonProcessing = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài đang xử lý',
            'type' => 'video',
            'original_video_key' => "originals/courses/{$course->id}/lessons/1/video1.mp4",
            'upload_status' => 'uploaded',
            'processing_status' => 'processing',
            'sort_order' => 1,
            'status' => 'draft',
        ]);

        $lessonCompleted = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài đã hoàn tất',
            'type' => 'video',
            'original_video_key' => "originals/courses/{$course->id}/lessons/2/video2.mp4",
            'hls_manifest_key' => 'hls/lessons/2/master.m3u8',
            'upload_status' => 'uploaded',
            'processing_status' => 'completed',
            'sort_order' => 2,
            'status' => 'draft',
        ]);

        $response = $this->getJson(route('instructor.courses.hls-status', $course));

        $response->assertOk()
            ->assertJsonStructure(['statuses', 'can_submit', 'common_state', 'common_message'])
            ->assertJsonPath('can_submit', false)
            ->assertJsonPath('common_state', 'processing')
            ->assertJsonPath('common_message', 'Còn 1 video đang xử lý bảo mật: Bài đang xử lý.');
    }

    public function test_hls_status_ignores_non_video_and_approved_lesson_updates(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        ContentUpdate::create([
            'course_id' => $course->id,
            'type' => ContentUpdate::TYPE_LESSON,
            'action' => ContentUpdate::ACTION_CREATE,
            'status' => ContentUpdate::STATUS_DRAFT,
            'created_by' => $instructor->id,
            'payload' => ['type' => Lesson::TYPE_DOCUMENT, 'title' => 'Tài liệu'],
        ]);

        ContentUpdate::create([
            'course_id' => $course->id,
            'type' => ContentUpdate::TYPE_LESSON,
            'action' => ContentUpdate::ACTION_CREATE,
            'status' => ContentUpdate::STATUS_APPROVED,
            'created_by' => $instructor->id,
            'payload' => [
                'type' => Lesson::TYPE_VIDEO,
                'original_video_key' => 'originals/old.mp4',
                'processing_status' => 'pending',
            ],
        ]);

        ContentUpdate::create([
            'course_id' => $course->id,
            'type' => ContentUpdate::TYPE_LESSON,
            'action' => ContentUpdate::ACTION_CREATE,
            'status' => ContentUpdate::STATUS_DRAFT,
            'created_by' => $instructor->id,
            'payload' => [
                'type' => Lesson::TYPE_VIDEO,
                'original_video_key' => 'originals/new.mp4',
                'hls_manifest_key' => 'hls/updates/new/master.m3u8',
                'processing_status' => 'completed',
            ],
        ]);

        $this->getJson(route('instructor.courses.hls-status', $course))
            ->assertOk()
            ->assertJsonPath('total_videos', 1)
            ->assertJsonPath('common_state', 'incomplete')
            ->assertJsonPath('can_submit', false);
    }

    public function test_course_submit_is_rejected_when_video_hls_is_incomplete(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài đang xử lý',
            'type' => 'video',
            'original_video_key' => "originals/courses/{$course->id}/lessons/1/video1.mp4",
            'upload_status' => 'uploaded',
            'processing_status' => 'processing',
            'sort_order' => 1,
            'status' => 'draft',
        ]);

        $course->update(['copyright_agreed' => true]);

        $response = $this->post(route('instructor.courses.submit', $course), [
            'copyright_agreed' => 1,
        ]);

        $response->assertSessionHas('error', 'Khóa học chưa thể gửi duyệt vì video vẫn đang được xử lý bảo mật.');
    }

    public function test_hls_publication_replaces_local_output_without_leaving_stale_segments(): void
    {
        config()->set('filesystems.disks.s3.key');
        config()->set('filesystems.disks.s3.secret');
        config()->set('filesystems.disks.s3.bucket');

        $outputDirectory = storage_path('framework/testing/hls-output-'.Str::uuid());
        File::ensureDirectoryExists($outputDirectory);
        File::put($outputDirectory.'/master.m3u8', "#EXTM3U\nplaylist.m3u8\n");
        File::put($outputDirectory.'/playlist.m3u8', "#EXTM3U\nsegment_00000.ts\n");
        File::put($outputDirectory.'/segment_00000.ts', 'segment');
        Storage::disk('local')->put('lesson-hls/99/stale.ts', 'stale');

        try {
            $result = app(HlsVideoService::class)->publish(
                $outputDirectory,
                'hls/lessons/99',
                'lesson-hls/99',
            );

            $this->assertFalse($result['use_s3']);
            $this->assertTrue($result['mirrored_locally']);
            $this->assertSame(3, $result['file_count']);
            Storage::disk('local')->assertExists('lesson-hls/99/master.m3u8');
            Storage::disk('local')->assertExists('lesson-hls/99/playlist.m3u8');
            Storage::disk('local')->assertExists('lesson-hls/99/segment_00000.ts');
            Storage::disk('local')->assertMissing('lesson-hls/99/stale.ts');
        } finally {
            File::deleteDirectory($outputDirectory);
        }
    }

    public function test_hls_job_marks_a_video_without_source_as_failed(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Video không có nguồn',
            'type' => Lesson::TYPE_VIDEO,
            'processing_status' => 'pending',
            'status' => 'draft',
        ]);

        (new ConvertVideoToHLS($lesson))->handle(app(HlsVideoService::class));

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'processing_status' => 'failed',
        ]);
    }

    public function test_content_update_hls_job_marks_missing_source_failed_without_losing_payload(): void
    {
        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);
        $contentUpdate = ContentUpdate::create([
            'course_id' => $course->id,
            'type' => ContentUpdate::TYPE_LESSON,
            'action' => ContentUpdate::ACTION_CREATE,
            'status' => ContentUpdate::STATUS_DRAFT,
            'created_by' => $instructor->id,
            'payload' => [
                'type' => Lesson::TYPE_VIDEO,
                'title' => 'Video nháp không có nguồn',
                'processing_status' => 'pending',
            ],
        ]);

        (new ConvertContentUpdateVideoToHLS($contentUpdate))->handle(
            app(ContentUpdateService::class),
            app(ContentVersionService::class),
        );

        $payload = $contentUpdate->fresh()->payload;
        $this->assertSame('failed', $payload['processing_status']);
        $this->assertSame('Video nháp không có nguồn', $payload['title']);
    }

    private function signInInstructor(?User $user = null): User
    {
        $user ??= User::factory()->create([
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $user;
    }

    /**
     * @return array{0: Course, 1: CourseSection}
     */
    private function courseWithSection(User $instructor): array
    {
        $category = Category::create([
            'name' => 'Danh mục '.uniqid(),
            'slug' => 'category-'.uniqid(),
        ]);
        $profile = InstructorProfile::firstOrCreate(['user_id' => $instructor->id]);
        $profile->teachingCategories()->syncWithoutDetaching([
            $category->id => [
                'is_primary' => ! $profile->teachingCategories()->exists(),
                'approval_status' => InstructorTeachingField::STATUS_APPROVED,
            ],
        ]);

        $course = Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Khóa học '.uniqid(),
            'slug' => 'course-'.uniqid(),
            'short_description' => 'Mô tả ngắn',
            'description' => 'Mô tả khóa học đủ dài',
            'objectives' => 'Mục tiêu học tập',
            'target_audience' => 'Học viên',
            'requirements' => 'Không yêu cầu',
            'price' => 100000,
            'language' => 'vi',
            'level' => 'beginner',
            'status' => Course::STATUS_DRAFT,
            'is_published' => false,
        ]);

        $section = CourseSection::create([
            'course_id' => $course->id,
            'title' => 'Chương 1',
            'sort_order' => 0,
        ]);

        return [$course, $section];
    }
}
