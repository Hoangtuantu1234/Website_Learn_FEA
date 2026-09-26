<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\InstructorProfile;
use App\Models\InstructorTeachingField;
use App\Models\User;
use App\Enums\CourseStatus;
use App\Services\CourseReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InstructorCourseCategoryAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_create_form_only_lists_registered_categories_and_auto_selects_the_only_one(): void
    {
        [$instructor, $allowed, $outside] = $this->instructorWithTeachingFields(1);

        $response = $this->actingAs($instructor)->get(route('instructor.courses.create'));

        $response->assertOk()
            ->assertSee($allowed->name)
            ->assertDontSee($outside->name)
            ->assertSee('value="'.$allowed->id.'" selected', false);
    }

    public function test_instructor_cannot_create_course_in_unregistered_category(): void
    {
        [$instructor, $allowed, $outside] = $this->instructorWithTeachingFields(1);

        $response = $this->actingAs($instructor)->from(route('instructor.courses.create'))->post(route('instructor.courses.store'), $this->coursePayload($outside));

        $response->assertRedirect(route('instructor.courses.create'))
            ->assertSessionHasErrors(['category_id' => 'Bạn không có quyền tạo khóa học thuộc ngành này.']);
        $this->assertDatabaseMissing('courses', ['title' => 'Khóa học bị từ chối']);
    }

    public function test_instructor_can_create_course_in_registered_category(): void
    {
        [$instructor, $allowed] = $this->instructorWithTeachingFields(1);

        $response = $this->actingAs($instructor)->post(route('instructor.courses.store'), $this->coursePayload($allowed, 'Khóa học hợp lệ'));

        $response->assertRedirect();
        $this->assertDatabaseHas('courses', ['instructor_id' => $instructor->id, 'category_id' => $allowed->id, 'title' => 'Khóa học hợp lệ']);
    }

    public function test_instructor_cannot_change_course_to_unregistered_category(): void
    {
        [$instructor, $allowed, $outside] = $this->instructorWithTeachingFields(1);
        $course = $this->course($instructor, $allowed);

        $response = $this->actingAs($instructor)->from(route('instructor.courses.edit', $course))->put(route('instructor.courses.update', $course), $this->coursePayload($outside, $course->title));

        $response->assertRedirect(route('instructor.courses.edit', $course))
            ->assertSessionHasErrors(['category_id' => 'Bạn không có quyền tạo khóa học thuộc ngành này.']);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'category_id' => $allowed->id]);
    }

    public function test_curriculum_actions_are_rejected_when_course_category_is_not_in_teaching_fields(): void
    {
        [$instructor, $allowed, $outside] = $this->instructorWithTeachingFields(1);
        $course = $this->course($instructor, $outside);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Chương 1', 'sort_order' => 0]);

        $this->actingAs($instructor)
            ->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
                'title' => 'Bài học không hợp lệ', 'type' => 'document', 'sort_order' => 0, 'status' => 'draft',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_manage_existing_course_in_primary_profile_category_while_field_is_pending(): void
    {
        [$instructor, $allowed] = $this->instructorWithTeachingFields(1);
        $instructor->instructorProfile->update(['category_id' => $allowed->id]);
        InstructorTeachingField::query()
            ->where('instructor_profile_id', $instructor->instructorProfile->id)
            ->where('category_id', $allowed->id)
            ->update(['approval_status' => InstructorTeachingField::STATUS_PENDING]);
        $course = $this->course($instructor, $allowed);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Chương 1', 'sort_order' => 0]);
        $lesson = \App\Models\Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài học chủ sở hữu',
            'type' => 'document',
            'content' => 'Nội dung',
            'sort_order' => 0,
            'status' => 'draft',
        ]);

        $this->actingAs($instructor)
            ->put(route('instructor.courses.lessons.update', [$course, $lesson]), [
                'title' => 'Bài học chủ sở hữu đã sửa',
                'type' => 'document',
                'content' => 'Nội dung mới',
                'sort_order' => 0,
                'status' => 'draft',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'title' => 'Bài học chủ sở hữu đã sửa',
        ]);
    }

    public function test_s3_upload_is_rejected_when_course_category_is_not_in_teaching_fields(): void
    {
        [$instructor, $allowed, $outside] = $this->instructorWithTeachingFields(1);
        $course = $this->course($instructor, $outside);

        $this->actingAs($instructor)
            ->postJson(route('instructor.courses.s3.multipart.create', $course), ['filename' => 'bai-giang.mp4'])
            ->assertForbidden();
    }

    public function test_draft_pending_and_rejected_fields_do_not_grant_course_creation_but_approved_field_does(): void
    {
        [$instructor, $allowed] = $this->instructorWithTeachingFields(1);
        $field = InstructorTeachingField::query()
            ->where('instructor_profile_id', $instructor->instructorProfile->id)
            ->where('category_id', $allowed->id)
            ->firstOrFail();

        foreach ([
            InstructorTeachingField::STATUS_DRAFT,
            InstructorTeachingField::STATUS_PENDING,
            InstructorTeachingField::STATUS_REJECTED,
        ] as $status) {
            $field->update(['approval_status' => $status]);

            $this->actingAs($instructor)
                ->post(route('instructor.courses.store'), $this->coursePayload($allowed, 'Blocked '.$status))
                ->assertSessionHasErrors('category_id');
        }

        $field->update(['approval_status' => InstructorTeachingField::STATUS_APPROVED]);
        $this->actingAs($instructor)
            ->post(route('instructor.courses.store'), $this->coursePayload($allowed, 'Approved field course'))
            ->assertRedirect();
    }

    public function test_published_course_in_approved_field_is_public(): void
    {
        [$instructor, $allowed] = $this->instructorWithTeachingFields(1);
        $course = $this->course($instructor, $allowed);
        $course->update([
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->get(route('courses.show', $course->slug))->assertOk();
    }

    public function test_superseded_field_keeps_existing_published_course_public_and_manageable_but_blocks_new_course(): void
    {
        [$instructor, $allowed] = $this->instructorWithTeachingFields(1);
        $course = $this->course($instructor, $allowed);
        $course->update([
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);
        $field = InstructorTeachingField::query()
            ->where('instructor_profile_id', $instructor->instructorProfile->id)
            ->where('category_id', $allowed->id)
            ->firstOrFail();
        $field->update(['approval_status' => InstructorTeachingField::STATUS_SUPERSEDED]);

        $this->get(route('courses.show', $course->slug))->assertOk();
        $this->actingAs($instructor)
            ->get(route('instructor.courses.edit', $course))
            ->assertOk();
        $this->actingAs($instructor)
            ->post(route('instructor.courses.store'), $this->coursePayload($allowed, 'New superseded course'))
            ->assertSessionHasErrors('category_id');
    }

    public function test_published_course_in_unapproved_field_remains_hidden_from_public(): void
    {
        [$instructor, $allowed, $outside] = $this->instructorWithTeachingFields(1);
        $course = $this->course($instructor, $outside);
        $course->update([
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->assertFalse(app(\App\Services\InstructorCourseCategoryAccess::class)->canManageCourse($instructor, $course));
        $this->get(route('courses.show', $course->slug))->assertNotFound();
    }

    public function test_unpublished_and_rejected_courses_remain_hidden_from_public(): void
    {
        [$instructor, $allowed] = $this->instructorWithTeachingFields(1);

        foreach ([Course::STATUS_DRAFT, Course::STATUS_REJECTED] as $status) {
            $course = $this->course($instructor, $allowed);
            $course->update([
                'status' => $status,
                'is_published' => false,
                'published_at' => null,
            ]);

            $this->get(route('courses.show', $course->slug))->assertNotFound();
        }
    }

    public function test_replacement_blocks_new_a_courses_after_b_approval_but_keeps_existing_a_course_manageable(): void
    {
        [$instructor, $categoryA, $categoryB] = $this->instructorWithTeachingFields(1);
        $courseA = $this->course($instructor, $categoryA);
        $fieldA = InstructorTeachingField::query()
            ->where('instructor_profile_id', $instructor->instructorProfile->id)
            ->where('category_id', $categoryA->id)
            ->firstOrFail();
        InstructorTeachingField::create([
            'instructor_profile_id' => $fieldA->instructor_profile_id,
            'category_id' => $categoryB->id,
            'approval_status' => InstructorTeachingField::STATUS_APPROVED,
            'replace_of_teaching_field_id' => $fieldA->id,
        ]);
        $fieldA->update(['approval_status' => InstructorTeachingField::STATUS_SUPERSEDED]);

        $this->actingAs($instructor)
            ->post(route('instructor.courses.store'), $this->coursePayload($categoryA, 'New A must be blocked'))
            ->assertSessionHasErrors('category_id');
        $this->actingAs($instructor)
            ->get(route('instructor.courses.edit', $courseA))
            ->assertOk();
        $this->actingAs($instructor)
            ->put(route('instructor.courses.update', $courseA), $this->coursePayload($categoryA, 'Updated legacy A course'))
            ->assertRedirect();
        $this->actingAs($instructor)
            ->post(route('instructor.courses.store'), $this->coursePayload($categoryB, 'New B is allowed'))
            ->assertRedirect();
    }

    public function test_course_submit_and_admin_publish_do_not_bypass_an_unapproved_field(): void
    {
        [$instructor, $allowed, $outside] = $this->instructorWithTeachingFields(1);
        $course = $this->course($instructor, $outside);

        $this->actingAs($instructor)
            ->post(route('instructor.courses.submit', $course), ['copyright_agreed' => true])
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $course->update(['status' => Course::STATUS_APPROVED]);
        $this->actingAs($admin)
            ->post(route('admin.courses.publish', $course))
            ->assertStatus(422);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'status' => Course::STATUS_APPROVED]);
    }

    public function test_admin_review_does_not_bypass_an_unapproved_field(): void
    {
        [$instructor, $allowed, $outside] = $this->instructorWithTeachingFields(1);
        $course = $this->course($instructor, $outside);
        $course->update(['status' => CourseStatus::PendingReview->value]);
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

        try {
            app(CourseReviewService::class)->approve($course, $admin, [], true);
            $this->fail('Admin review must not approve a course in an unapproved teaching field.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    /** @return array{0: User, 1: Category, 2: Category} */
    private function instructorWithTeachingFields(int $fieldCount): array
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved', 'email_verified_at' => now()]);
        $parent = Category::create(['name' => 'Công nghệ', 'slug' => 'cong-nghe', 'status' => true]);
        $allowed = Category::create(['name' => 'Phát triển Web', 'slug' => 'phat-trien-web', 'parent_id' => $parent->id, 'status' => true]);
        $outside = Category::create(['name' => 'Marketing', 'slug' => 'marketing', 'parent_id' => $parent->id, 'status' => true]);
        $profile = InstructorProfile::create(['user_id' => $instructor->id]);
        $profile->teachingCategories()->attach($allowed->id, [
            'is_primary' => true,
            'approval_status' => InstructorTeachingField::STATUS_APPROVED,
        ]);

        if ($fieldCount > 1) {
            $profile->teachingCategories()->attach($outside->id, [
                'is_primary' => false,
                'approval_status' => InstructorTeachingField::STATUS_APPROVED,
            ]);
        }

        return [$instructor, $allowed, $outside];
    }

    private function coursePayload(Category $category, string $title = 'Khóa học bị từ chối'): array
    {
        return ['title' => $title, 'category_id' => $category->id, 'price' => 100000, 'level' => 'beginner', 'language' => 'vi'];
    }

    private function course(User $instructor, Category $category): Course
    {
        return Course::create([
            'instructor_id' => $instructor->id, 'category_id' => $category->id, 'title' => 'Khóa học hiện có',
            'slug' => 'khoa-hoc-hien-co-'.uniqid(), 'price' => 100000, 'language' => 'vi',
            'level' => 'beginner', 'status' => Course::STATUS_DRAFT, 'is_published' => false,
        ]);
    }
}
