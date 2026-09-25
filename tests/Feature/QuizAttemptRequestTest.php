<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\InstructorProfile;
use App\Models\InstructorTeachingField;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptRequest;
use App\Models\QuizVersion;
use App\Models\User;
use App\Services\LearningPlayerService;
use App\Services\QuizAttemptService;
use App\Services\QuizContentService;
use App\Services\QuizVersioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizAttemptRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausted_student_is_locked_and_can_request_attempt(): void
    {
        [$instructor, $student, $course, $lesson, $quiz] = $this->setupCourseWithQuiz(3);

        // Học viên hoàn thành đủ 3 lần làm bài
        $this->createCompletedAttempts($student, $quiz, 3);

        $attemptService = app(QuizAttemptService::class);
        $availability = $attemptService->attemptAvailability($quiz, $student);

        // 1. Kiểm tra học viên bị khóa khi làm đủ 3/3 lượt
        $this->assertSame(3, $availability['attempts_used']);
        $this->assertSame(3, $availability['max_attempts']);
        $this->assertSame(0, $availability['remaining_attempts']);
        $this->assertFalse($availability['has_remaining_attempts']);

        // Gọi API start sẽ bị chặn 422
        $this->actingAs($student)->postJson(route('courses.lessons.quiz.start', [$course, $lesson]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bạn đã hết số lần làm bài kiểm tra này.');

        // 2. Kiểm tra context Learning Player hiển thị nút Yêu cầu cấp lại lượt Quiz
        $playerContext = app(LearningPlayerService::class)->buildPlayerContext($course, $lesson, $student, false);
        $this->assertTrue($playerContext['quizContext']['attempt_limit_reached']);
        $this->assertTrue($playerContext['quizContext']['can_request_attempt']);
        $this->assertFalse($playerContext['quizContext']['has_pending_request']);
    }

    public function test_student_cannot_request_attempt_when_still_has_remaining_attempts(): void
    {
        [$instructor, $student, $course, $lesson, $quiz] = $this->setupCourseWithQuiz(3);

        // Học viên mới làm 1 lần, vẫn còn 2 lần
        $this->createCompletedAttempts($student, $quiz, 1);

        $this->actingAs($student)->postJson(route('courses.lessons.quiz.request-attempt', [$course, $lesson]), [
            'reason' => 'Em muốn xin thêm lượt làm bài',
        ])->assertStatus(422)->assertJsonPath('message', 'Bạn vẫn còn lượt làm bài kiểm tra này.');

        $this->assertDatabaseCount('quiz_attempt_requests', 0);
    }

    public function test_student_can_submit_request_and_duplicate_pending_request_is_prevented(): void
    {
        [$instructor, $student, $course, $lesson, $quiz] = $this->setupCourseWithQuiz(3);
        $this->createCompletedAttempts($student, $quiz, 3);

        // 3. Gửi yêu cầu -> trạng thái "Chờ xử lý"
        $res = $this->actingAs($student)->postJson(route('courses.lessons.quiz.request-attempt', [$course, $lesson]), [
            'reason' => 'Em bị mất kết nối mạng ở lần thứ 2, xin thầy cấp thêm lượt ạ.',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Đã gửi yêu cầu cấp lại lượt Quiz. Vui lòng chờ giảng viên xử lý.')
            ->assertJsonPath('request.status', 'pending');

        $this->assertDatabaseHas('quiz_attempt_requests', [
            'user_id' => $student->id,
            'quiz_id' => $quiz->id,
            'status' => 'pending',
            'attempts_used' => 3,
            'max_attempts' => 3,
        ]);

        // 4. Không thể gửi yêu cầu trùng khi đang chờ xử lý
        $duplicateRes = $this->actingAs($student)->postJson(route('courses.lessons.quiz.request-attempt', [$course, $lesson]), [
            'reason' => 'Em gửi lại yêu cầu xin thêm lượt.',
        ]);

        $duplicateRes->assertStatus(422)
            ->assertJsonPath('message', 'Bạn đã có một yêu cầu đang chờ giảng viên xử lý.');

        $this->assertDatabaseCount('quiz_attempt_requests', 1);

        // Kiểm tra Learning Player chuyển sang trạng thái chờ xử lý
        $playerContext = app(LearningPlayerService::class)->buildPlayerContext($course, $lesson, $student, false);
        $this->assertTrue($playerContext['quizContext']['has_pending_request']);
        $this->assertFalse($playerContext['quizContext']['can_request_attempt']);
    }

    public function test_instructor_of_course_can_view_and_approve_request(): void
    {
        [$instructor, $student, $course, $lesson, $quiz] = $this->setupCourseWithQuiz(3);
        $this->createCompletedAttempts($student, $quiz, 3);

        $attemptRequest = QuizAttemptRequest::create([
            'user_id' => $student->id,
            'quiz_id' => $quiz->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'attempts_used' => 3,
            'max_attempts' => 3,
            'reason' => 'Xin cấp thêm lượt',
            'status' => QuizAttemptRequest::STATUS_PENDING,
        ]);

        // 5. Giảng viên đúng khóa học thấy yêu cầu
        $this->actingAs($instructor)->get(route('instructor.quiz-attempt-requests.index'))
            ->assertOk()
            ->assertSee($student->name)
            ->assertSee($course->title)
            ->assertSee('Chờ xử lý');

        // 6. Giảng viên duyệt -> học viên được thêm đúng 1 lượt
        $approveRes = $this->actingAs($instructor)->post(route('instructor.quiz-attempt-requests.approve', $attemptRequest), [
            'extra_attempts' => 1,
        ]);

        $approveRes->assertRedirect();
        $attemptRequest->refresh();
        $this->assertSame(QuizAttemptRequest::STATUS_APPROVED, $attemptRequest->status);
        $this->assertSame(1, $attemptRequest->extra_attempts_granted);
        $this->assertSame($instructor->id, $attemptRequest->reviewed_by);
        $this->assertNotNull($attemptRequest->reviewed_at);

        // Kiểm tra đã gửi thông báo đến học viên
        $this->assertDatabaseHas('push_notifications', [
            'user_id' => $student->id,
            'type' => 'quiz_attempt_granted',
        ]);

        // 7. Giảng viên không thể duyệt cùng một yêu cầu 2 lần
        $secondApprove = $this->actingAs($instructor)->post(route('instructor.quiz-attempt-requests.approve', $attemptRequest));
        $secondApprove->assertStatus(422);
    }

    public function test_approved_student_can_take_quiz_and_other_students_are_not_affected(): void
    {
        [$instructor, $studentA, $course, $lesson, $quiz] = $this->setupCourseWithQuiz(3);
        $studentB = User::factory()->create(['role' => 'student']);
        Enrollment::create(['user_id' => $studentB->id, 'course_id' => $course->id, 'status' => Enrollment::STATUS_ACTIVE, 'enrolled_at' => now()]);

        // Cả 2 học viên đều đã làm hết 3 lượt
        $this->createCompletedAttempts($studentA, $quiz, 3);
        $this->createCompletedAttempts($studentB, $quiz, 3);

        // Chỉ học viên A được giảng viên duyệt cấp thêm 1 lượt
        QuizAttemptRequest::create([
            'user_id' => $studentA->id,
            'quiz_id' => $quiz->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'attempts_used' => 3,
            'max_attempts' => 3,
            'reason' => 'Xin cấp lượt',
            'status' => QuizAttemptRequest::STATUS_APPROVED,
            'extra_attempts_granted' => 1,
            'reviewed_by' => $instructor->id,
            'reviewed_at' => now(),
        ]);

        $attemptService = app(QuizAttemptService::class);

        // Học viên A: có 3 + 1 = 4 lượt tối đa, đã dùng 3 -> còn 1 lượt
        $availabilityA = $attemptService->attemptAvailability($quiz, $studentA);
        $this->assertSame(3, $availabilityA['max_attempts']);
        $this->assertSame(1, $availabilityA['extra_attempts']);
        $this->assertSame(4, $availabilityA['effective_max_attempts']);
        $this->assertSame(1, $availabilityA['remaining_attempts']);
        $this->assertTrue($availabilityA['has_remaining_attempts']);

        // 8. Học viên khác (B) không được tăng lượt: vẫn 3 lượt, còn 0 lượt
        $availabilityB = $attemptService->attemptAvailability($quiz, $studentB);
        $this->assertSame(3, $availabilityB['max_attempts']);
        $this->assertSame(0, $availabilityB['extra_attempts']);
        $this->assertSame(3, $availabilityB['effective_max_attempts']);
        $this->assertSame(0, $availabilityB['remaining_attempts']);
        $this->assertFalse($availabilityB['has_remaining_attempts']);

        // 9. Kiểm tra 3 attempt cũ của học viên A vẫn giữ nguyên
        $this->assertSame(3, $quiz->attempts()->where('user_id', $studentA->id)->count());

        // Học viên A bắt đầu làm lần thứ 4 thành công
        $this->actingAs($studentA)->postJson(route('courses.lessons.quiz.start', [$course, $lesson]))->assertOk();

        // 10. Sau khi hoàn thành lần thứ 4, Quiz lại bị khóa
        $attempt4 = $quiz->attempts()->where('user_id', $studentA->id)->where('status', QuizAttempt::STATUS_IN_PROGRESS)->firstOrFail();
        $attempt4->update([
            'status' => QuizAttempt::STATUS_COMPLETED,
            'completed_at' => now(),
            'score' => 5,
            'total_score' => 5,
            'percent' => 100,
            'passed' => true,
        ]);

        $availabilityAAfter = $attemptService->attemptAvailability($quiz, $studentA);
        $this->assertSame(4, $availabilityAAfter['attempts_used']);
        $this->assertSame(4, $availabilityAAfter['effective_max_attempts']);
        $this->assertSame(0, $availabilityAAfter['remaining_attempts']);
        $this->assertFalse($availabilityAAfter['has_remaining_attempts']);
    }

    public function test_instructor_can_reject_request_with_reason(): void
    {
        [$instructor, $student, $course, $lesson, $quiz] = $this->setupCourseWithQuiz(3);
        $this->createCompletedAttempts($student, $quiz, 3);

        $attemptRequest = QuizAttemptRequest::create([
            'user_id' => $student->id,
            'quiz_id' => $quiz->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'attempts_used' => 3,
            'max_attempts' => 3,
            'reason' => 'Lý do xin cấp lượt',
            'status' => QuizAttemptRequest::STATUS_PENDING,
        ]);

        // Giảng viên từ chối
        $rejectRes = $this->actingAs($instructor)->post(route('instructor.quiz-attempt-requests.reject', $attemptRequest), [
            'rejection_reason' => 'Không đủ điều kiện cấp lại lượt.',
        ]);

        $rejectRes->assertRedirect();
        $attemptRequest->refresh();
        $this->assertSame(QuizAttemptRequest::STATUS_REJECTED, $attemptRequest->status);
        $this->assertSame('Không đủ điều kiện cấp lại lượt.', $attemptRequest->rejection_reason);
        $this->assertSame(0, $attemptRequest->extra_attempts_granted);

        // Kiểm tra đã gửi thông báo từ chối đến học viên
        $this->assertDatabaseHas('push_notifications', [
            'user_id' => $student->id,
            'type' => 'quiz_attempt_rejected',
        ]);

        // Học viên tiếp tục bị khóa vì không được cấp thêm lượt
        $availability = app(QuizAttemptService::class)->attemptAvailability($quiz, $student);
        $this->assertFalse($availability['has_remaining_attempts']);

        // Học viên thấy thông báo từ chối và có thể gửi yêu cầu mới
        $playerContext = app(LearningPlayerService::class)->buildPlayerContext($course, $lesson, $student, false);
        $this->assertFalse($playerContext['quizContext']['has_pending_request']);
        $this->assertTrue($playerContext['quizContext']['can_request_attempt']);
        $this->assertSame('rejected', $playerContext['quizContext']['latest_attempt_request']['status']);
    }

    public function test_other_instructors_cannot_review_requests_of_foreign_courses(): void
    {
        [$instructorA, $student, $courseA, $lessonA, $quizA] = $this->setupCourseWithQuiz(3);
        $otherInstructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);

        $attemptRequest = QuizAttemptRequest::create([
            'user_id' => $student->id,
            'quiz_id' => $quizA->id,
            'course_id' => $courseA->id,
            'lesson_id' => $lessonA->id,
            'attempts_used' => 3,
            'max_attempts' => 3,
            'reason' => 'Xin cấp lượt',
            'status' => QuizAttemptRequest::STATUS_PENDING,
        ]);

        // Giảng viên khác không thể duyệt
        $this->actingAs($otherInstructor)->post(route('instructor.quiz-attempt-requests.approve', $attemptRequest))
            ->assertStatus(403);

        // Giảng viên khác không thể từ chối
        $this->actingAs($otherInstructor)->post(route('instructor.quiz-attempt-requests.reject', $attemptRequest))
            ->assertStatus(403);
    }

    public function test_multiple_requests_for_same_quiz_are_grouped_and_display_request_number(): void
    {
        [$instructor, $student, $course, $lesson, $quiz] = $this->setupCourseWithQuiz(3);

        // Yêu cầu lần 1 (đã duyệt)
        $req1 = QuizAttemptRequest::create([
            'user_id' => $student->id,
            'quiz_id' => $quiz->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'attempts_used' => 3,
            'max_attempts' => 3,
            'reason' => 'Xin cấp lần 1',
            'status' => QuizAttemptRequest::STATUS_APPROVED,
            'extra_attempts_granted' => 1,
            'reviewed_by' => $instructor->id,
            'reviewed_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(15),
        ]);

        // Yêu cầu lần 2 (đang chờ xử lý)
        $req2 = QuizAttemptRequest::create([
            'user_id' => $student->id,
            'quiz_id' => $quiz->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'attempts_used' => 4,
            'max_attempts' => 3,
            'reason' => 'Xin cấp lần 2 do mất mạng',
            'status' => QuizAttemptRequest::STATUS_PENDING,
            'created_at' => now(),
        ]);

        // Giảng viên xem danh sách -> chỉ thấy 1 dòng đại diện cho quiz này (yêu cầu mới nhất)
        $response = $this->actingAs($instructor)->get(route('instructor.quiz-attempt-requests.index'));
        $response->assertOk();
        $response->assertSee('Yêu cầu cấp lần 2');
        $response->assertSee('Xin cấp lần 2 do mất mạng');
        // Không lặp dòng lý do của lần 1 ngoài bảng chính
        $response->assertDontSee('Xin cấp lần 1');
    }

    /**
     * @return array{0: User, 1: User, 2: Course, 3: Lesson, 4: Quiz}
     */
    private function setupCourseWithQuiz(int $maxAttempts = 3): array
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'instructor_status' => 'approved']);
        $category = Category::create(['name' => 'IT Category '.uniqid(), 'slug' => 'it-cat-'.uniqid(), 'status' => true]);
        $profile = InstructorProfile::create(['user_id' => $instructor->id, 'category_id' => $category->id]);
        $profile->teachingCategories()->attach($category->id, [
            'is_primary' => true,
            'approval_status' => InstructorTeachingField::STATUS_APPROVED,
        ]);

        $course = Course::create([
            'user_id' => $instructor->id,
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Test Course '.uniqid(),
            'slug' => 'course-'.uniqid(),
            'description' => 'Course description',
            'price' => 0,
            'language' => 'vi',
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Chương 1', 'sort_order' => 0]);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Quiz 1',
            'type' => Lesson::TYPE_QUIZ,
            'sort_order' => 1,
            'status' => Lesson::STATUS_PUBLISHED,
        ]);

        $content = app(QuizContentService::class);
        $quiz = $content->getOrCreateForLesson($lesson);
        $content->saveMetadata($lesson, [
            'title' => 'Quiz Metadata',
            'description' => 'Test description',
            'pass_score' => 70,
            'time_limit_minutes' => 15,
            'max_attempts' => $maxAttempts,
        ], false);

        foreach (range(1, 5) as $idx) {
            $content->createQuestion($quiz->fresh(), [
                'question_text' => 'Câu hỏi '.$idx,
                'question_type' => 'single',
                'score' => 1,
                'sort_order' => $idx - 1,
            ], [
                ['option_text' => 'Đáp án đúng '.$idx, 'is_correct' => true, 'sort_order' => 0],
                ['option_text' => 'Đáp án sai '.$idx, 'is_correct' => false, 'sort_order' => 1],
                ['option_text' => 'Đáp án khác '.$idx, 'is_correct' => false, 'sort_order' => 2],
                ['option_text' => 'Đáp án phụ '.$idx, 'is_correct' => false, 'sort_order' => 3],
            ]);
        }

        $quiz->update(['is_active' => true, 'max_attempts' => $maxAttempts]);
        app(QuizVersioningService::class)->publishDraft($quiz->fresh(), app(QuizVersioningService::class)->currentDraft($quiz->fresh()));

        $student = User::factory()->create(['role' => 'student']);
        Enrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => Enrollment::STATUS_ACTIVE,
            'enrolled_at' => now(),
        ]);

        return [$instructor, $student, $course->fresh(), $lesson->fresh(), $quiz->fresh()];
    }

    private function createCompletedAttempts(User $student, Quiz $quiz, int $count): void
    {
        $version = app(QuizVersioningService::class)->currentPublished($quiz);
        for ($i = 1; $i <= $count; $i++) {
            QuizAttempt::create([
                'user_id' => $student->id,
                'quiz_id' => $quiz->id,
                'quiz_version_id' => $version->id,
                'status' => QuizAttempt::STATUS_COMPLETED,
                'score' => 2,
                'total_score' => 3,
                'percent' => 66.67,
                'passed' => false,
                'started_at' => now()->subHours($count - $i + 1),
                'completed_at' => now()->subHours($count - $i),
            ]);
        }
    }
}
