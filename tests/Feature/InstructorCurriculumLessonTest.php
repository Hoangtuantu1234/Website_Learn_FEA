<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Category;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\InstructorProfile;
use App\Models\InstructorTeachingField;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CurriculumLessonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class InstructorCurriculumLessonTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_section_lessons_url_redirects_owner_to_the_sections_actual_course(): void
    {
        $instructor = $this->signInInstructor();
        [$wrongCourse] = $this->courseWithSection($instructor);
        [$actualCourse, $section] = $this->courseWithSection($instructor);

        $this->get("/instructor/courses/{$wrongCourse->id}/sections/{$section->id}/lessons")
            ->assertRedirect(route('instructor.courses.curriculum', $actualCourse))
            ->assertSessionHas('error');

        $this->get(route('instructor.courses.curriculum', $actualCourse))->assertOk();
    }

    public function test_lesson_form_starts_with_common_fields_and_type_panels_are_exclusive(): void
    {
        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);

        $response = $this->get(route('instructor.courses.curriculum', $course));

        $response->assertOk();
        $response->assertSee('data-lesson-form', false);
        $response->assertSee('data-initial-type="none"', false);
        $response->assertSee("x-show=\"selectedType === 'video'\"", false);
        $response->assertSee("x-show=\"selectedType === 'document'\"", false);
        $response->assertSee("x-show=\"selectedType === 'quiz'\"", false);
        $response->assertSee("x-show=\"selectedType === 'assignment'\"", false);
        $response->assertSee("x-bind:disabled=\"selectedType !== 'video'\"", false);
        $response->assertSee("x-bind:disabled=\"selectedType !== 'document'\"", false);
        $response->assertSee("x-bind:disabled=\"selectedType !== 'quiz'\"", false);
        $response->assertSee("x-bind:disabled=\"selectedType !== 'assignment'\"", false);
    }

    public function test_video_lesson_can_be_created_with_only_video_fields(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Video mở đầu',
            'type' => 'video',
            'video_url' => 'https://example.com/video.mp4',
            'duration' => 300,
            'sort_order' => 1,
            'status' => 'published',
            'is_preview' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Video mở đầu',
            'type' => 'video',
            'video_url' => 'https://example.com/video.mp4',
            'duration_seconds' => 300,
            'is_preview' => true,
            'status' => 'published',
        ]);
    }

    public function test_document_lesson_can_be_created_without_video_data(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Tài liệu cài đặt',
            'type' => 'document',
            'content' => 'Các bước cài đặt môi trường.',
            'duration' => 120,
            'sort_order' => 2,
            'status' => 'draft',
        ])->assertRedirect();

        $lesson = Lesson::query()->where('title', 'Tài liệu cài đặt')->firstOrFail();

        $this->assertSame('document', $lesson->type);
        $this->assertSame('Các bước cài đặt môi trường.', $lesson->content);
        $this->assertNull($lesson->video_url);
        $this->assertNull($lesson->video_path);
    }

    public function test_quiz_lesson_redirects_to_existing_quiz_manager(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        $response = $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Quiz cuối chương',
            'type' => 'quiz',
            'duration' => 60,
            'sort_order' => 3,
            'status' => 'draft',
        ]);

        $lesson = Lesson::query()->where('title', 'Quiz cuối chương')->firstOrFail();

        $response->assertRedirect(route('instructor.courses.lessons.quiz.show', [$course, $lesson]));
        $this->assertSame('quiz', $lesson->type);
        $this->assertNull($lesson->content);

        $this->get(route('instructor.courses.lessons.quiz.show', [$course, $lesson]))->assertOk();
        $this->assertDatabaseHas('quizzes', [
            'lesson_id' => $lesson->id,
            'title' => 'Quiz cuối chương',
        ]);
    }

    public function test_assignment_lesson_creates_assignment_record_from_existing_schema(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Bài tập Laravel',
            'type' => 'assignment',
            'content' => 'Xây dựng CRUD cho khóa học.',
            'assignment_due_days' => 7,
            'assignment_max_score' => 100,
            'assignment_passing_score' => 60,
            'duration' => 180,
            'sort_order' => 4,
            'status' => 'published',
        ])->assertRedirect();

        $lesson = Lesson::query()->where('title', 'Bài tập Laravel')->firstOrFail();

        $this->assertDatabaseHas('assignments', [
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'title' => 'Bài tập Laravel',
            'description' => 'Xây dựng CRUD cho khóa học.',
            'due_days' => 7,
            'max_score' => 100,
            'passing_score' => 60,
        ]);
    }

    public function test_rejected_course_uses_the_same_direct_creation_flow_as_draft_course(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        $course->update(['status' => Course::STATUS_REJECTED]);

        $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Tài liệu sửa sau từ chối',
            'type' => Lesson::TYPE_DOCUMENT,
            'content' => 'Nội dung đã chỉnh sửa.',
            'duration' => 75,
        ])->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Tài liệu sửa sau từ chối',
            'duration' => 75,
            'duration_seconds' => 75,
        ]);
        $this->assertDatabaseMissing('content_updates', [
            'course_id' => $course->id,
            'type' => 'lesson',
        ]);
    }

    public function test_pending_review_course_cannot_receive_direct_lesson_writes(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        $course->update(['status' => Course::STATUS_PENDING]);

        $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Không được ghi trực tiếp',
            'type' => Lesson::TYPE_DOCUMENT,
            'content' => 'Nội dung không hợp lệ theo trạng thái khóa.',
            'duration' => 75,
        ])->assertForbidden();

        $this->assertDatabaseMissing('lessons', [
            'course_id' => $course->id,
            'title' => 'Không được ghi trực tiếp',
        ]);
    }

    public function test_service_rolls_back_lesson_and_cleans_uploaded_file_when_assignment_creation_fails(): void
    {
        Storage::fake('public');

        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        Assignment::creating(static function (): void {
            throw new RuntimeException('Simulated assignment persistence failure.');
        });

        try {
            app(CurriculumLessonService::class)->create($course, $section, [
                'title' => 'Bài tập phải rollback',
                'type' => Lesson::TYPE_ASSIGNMENT,
                'content' => 'Nội dung bài tập.',
                'document_file' => UploadedFile::fake()->create('assignment.pdf', 20, 'application/pdf'),
                'assignment_max_score' => 100,
                'assignment_passing_score' => 70,
                'duration' => 120,
            ]);

            $this->fail('The simulated assignment failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated assignment persistence failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('lessons', [
            'course_id' => $course->id,
            'title' => 'Bài tập phải rollback',
        ]);
        $this->assertDatabaseMissing('assignments', [
            'course_id' => $course->id,
            'title' => 'Bài tập phải rollback',
        ]);
        $this->assertSame([], Storage::disk('public')->allFiles('lesson-documents'));
    }

    public function test_validation_depends_on_selected_lesson_type_and_keeps_old_type(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        $errorBag = 'storeLesson_'.$section->id;

        $this
            ->from(route('instructor.courses.curriculum', $course))
            ->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
                'title' => 'Video thiếu nội dung',
                'type' => 'video',
                'duration' => 90,
                'sort_order' => 5,
                'status' => 'draft',
            ])
            ->assertRedirect(route('instructor.courses.curriculum', $course))
            ->assertSessionHasErrors(['video_url'], null, $errorBag)
            ->assertSessionHasInput('type', 'video');

        $this
            ->from(route('instructor.courses.curriculum', $course))
            ->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
                'title' => 'Tài liệu gửi sai field',
                'type' => 'document',
                'video_url' => 'https://example.com/should-not-send.mp4',
                'duration' => 90,
                'sort_order' => 6,
                'status' => 'draft',
            ])
            ->assertRedirect(route('instructor.courses.curriculum', $course))
            ->assertSessionHasErrors(['video_url', 'content'], null, $errorBag)
            ->assertSessionHasInput('type', 'document');
    }

    public function test_edit_lesson_form_opens_current_type_panel(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Quiz đã có',
            'type' => 'quiz',
            'sort_order' => 1,
            'status' => 'draft',
        ]);

        $this->get(route('instructor.courses.curriculum', $course))
            ->assertOk()
            ->assertSee('data-initial-type="quiz"', false);
    }

    public function test_update_document_lesson_keeps_existing_file_when_no_new_file_is_uploaded(): void
    {
        Storage::fake('public');

        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        Storage::disk('public')->put('lesson-documents/current.pdf', 'old file');

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Tài liệu cũ',
            'type' => 'document',
            'content' => 'Nội dung cũ',
            'document_file' => 'lesson-documents/current.pdf',
            'duration' => 60,
            'duration_seconds' => 60,
            'sort_order' => 1,
            'status' => 'draft',
        ]);

        $this->put(route('instructor.courses.lessons.update', [$course, $lesson]), [
            'title' => 'Tài liệu đã cập nhật',
            'type' => 'document',
            'content' => 'Nội dung mới',
            'duration' => 90,
            'sort_order' => 1,
            'status' => 'published',
        ])->assertRedirect();

        $lesson->refresh();

        $this->assertSame('lesson-documents/current.pdf', $lesson->document_file);
        Storage::disk('public')->assertExists('lesson-documents/current.pdf');
    }

    public function test_instructor_cannot_modify_other_instructors_curriculum(): void
    {
        $owner = User::factory()->create(['role' => 'instructor']);
        $other = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($owner);

        $this->actingAs($other)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
                'title' => 'Không được phép',
                'type' => 'video',
                'video_url' => 'https://example.com/video.mp4',
                'duration' => 60,
                'sort_order' => 1,
                'status' => 'draft',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_update_and_delete_their_lesson(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài học gốc',
            'type' => Lesson::TYPE_DOCUMENT,
            'content' => 'Nội dung gốc',
            'duration' => 60,
            'duration_seconds' => 60,
            'sort_order' => 1,
            'status' => Lesson::STATUS_DRAFT,
        ]);

        $this->put(route('instructor.courses.lessons.update', [$course, $lesson]), [
            'title' => 'Bài học đã sửa',
            'type' => Lesson::TYPE_DOCUMENT,
            'content' => 'Nội dung mới',
            'duration' => 90,
            'sort_order' => 1,
            'status' => Lesson::STATUS_DRAFT,
        ])->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'title' => 'Bài học đã sửa',
        ]);

        $this->delete(route('instructor.courses.lessons.destroy', [$course, $lesson]))
            ->assertRedirect();
        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);
    }

    public function test_owner_can_update_lesson_when_course_id_is_stale_but_section_matches(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        [$otherCourse] = $this->courseWithSection($instructor);
        $lesson = Lesson::create([
            'course_id' => $otherCourse->id,
            'section_id' => $section->id,
            'title' => 'Bài lệch course_id',
            'type' => Lesson::TYPE_DOCUMENT,
            'content' => 'Nội dung',
            'duration' => 30,
            'duration_seconds' => 30,
            'sort_order' => 1,
            'status' => Lesson::STATUS_DRAFT,
        ]);

        $this->put(route('instructor.courses.lessons.update', [$course, $lesson]), [
            'title' => 'Đã sửa bài lệch',
            'type' => Lesson::TYPE_DOCUMENT,
            'content' => 'Nội dung đã sửa',
            'duration' => 45,
            'sort_order' => 1,
            'status' => Lesson::STATUS_DRAFT,
        ])->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'title' => 'Đã sửa bài lệch',
        ]);
    }

    public function test_student_and_non_owner_cannot_update_or_delete_lesson(): void
    {
        $owner = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($owner);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Bài bị bảo vệ',
            'type' => Lesson::TYPE_DOCUMENT,
            'content' => 'Nội dung',
            'duration' => 20,
            'duration_seconds' => 20,
            'sort_order' => 1,
            'status' => Lesson::STATUS_DRAFT,
        ]);

        $other = User::factory()->create([
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($other)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('instructor.courses.lessons.update', [$course, $lesson]), [
                'title' => 'Xâm nhập',
                'type' => Lesson::TYPE_DOCUMENT,
                'content' => 'Không được',
                'duration' => 20,
            ])
            ->assertForbidden();

        $this->actingAs($other)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->delete(route('instructor.courses.lessons.destroy', [$course, $lesson]))
            ->assertForbidden();

        $student = User::factory()->create([
            'role' => 'student',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($student)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('instructor.courses.lessons.update', [$course, $lesson]), [
                'title' => 'Học viên sửa',
                'type' => Lesson::TYPE_DOCUMENT,
                'content' => 'Không được',
                'duration' => 20,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'title' => 'Bài bị bảo vệ',
        ]);
    }

    public function test_video_lesson_can_be_created_with_pending_s3_upload_flag(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);

        $this->post(route('instructor.courses.sections.lessons.store', [$course, $section]), [
            'title' => 'Video chờ tải S3',
            'type' => Lesson::TYPE_VIDEO,
            'pending_video_upload' => '1',
            'video_original_name' => 'bai-giang.mp4',
            'video_mime' => 'video/mp4',
            'video_size' => 2048,
            'duration' => 120,
            'sort_order' => 1,
            'status' => Lesson::STATUS_DRAFT,
        ])->assertRedirect();

        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Video chờ tải S3',
            'type' => Lesson::TYPE_VIDEO,
            'upload_status' => 'pending',
            'processing_status' => 'pending',
        ]);
    }

    public function test_section_create_and_update_keep_plain_text_description_without_code_leak(): void
    {
        $instructor = $this->signInInstructor();
        [$course] = $this->courseWithSection($instructor);

        $this->post(route('instructor.courses.sections.store', $course), [
            'title' => '  Chương mô tả  ',
            'description' => '  Nội dung chương bằng văn bản thuần.  ',
        ])->assertRedirect();

        $section = CourseSection::where('course_id', $course->id)
            ->where('title', 'Chương mô tả')
            ->firstOrFail();

        $this->assertSame('Nội dung chương bằng văn bản thuần.', $section->description);
        $this->get(route('instructor.courses.curriculum', $course))
            ->assertOk()
            ->assertSeeText('Nội dung chương bằng văn bản thuần.');

        $this->put(route('instructor.courses.sections.update', [$course, $section]), [
            'title' => 'Chương mô tả đã sửa',
            'description' => 'Mô tả sau chỉnh sửa.',
        ])->assertRedirect();

        $this->assertDatabaseHas('course_sections', [
            'id' => $section->id,
            'title' => 'Chương mô tả đã sửa',
            'description' => 'Mô tả sau chỉnh sửa.',
        ]);
        $this->get(route('instructor.courses.curriculum', $course))
            ->assertOk()
            ->assertSeeText('Mô tả sau chỉnh sửa.');
    }

    public function test_section_description_rejects_markup_and_legacy_blade_source_is_not_rendered(): void
    {
        $instructor = $this->signInInstructor();
        [$course, $section] = $this->courseWithSection($instructor);
        $rawBlade = '<div class="mt-4">@error(\'title\') raw form source @enderror</div>';

        $this->post(route('instructor.courses.sections.store', $course), [
            'title' => 'Chương không hợp lệ',
            'description' => $rawBlade,
        ])
            ->assertRedirect()
            ->assertSessionHasErrors(['description'], null, 'storeSection');

        $this->assertDatabaseMissing('course_sections', [
            'course_id' => $course->id,
            'title' => 'Chương không hợp lệ',
        ]);

        $section->forceFill(['description' => $rawBlade])->save();

        $this->get(route('instructor.courses.curriculum', $course))
            ->assertOk()
            ->assertDontSee("@error('title')", false)
            ->assertSeeText('Mô tả cũ chứa dữ liệu không hợp lệ. Hãy nhập lại mô tả bằng văn bản thuần.');
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
            'status' => true,
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
