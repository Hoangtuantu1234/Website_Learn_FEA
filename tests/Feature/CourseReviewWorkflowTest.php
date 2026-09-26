<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Models\Category;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\InstructorProfile;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CourseReviewService;
use App\Services\CourseValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CourseReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_access_admin_course_reviews(): void
    {
        $student = User::factory()->create(['role' => 'student', 'email_verified_at' => now()]);

        $this->actingAs($student)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.course-reviews.index'))
            ->assertRedirect($student->dashboardUrl());
    }

    public function test_instructor_cannot_edit_other_instructors_course(): void
    {
        $owner = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'email_verified_at' => now()]);
        $other = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'email_verified_at' => now()]);
        $course = $this->makeDraftCourse($owner);

        $this->actingAs($other)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('instructor.courses.update', $course), $this->coursePayload())
            ->assertForbidden();
    }

    public function test_incomplete_course_can_be_submitted(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        $course = Course::create([
            'instructor_id' => $instructor->id,
            'title' => 'Incomplete',
            'slug' => 'incomplete',
            'price' => 100000,
            'language' => 'vi',
            'status' => CourseStatus::Draft->value,
            'is_published' => false,
            'copyright_agreed' => true,
        ]);

        $validation = app(CourseValidationService::class)->validateForSubmission($course);

        $this->assertTrue($validation['eligible']);
        $this->assertEmpty($validation['errors']);

        $review = app(CourseReviewService::class)->submitForReview($course, $instructor);

        $this->assertEquals(CourseStatus::PendingReview->value, $course->fresh()->status);
        $this->assertEquals(1, $review->submission_number);
        $this->assertNull($review->reviewer_id);
        $this->assertDatabaseHas('course_reviews', [
            'course_id' => $course->id,
            'submission_number' => 1,
            'status' => 'pending',
            'reviewer_id' => null,
        ]);
    }

    public function test_course_with_enough_content_can_be_submitted(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        $course = $this->makeSubmittableCourse($instructor);

        $review = app(CourseReviewService::class)->submitForReview($course, $instructor);

        $this->assertEquals(CourseStatus::PendingReview->value, $course->fresh()->status);
        $this->assertEquals(1, $review->submission_number);
        $this->assertNull($review->reviewer_id);
    }

    public function test_admin_can_approve_course(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'is_active' => true]);
        $course = $this->makeSubmittableCourse($instructor);
        app(CourseReviewService::class)->submitForReview($course, $instructor);

        $checklist = collect(config('course.admin_review_checklist'))->mapWithKeys(fn ($l, $k) => [$k => true])->all();

        $review = app(CourseReviewService::class)->approve($course->fresh(), $admin, $checklist, true);

        $this->assertEquals(CourseStatus::Published->value, $course->fresh()->status);
        $this->assertTrue($course->fresh()->is_published);
        $this->assertEquals($admin->id, $review->reviewer_id);
        $this->assertNotNull($review->reviewed_at);
    }

    public function test_admin_reject_requires_minimum_comment_length(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        $course = $this->makeSubmittableCourse($instructor);
        app(CourseReviewService::class)->submitForReview($course, $instructor);

        $this->expectException(HttpException::class);
        app(CourseReviewService::class)->reject($course->fresh(), $admin, 'short');
    }

    public function test_reject_creates_history_and_instructor_can_see_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        $course = $this->makeSubmittableCourse($instructor);
        app(CourseReviewService::class)->submitForReview($course, $instructor);

        app(CourseReviewService::class)->reject($course->fresh(), $admin, 'Video bài 2 không có âm thanh rõ ràng.');

        $course->refresh();
        $this->assertEquals(CourseStatus::Rejected->value, $course->status);
        $this->assertStringContainsString('âm thanh', $course->rejectionReasonText());
        $this->assertEquals(1, $course->courseReviews()->where('status', 'rejected')->count());
    }

    public function test_admin_reject_assigns_reviewer_only_when_reviewed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        $course = $this->makeSubmittableCourse($instructor);
        $pendingReview = app(CourseReviewService::class)->submitForReview($course, $instructor);

        $this->assertNull($pendingReview->reviewer_id);

        $review = app(CourseReviewService::class)->reject($course->fresh(), $admin, 'Video lesson audio is not clear enough.');

        $this->assertEquals($admin->id, $review->reviewer_id);
        $this->assertNotNull($review->reviewed_at);
        $this->assertEquals('rejected', $review->status->value);
    }

    public function test_course_reviews_reviewer_id_column_allows_null(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $column = DB::selectOne(
                <<<'SQL'
                SELECT IS_NULLABLE
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?
                SQL,
                ['course_reviews', 'reviewer_id'],
            );

            $this->assertSame('YES', $column->IS_NULLABLE ?? null);

            return;
        }

        $this->assertTrue(collect(DB::select('PRAGMA table_info(course_reviews)'))
            ->contains(fn (object $column) => ($column->name ?? null) === 'reviewer_id' && (int) ($column->notnull ?? 1) === 0));
    }

    public function test_review_history_is_not_overwritten_on_resubmit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        $course = $this->makeSubmittableCourse($instructor);

        app(CourseReviewService::class)->submitForReview($course, $instructor);
        app(CourseReviewService::class)->reject($course->fresh(), $admin, 'Cần bổ sung mô tả chi tiết hơn cho bài học.');

        app(CourseReviewService::class)->submitForReview($course->fresh(), $instructor);

        $this->assertEquals(2, $course->fresh()->courseReviews()->count());
        $this->assertEquals(2, $course->fresh()->submission_count);
    }

    private function makeDraftCourse(User $instructor): Course
    {
        $category = Category::create([
            'name' => 'Test',
            'slug' => 'test-'.uniqid(),
            'status' => true,
        ]);
        $profile = InstructorProfile::firstOrCreate(['user_id' => $instructor->id]);
        $profile->teachingCategories()->attach($category->id, [
            'is_primary' => true,
            'approval_status' => 'approved',
        ]);

        return Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Draft Course',
            'slug' => 'draft-course-'.uniqid(),
            'short_description' => 'Short',
            'description' => 'Description long enough',
            'objectives' => 'Learn things',
            'target_audience' => 'Students',
            'requirements' => 'None',
            'thumbnail' => 'thumb.png',
            'price' => 100000,
            'language' => 'vi',
            'level' => 'beginner',
            'status' => CourseStatus::Draft->value,
            'is_published' => false,
            'copyright_agreed' => true,
        ]);
    }

    private function makeSubmittableCourse(User $instructor): Course
    {
        $course = $this->makeDraftCourse($instructor);
        $section = CourseSection::create([
            'course_id' => $course->id,
            'title' => 'Section 1',
            'sort_order' => 1,
        ]);

        foreach (range(1, 5) as $i) {
            Lesson::create([
                'course_id' => $course->id,
                'section_id' => $section->id,
                'title' => "Lesson {$i}",
                'type' => 'video',
                'video_url' => 'https://example.com/video.mp4',
                'duration_seconds' => 360,
                'content' => 'Lesson content',
                'sort_order' => $i,
                'is_required' => true,
                'status' => 'draft',
            ]);
        }

        return $course->fresh(['courseSections.lessons']);
    }

    private function coursePayload(): array
    {
        return [
            'title' => 'Updated',
            'price' => 200000,
            'language' => 'vi',
            'level' => 'beginner',
        ];
    }

    public function test_cannot_submit_course_for_review_without_copyright_agreement(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        $course = $this->makeSubmittableCourse($instructor);
        $course->update(['copyright_agreed' => false]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Bạn phải đồng ý với cam kết bản quyền trước khi gửi duyệt.');

        app(CourseReviewService::class)->submitForReview($course, $instructor);
    }

    public function test_controller_validates_copyright_agreement_input(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'is_active' => true, 'email_verified_at' => now()]);
        $course = $this->makeSubmittableCourse($instructor);
        $course->update(['copyright_agreed' => false]);

        $this->actingAs($instructor)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('instructor.courses.submit', $course), [])
            ->assertSessionHasErrors('copyright_agreed');
    }

    public function test_controller_allows_submission_when_copyright_agreed_in_post_payload(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'is_active' => true, 'email_verified_at' => now()]);
        $course = $this->makeSubmittableCourse($instructor);
        $course->update(['copyright_agreed' => false]);

        $this->actingAs($instructor)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('instructor.courses.submit', $course), ['copyright_agreed' => '1'])
            ->assertRedirect(route('instructor.courses.index'))
            ->assertSessionHas('success');

        $this->assertEquals(CourseStatus::PendingReview->value, $course->fresh()->status);
        $this->assertTrue((bool) $course->fresh()->copyright_agreed);
    }

    public function test_controller_allows_submission_when_course_has_prior_copyright_agreement(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'is_active' => true, 'email_verified_at' => now()]);
        $course = $this->makeSubmittableCourse($instructor);
        $course->update(['copyright_agreed' => true]);

        $this->actingAs($instructor)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('instructor.courses.submit', $course), [])
            ->assertRedirect(route('instructor.courses.index'))
            ->assertSessionHas('success');

        $this->assertEquals(CourseStatus::PendingReview->value, $course->fresh()->status);
    }

    public function test_adding_lesson_to_chapter_assigns_course_id(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'is_active' => true, 'email_verified_at' => now()]);
        $course = $this->makeDraftCourse($instructor);
        $chapter = Chapter::create([
            'course_id' => $course->id,
            'title' => 'Chương 1',
            'sort_order' => 1,
        ]);

        $this->actingAs($instructor)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('instructor.chapters.lessons.store', $chapter), [
                'title' => 'Bài học mới',
                'type' => 'video',
                'video_url' => 'https://example.com/video.mp4',
                'duration_seconds' => 300,
            ])
            ->assertRedirect();

        $lesson = Lesson::where('chapter_id', $chapter->id)->first();
        $this->assertNotNull($lesson);
        $this->assertEquals($course->id, $lesson->course_id);
    }

    public function test_admin_course_reviews_index_displays_instructor_verification_status_and_badges(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

        $approvedInstructor = User::factory()->create([
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $approvedCourse = $this->makeSubmittableCourse($approvedInstructor);
        $approvedCourse->update(['title' => 'Khóa học GV Đã Duyệt', 'status' => CourseStatus::PendingReview->value, 'submitted_at' => now()]);

        $pendingInstructor = User::factory()->create([
            'role' => 'instructor',
            'instructor_status' => 'pending',
            'email_verified_at' => now(),
        ]);
        $pendingCourse = $this->makeSubmittableCourse($pendingInstructor);
        $pendingCourse->update(['title' => 'Khóa học GV Chưa Duyệt', 'status' => CourseStatus::PendingReview->value, 'submitted_at' => now()]);

        // Admin views the course reviews index
        $this->actingAs($admin)
            ->get(route('admin.course-reviews.index'))
            ->assertOk()
            ->assertSee('Đã duyệt')
            ->assertSee('Chưa duyệt hồ sơ')
            ->assertSee('⚠️ Giảng viên chưa được duyệt');

        // Test filter by approved instructor
        $this->actingAs($admin)
            ->get(route('admin.course-reviews.index', ['instructor_status' => 'approved']))
            ->assertOk()
            ->assertSee($approvedCourse->title)
            ->assertDontSee($pendingCourse->title);

        // Test filter by pending instructor
        $this->actingAs($admin)
            ->get(route('admin.course-reviews.index', ['instructor_status' => 'pending']))
            ->assertOk()
            ->assertSee($pendingCourse->title)
            ->assertDontSee($approvedCourse->title);
    }

    public function test_admin_course_review_page_displays_instructor_info_block_and_profile_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

        $pendingInstructor = User::factory()->create([
            'role' => 'instructor',
            'instructor_status' => 'pending',
            'email_verified_at' => now(),
        ]);
        $course = $this->makeSubmittableCourse($pendingInstructor);
        $course->update(['status' => CourseStatus::PendingReview->value, 'submitted_at' => now()]);

        $this->actingAs($admin)
            ->get(route('admin.courses.review', $course))
            ->assertOk()
            ->assertSee('Chưa duyệt hồ sơ')
            ->assertSee('⚠️ Giảng viên chưa được duyệt')
            ->assertSee('Xem hồ sơ giảng viên')
            ->assertSee(route('admin.instructors.applications.show', $pendingInstructor));
    }

    public function test_admin_approve_redirects_to_course_management_not_review_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'is_active' => true]);
        $course = $this->makeSubmittableCourse($instructor);
        app(CourseReviewService::class)->submitForReview($course, $instructor);

        $checklist = collect(config('course.admin_review_checklist'))->mapWithKeys(fn ($label, $key) => [$key => 1])->all();

        $this->actingAs($admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->from(route('admin.course-reviews.show', $course))
            ->post(route('admin.course-reviews.approve', $course), [
                'checklist' => $checklist,
                'publish_immediately' => 1,
            ])
            ->assertRedirect(route('admin.courses.index'))
            ->assertSessionHas('success');

        $this->assertEquals(CourseStatus::Published->value, $course->fresh()->status);
    }

    public function test_admin_approve_from_manage_action_redirects_to_course_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'is_active' => true]);
        $course = $this->makeSubmittableCourse($instructor);
        app(CourseReviewService::class)->submitForReview($course, $instructor);

        $this->actingAs($admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->from(route('admin.courses.review', $course))
            ->post(route('admin.courses.approve', $course))
            ->assertRedirect(route('admin.courses.index'))
            ->assertSessionHas('success');

        $this->assertEquals(CourseStatus::Published->value, $course->fresh()->status);
    }

    public function test_admin_cannot_approve_course_that_is_not_pending(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'is_active' => true]);
        $course = $this->makeDraftCourse($instructor);

        $this->actingAs($admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->from(route('admin.courses.review', $course))
            ->post(route('admin.courses.approve', $course))
            ->assertRedirect(route('admin.courses.review', $course))
            ->assertSessionHas('error');

        $this->assertEquals(CourseStatus::Draft->value, $course->fresh()->status);
    }
}
