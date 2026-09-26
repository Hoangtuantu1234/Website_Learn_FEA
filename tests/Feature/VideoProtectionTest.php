<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Services\VideoTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VideoProtectionTest extends TestCase
{
    use DatabaseTransactions;

    private string $localStorageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localStorageRoot = storage_path('framework/testing/video-protection/'.Str::uuid());
        config(['filesystems.disks.local.root' => $this->localStorageRoot]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        File::deleteDirectory($this->localStorageRoot);

        parent::tearDown();
    }

    public function test_unauthorized_user_cannot_access_hls_key(): void
    {
        $lesson = $this->createTestLesson();

        // Request key without token
        $response = $this->getJson(route('video.hls.key', ['lesson' => $lesson->id]));

        $response->assertStatus(404);
    }

    public function test_invalid_token_cannot_access_hls_key(): void
    {
        $lesson = $this->createTestLesson();

        // Request key with invalid token
        $response = $this->getJson(route('video.hls.key', [
            'lesson' => $lesson->id,
            'token' => 'invalid_token_123',
        ]));

        $response->assertStatus(404);
    }

    public function test_authorized_token_can_access_hls_key_when_key_exists(): void
    {
        $user = User::create([
            'name' => 'Test User '.uniqid(),
            'email' => 'user_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $lesson = $this->createTestLesson($user);

        $hlsDir = 'lesson-hls/'.$lesson->id;
        Storage::disk('local')->makeDirectory($hlsDir);

        $keyContent = random_bytes(16);
        Storage::disk('local')->put($hlsDir.'/enc.key', $keyContent);

        /** @var VideoTokenService $tokenService */
        $tokenService = app(VideoTokenService::class);
        $token = $tokenService->generateToken($user->id, $lesson->id);

        $response = $this->get(route('video.hls.key', [
            'lesson' => $lesson->id,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertEquals($keyContent, $response->getContent());
    }

    public function test_playlist_replaces_key_uri_with_token(): void
    {
        $user = User::create([
            'name' => 'Test User '.uniqid(),
            'email' => 'user_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $lesson = $this->createTestLesson($user);

        $hlsDir = 'lesson-hls/'.$lesson->id;
        Storage::disk('local')->makeDirectory($hlsDir);

        $sampleM3u8 = "#EXTM3U\n"
            ."#EXT-X-VERSION:3\n"
            ."#EXT-X-KEY:METHOD=AES-128,URI=\"/api/video/hls/{$lesson->id}/enc.key\"\n"
            ."#EXTINF:10.000000,\n"
            ."segment0.ts\n";

        Storage::disk('local')->put($hlsDir.'/playlist.m3u8', $sampleM3u8);

        /** @var VideoTokenService $tokenService */
        $tokenService = app(VideoTokenService::class);
        $token = $tokenService->generateToken($user->id, $lesson->id);

        $response = $this->get(route('video.hls.playlist', [
            'lesson' => $lesson->id,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('enc.key?token='.urlencode($token), $content);
        $this->assertStringContainsString('segment0.ts?token='.urlencode($token), $content);
        $this->assertStringNotContainsString('http', $content);
        $this->assertStringNotContainsString('amazonaws', $content);
    }

    public function test_s3_playlist_keeps_tokenized_segments_instead_of_direct_object_urls(): void
    {
        Storage::fake('s3');
        config([
            'filesystems.disks.s3.key' => 'testing',
            'filesystems.disks.s3.secret' => 'testing',
            'filesystems.disks.s3.bucket' => 'test-video',
        ]);

        $instructor = User::create([
            'name' => 'Instructor '.uniqid(),
            'email' => 'inst_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role' => 'instructor',
            'instructor_status' => 'approved',
        ]);
        $lesson = $this->createTestLesson($instructor);
        $lesson->update(['hls_manifest_key' => 'hls/lessons/'.$lesson->id.'/playlist.m3u8']);
        Storage::disk('s3')->put('hls/lessons/'.$lesson->id.'/playlist.m3u8', "#EXTM3U\n#EXTINF:10.0,\nsegment0.ts\n");

        $token = app(VideoTokenService::class)->generateToken($instructor->id, $lesson->id);
        $content = $this->get(route('video.hls.playlist', ['lesson' => $lesson->id, 'token' => $token]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('segment0.ts?token='.urlencode($token), $content);
        $this->assertStringNotContainsString('temporary', $content);
        $this->assertStringNotContainsString('X-Amz-', $content);
    }

    public function test_guest_and_unenrolled_student_cannot_get_video_token(): void
    {
        $lesson = $this->createTestLesson();

        $this->getJson(route('video.token', $lesson))->assertUnauthorized();

        $student = User::factory()->create(['role' => 'student', 'email_verified_at' => now()]);
        $this->actingAs($student)
            ->getJson(route('video.token', $lesson))
            ->assertForbidden();
    }

    public function test_direct_segment_request_without_valid_token_is_rejected(): void
    {
        $lesson = $this->createTestLesson();
        $hlsDir = 'lesson-hls/'.$lesson->id;
        Storage::disk('local')->put($hlsDir.'/segment0.ts', 'ts-bytes');

        $this->get(route('video.hls.segment', ['lesson' => $lesson->id, 'segment' => 'segment0.ts']))
            ->assertNotFound();
        $this->get(route('video.hls.segment', ['lesson' => $lesson->id, 'segment' => 'segment0.ts', 'token' => 'bad']))
            ->assertNotFound();
    }

    public function test_ai_scan_reads_original_s3_video_and_cleans_up_on_success_and_failure(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        Storage::fake('public');
        config(['filesystems.disks.s3.bucket' => 'test-video']);
        $lesson = $this->createTestLesson();
        $lesson->update(['original_video_key' => 'videos/original.mp4', 'hls_manifest_key' => 'hls/master.m3u8']);
        Storage::disk('s3')->put('videos/original.mp4', 'original-video');
        foreach ([false, true] as $fail) {
            $temporary = null;
            $extractor = \Mockery::mock(\App\Services\VideoFrameExtractor::class);
            $extractor->shouldReceive('extract')->once()->andReturnUsing(function ($path) use (&$temporary, $fail) {
                $temporary = $path;
                $this->assertSame('original-video', file_get_contents($path));
                if ($fail) {
                    throw new \RuntimeException('Synthetic extraction failure');
                }
                return ['frame.jpg'];
            });
            $response = app(\App\Http\Controllers\Web\Admin\AiModerationController::class)->extractFrames($lesson->id, $extractor);
            $this->assertSame($fail ? 500 : 200, $response->getStatusCode());
            $this->assertFileDoesNotExist($temporary);
        }
    }

    public function test_ai_scan_uses_draft_original_instead_of_published_video(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        Storage::fake('public');
        config(['filesystems.disks.s3.bucket' => 'test-video']);
        $lesson = $this->createTestLesson();
        $lesson->update(['original_video_key' => 'videos/old.mp4']);
        \App\Models\ContentUpdate::create([
            'course_id' => $lesson->course_id, 'entity_id' => $lesson->id,
            'type' => 'lesson', 'action' => 'update', 'status' => 'pending',
            'created_by' => $lesson->course->instructor_id,
            'payload' => ['original_video_key' => 'videos/new.mp4', 'hls_manifest_key' => 'hls/new.m3u8'],
        ]);
        Storage::disk('s3')->put('videos/new.mp4', 'draft-video');
        $extractor = \Mockery::mock(\App\Services\VideoFrameExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturnUsing(function ($path) {
            $this->assertSame('draft-video', file_get_contents($path));
            return ['frame.jpg'];
        });
        $response = app(\App\Http\Controllers\Web\Admin\AiModerationController::class)->extractFrames($lesson->id, $extractor);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_bulk_ai_scan_button_includes_s3_originals(): void
    {
        $lesson = $this->createTestLesson();
        $lesson->update(['original_video_key' => 'videos/s3-only.mp4', 'video_path' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.courses.review', $lesson->course_id))
            ->assertOk()
            ->assertSee('id="btn-scan-course-ai"', false)
            ->assertViewHas('videoLessons', fn ($videos) => $videos->contains('id', $lesson->id));
    }

    public function test_reading_completion_requires_thirty_seconds_and_is_idempotent(): void
    {
        $lesson = $this->createTestLesson();
        $lesson->update(['type' => 'document']);
        $student = User::factory()->create(['role' => 'student']);
        \App\Models\Enrollment::create([
            'user_id' => $student->id, 'course_id' => $lesson->course_id,
            'status' => 'active', 'enrolled_at' => now(),
        ]);
        $key = 'reading-start:'.$student->id.':'.$lesson->id;
        \Illuminate\Support\Facades\Cache::put($key, now()->timestamp, 3600);
        $service = app(\App\Services\LearningProgressService::class);
        try {
            $service->recordLessonProgress($student->id, $lesson->course, $lesson, 0, 0, true);
            $this->fail('Early completion must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->travel(30)->seconds();
        $result = $service->recordLessonProgress($student->id, $lesson->course, $lesson, 0, 0, true);
        $this->assertTrue($result['lesson_completed']);
        $again = $service->recordLessonProgress($student->id, $lesson->course, $lesson, 0, 0, true);
        $this->assertSame($result['completed_lessons'], $again['completed_lessons']);
        $this->travelBack();
        \Illuminate\Support\Facades\Cache::forget($key);
    }

    private function createTestLesson(?User $instructor = null): Lesson
    {
        $instructor ??= User::create([
            'name' => 'Instructor '.uniqid(),
            'email' => 'inst_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role' => 'instructor',
            'instructor_status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Cat '.uniqid(),
            'slug' => 'cat-'.uniqid(),
        ]);

        $course = Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Course '.uniqid(),
            'slug' => 'course-'.uniqid(),
            'short_description' => 'Desc',
            'description' => 'Full desc text',
            'objectives' => 'Objectives',
            'target_audience' => 'Audience',
            'requirements' => 'Requirements',
            'price' => 100000,
            'language' => 'vi',
            'level' => 'beginner',
            'status' => Course::STATUS_DRAFT,
            'is_published' => false,
        ]);

        $section = CourseSection::create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'sort_order' => 0,
        ]);

        return Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Lesson '.uniqid(),
            'type' => 'video',
            'sort_order' => 1,
            'status' => 'published',
            'upload_status' => 'uploaded',
            'processing_status' => 'completed',
        ]);
    }
}
