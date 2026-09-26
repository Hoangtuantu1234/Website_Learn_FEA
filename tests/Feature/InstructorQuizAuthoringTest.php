<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\QuizContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstructorQuizAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_lazily_creates_one_inactive_shell_and_explains_save_requirements(): void
    {
        [$instructor, $course, $lesson] = $this->authoringContext();

        $this->actingAs($instructor)
            ->get(route('instructor.courses.lessons.quiz.show', [$course, $lesson]))
            ->assertOk()
            ->assertSee('Quiz chưa sẵn sàng để bật hoặc gửi duyệt vì còn thiếu:')
            ->assertSee('chưa đủ 5 câu hỏi');

        $this->actingAs($instructor)
            ->get(route('instructor.courses.lessons.quiz.show', [$course, $lesson]))
            ->assertOk();

        $this->assertSame(1, Quiz::where('lesson_id', $lesson->id)->count());
        $this->assertFalse(Quiz::where('lesson_id', $lesson->id)->firstOrFail()->is_active);
    }

    public function test_manual_question_option_mutations_delegate_to_shared_rules(): void
    {
        [$instructor, $course, $lesson] = $this->authoringContext();
        $quiz = app(QuizContentService::class)->getOrCreateForLesson($lesson);

        $this->actingAs($instructor)
            ->post(route('instructor.quizzes.questions.store', $quiz), [
                'question_text' => 'What is PHP?',
                'question_type' => 'single_choice',
                'score' => 2,
            ])
            ->assertSessionHasNoErrors();

        $single = $quiz->questions()->firstOrFail();
        $this->assertSame(QuizQuestion::TYPE_SINGLE, $single->type);

        foreach ([['PHP', true], ['Laravel', false], ['Symfony', false]] as $index => [$text, $correct]) {
            $this->actingAs($instructor)
                ->post(route('instructor.quiz-questions.answers.store', $single), [
                    'answer_text' => $text,
                    'is_correct' => $correct,
                    'sort_order' => $index,
                    'target_question_id' => $single->id,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($instructor)
            ->from(route('instructor.courses.lessons.quiz.show', [$course, $lesson]))
            ->post(route('instructor.quiz-questions.answers.store', $single), [
                'answer_text' => ' php ',
                'target_question_id' => $single->id,
            ])
            ->assertSessionHasErrors('answer_text');

        $this->actingAs($instructor)
            ->post(route('instructor.quizzes.questions.store', $quiz), [
                'question_text' => 'The statement is true?',
                'question_type' => 'true_false',
                'score' => 1,
            ])
            ->assertSessionHasNoErrors();

        $trueFalse = $quiz->questions()->where('type', QuizQuestion::TYPE_TRUE_FALSE)->firstOrFail();
        $this->assertSame(['Đúng', 'Sai'], $trueFalse->options->pluck('option_text')->all());

        $this->actingAs($instructor)
            ->put(route('instructor.quiz-questions.update', $single), [
                'question_text' => $single->question,
                'question_type' => 'true_false',
                'score' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(QuizQuestion::TYPE_TRUE_FALSE, $single->refresh()->type);
        $this->assertCount(2, $single->options);
    }

    public function test_metadata_save_uses_shared_completeness_and_can_activate_only_complete_quiz(): void
    {
        [$instructor, $course, $lesson] = $this->authoringContext();
        $quiz = app(QuizContentService::class)->getOrCreateForLesson($lesson);

        $this->actingAs($instructor)
            ->post(route('instructor.courses.lessons.quiz.store', [$course, $lesson]), [
                'title' => 'Chapter quiz',
                'pass_score' => 70,
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('quiz');

        for ($index = 1; $index <= 5; $index++) {
            $question = app(QuizContentService::class)->createQuestion($quiz->fresh(), [
                'question_text' => 'Question '.$index,
                'question_type' => QuizQuestion::TYPE_SINGLE,
                'score' => 1,
                'sort_order' => $index - 1,
            ]);

            foreach ([['A', true], ['B', false], ['C', false]] as $sortOrder => [$text, $correct]) {
                app(QuizContentService::class)->createOption($question, [
                    'answer_text' => $text.$index,
                    'is_correct' => $correct,
                    'sort_order' => $sortOrder,
                ]);
            }
        }

        $this->actingAs($instructor)
            ->post(route('instructor.courses.lessons.quiz.store', [$course, $lesson]), [
                'title' => ' Chapter quiz ',
                'description' => ' Description ',
                'pass_score' => 70,
                'time_limit_minutes' => 60,
                'max_attempts' => 3,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $quiz->refresh();
        $this->assertSame('Chapter quiz', $quiz->title);
        $this->assertSame('Description', $quiz->description);
        $this->assertTrue($quiz->is_active);
    }

    public function test_instructor_can_attach_an_image_to_a_versioned_question(): void
    {
        Storage::fake('public');
        [$instructor, , $lesson] = $this->authoringContext();
        $quiz = app(QuizContentService::class)->getOrCreateForLesson($lesson);

        $this->actingAs($instructor)
            ->post(route('instructor.quizzes.questions.store', $quiz), [
                'question_text' => 'Identify this diagram',
                'question_type' => 'single_choice',
                'score' => 1,
                'question_image' => UploadedFile::fake()->image('diagram.png', 800, 600),
            ])
            ->assertSessionHasNoErrors();

        $question = $quiz->questions()->firstOrFail();
        $version = $question->versions()->firstOrFail();
        $this->assertNotNull($version->image_path);
        Storage::disk('public')->assertExists($version->image_path);
    }

    public function test_owner_can_update_question_explanation_and_answers(): void
    {
        [$instructor, $course, $lesson] = $this->authoringContext();
        $quiz = app(QuizContentService::class)->getOrCreateForLesson($lesson);
        $question = app(QuizContentService::class)->createQuestion($quiz, [
            'question_text' => 'Câu hỏi gốc',
            'question_type' => QuizQuestion::TYPE_SINGLE,
            'score' => 1,
        ], [
            ['option_text' => 'A', 'is_correct' => true, 'sort_order' => 0],
            ['option_text' => 'B', 'is_correct' => false, 'sort_order' => 1],
        ]);
        $answer = $question->options()->firstOrFail();

        $this->actingAs($instructor)
            ->put(route('instructor.quiz-questions.update', $question), [
                'question_text' => 'Câu hỏi đã sửa',
                'question_type' => 'single_choice',
                'score' => 2,
                'explanation' => 'Đây là bài giải.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($instructor)
            ->put(route('instructor.quiz-answers.update', $answer), [
                'answer_text' => 'Đáp án đã sửa',
                'is_correct' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Đây là bài giải.', $question->fresh()->explanation);
        $this->assertSame('Đáp án đã sửa', $answer->fresh()->option_text);
    }

    public function test_non_owner_and_student_cannot_mutate_quiz_answers(): void
    {
        [$instructor, , $lesson] = $this->authoringContext();
        $quiz = app(QuizContentService::class)->getOrCreateForLesson($lesson);
        $question = app(QuizContentService::class)->createQuestion($quiz, [
            'question_text' => 'Câu hỏi bảo vệ',
            'question_type' => QuizQuestion::TYPE_SINGLE,
            'score' => 1,
        ], [
            ['option_text' => 'A', 'is_correct' => true, 'sort_order' => 0],
        ]);
        $answer = $question->options()->firstOrFail();

        $intruder = User::factory()->create([
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($intruder)
            ->put(route('instructor.quiz-questions.update', $question), [
                'question_text' => 'IDOR',
                'question_type' => 'single_choice',
                'score' => 1,
            ])
            ->assertForbidden();
        $this->actingAs($intruder)
            ->put(route('instructor.quiz-answers.update', $answer), [
                'answer_text' => 'IDOR',
                'is_correct' => true,
            ])
            ->assertForbidden();

        $student = User::factory()->create([
            'role' => 'student',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($student)
            ->put(route('instructor.quiz-answers.update', $answer), [
                'answer_text' => 'Student',
                'is_correct' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Câu hỏi bảo vệ', $question->fresh()->question);
        $this->assertSame('A', $answer->fresh()->option_text);
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function authoringContext(): array
    {
        $instructor = User::factory()->create([
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Quiz '.uniqid(), 'slug' => 'quiz-'.uniqid(), 'status' => true]);
        $course = Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Quiz authoring',
            'slug' => 'quiz-authoring-'.uniqid(),
            'description' => 'Description',
            'price' => 0,
            'language' => 'vi',
            'status' => Course::STATUS_DRAFT,
        ]);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'sort_order' => 0]);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Quiz lesson',
            'type' => Lesson::TYPE_QUIZ,
            'sort_order' => 0,
            'status' => Lesson::STATUS_DRAFT,
        ]);

        return [$instructor, $course, $lesson];
    }
}
