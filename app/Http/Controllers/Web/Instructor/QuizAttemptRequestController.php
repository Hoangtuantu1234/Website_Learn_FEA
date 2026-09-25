<?php

namespace App\Http\Controllers\Web\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\QuizAttemptRequest;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class QuizAttemptRequestController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $isInstructorOrAdmin = $user && ($user->isInstructor() || $user->isAdmin());
        abort_unless($isInstructorOrAdmin, 403, 'Bạn không có quyền truy cập trang này.');

        $ownedCourses = $user->isAdmin()
            ? Course::query()->select('id', 'title')->orderBy('title')->get()
            : Course::query()->where('instructor_id', $user->id)->select('id', 'title')->orderBy('title')->get();

        $courseIds = $ownedCourses->pluck('id')->all();

        // Lấy ID mới nhất cho mỗi cặp (user_id, quiz_id) để không hiển thị lặp nhiều dòng cho cùng 1 quiz
        $latestIds = QuizAttemptRequest::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('user_id', 'quiz_id');

        $query = QuizAttemptRequest::query()
            ->whereIn('id', $latestIds)
            ->whereIn('course_id', $courseIds)
            ->with(['user', 'course', 'quiz', 'lesson', 'reviewer']);

        if ($request->filled('course_id') && in_array((int) $request->input('course_id'), $courseIds, true)) {
            $query->where('course_id', (int) $request->input('course_id'));
        }

        if ($request->filled('status') && in_array($request->input('status'), [
            QuizAttemptRequest::STATUS_PENDING,
            QuizAttemptRequest::STATUS_APPROVED,
            QuizAttemptRequest::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $pendingCount = QuizAttemptRequest::query()
            ->whereIn('course_id', $courseIds)
            ->where('status', QuizAttemptRequest::STATUS_PENDING)
            ->count();

        $requests = $query
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        // Gắn số lần yêu cầu (request_number) và toàn bộ lịch sử các lần xin cho mỗi cặp (user_id, quiz_id)
        if ($requests->isNotEmpty()) {
            $userQuizPairs = $requests->map(fn ($r) => ['user_id' => $r->user_id, 'quiz_id' => $r->quiz_id])->unique();

            $allRequestsForPairs = QuizAttemptRequest::query()
                ->where(function ($q) use ($userQuizPairs) {
                    foreach ($userQuizPairs as $pair) {
                        $q->orWhere(function ($sub) use ($pair) {
                            $sub->where('user_id', $pair['user_id'])->where('quiz_id', $pair['quiz_id']);
                        });
                    }
                })
                ->with('reviewer')
                ->orderBy('id', 'asc')
                ->get()
                ->groupBy(fn ($item) => "{$item->user_id}_{$item->quiz_id}");

            foreach ($requests as $req) {
                $key = "{$req->user_id}_{$req->quiz_id}";
                $group = $allRequestsForPairs->get($key, collect([$req]));
                $req->total_requests = $group->count();
                $index = $group->search(fn ($item) => $item->id === $req->id);
                $req->request_number = $index !== false ? ($index + 1) : $group->count();
                $req->request_history = $group->sortByDesc('id')->values()->map(function ($hist, $histIdx) use ($group) {
                    return [
                        'id' => $hist->id,
                        'request_number' => $group->count() - $histIdx,
                        'reason' => $hist->reason,
                        'status' => $hist->status,
                        'status_label' => $hist->getStatusLabel(),
                        'status_badge_class' => $hist->getStatusBadgeClass(),
                        'extra_attempts_granted' => $hist->extra_attempts_granted,
                        'rejection_reason' => $hist->rejection_reason,
                        'reviewed_by_name' => $hist->reviewer?->name,
                        'reviewed_at' => $hist->reviewed_at?->format('d/m/Y H:i'),
                        'created_at' => $hist->created_at->format('d/m/Y H:i'),
                    ];
                })->all();
            }
        }

        return view('instructor.quiz-attempt-requests.index', [
            'requests' => $requests,
            'ownedCourses' => $ownedCourses,
            'pendingCount' => $pendingCount,
        ]);
    }

    public function approve(Request $request, QuizAttemptRequest $attemptRequest): RedirectResponse
    {
        $user = $request->user();
        $course = $attemptRequest->course;

        abort_unless($course && ($course->isOwnedBy($user) || ($user && $user->isAdmin())), 403, 'Bạn không có quyền xử lý yêu cầu của khóa học này.');
        abort_unless($attemptRequest->isPending(), 422, 'Yêu cầu này đã được xử lý trước đó.');

        $request->validate([
            'extra_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $extraAttempts = (int) ($request->input('extra_attempts') ?: 1);

        DB::transaction(function () use ($attemptRequest, $extraAttempts, $user) {
            $attemptRequest->update([
                'status' => QuizAttemptRequest::STATUS_APPROVED,
                'extra_attempts_granted' => $extraAttempts,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);
        });

        // Gửi thông báo đến học viên khi được cấp lại lượt Quiz
        try {
            $student = $attemptRequest->user;
            if ($student) {
                $lesson = $attemptRequest->lesson;
                $quiz = $attemptRequest->quiz;
                $quizTitle = $lesson?->title ?? $quiz?->title ?? 'Bài Quiz';
                $courseTitle = $course?->title ?? 'Khóa học';
                $quizUrl = ($course && $lesson)
                    ? route('courses.lessons.show', [$course, $lesson])
                    : ($course ? route('courses.show', $course->slug ?? $course->id) : null);

                $requestNumber = QuizAttemptRequest::query()
                    ->where('quiz_id', $attemptRequest->quiz_id)
                    ->where('user_id', $attemptRequest->user_id)
                    ->where('id', '<=', $attemptRequest->id)
                    ->count();
                $roundText = $requestNumber > 1 ? " (Yêu cầu cấp lần {$requestNumber})" : "";

                app(NotificationService::class)->send(
                    $student,
                    'Yêu cầu cấp lại lượt làm Quiz đã được duyệt',
                    "Giảng viên đã duyệt cấp thêm {$extraAttempts} lượt làm bài cho Quiz \"{$quizTitle}\" (Khóa học: {$courseTitle}){$roundText}. Bạn có thể vào làm bài ngay bây giờ.",
                    'quiz_attempt_granted',
                    $quizUrl
                );
            }
        } catch (\Throwable $e) {
            Log::error('Gửi thông báo duyệt cấp lại lượt Quiz thất bại: ' . $e->getMessage());
        }

        return back()->with('success', "Đã duyệt cấp thêm {$extraAttempts} lượt làm bài cho học viên {$attemptRequest->user?->name}.");
    }

    public function reject(Request $request, QuizAttemptRequest $attemptRequest): RedirectResponse
    {
        $user = $request->user();
        $course = $attemptRequest->course;

        abort_unless($course && ($course->isOwnedBy($user) || ($user && $user->isAdmin())), 403, 'Bạn không có quyền xử lý yêu cầu của khóa học này.');
        abort_unless($attemptRequest->isPending(), 422, 'Yêu cầu này đã được xử lý trước đó.');

        $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $rejectionReason = $request->input('rejection_reason');

        DB::transaction(function () use ($attemptRequest, $rejectionReason, $user) {
            $attemptRequest->update([
                'status' => QuizAttemptRequest::STATUS_REJECTED,
                'rejection_reason' => $rejectionReason,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);
        });

        // Gửi thông báo đến học viên khi bị từ chối cấp lại lượt Quiz
        try {
            $student = $attemptRequest->user;
            if ($student) {
                $lesson = $attemptRequest->lesson;
                $quiz = $attemptRequest->quiz;
                $quizTitle = $lesson?->title ?? $quiz?->title ?? 'Bài Quiz';
                $courseTitle = $course?->title ?? 'Khóa học';
                $quizUrl = ($course && $lesson)
                    ? route('courses.lessons.show', [$course, $lesson])
                    : ($course ? route('courses.show', $course->slug ?? $course->id) : null);

                $requestNumber = QuizAttemptRequest::query()
                    ->where('quiz_id', $attemptRequest->quiz_id)
                    ->where('user_id', $attemptRequest->user_id)
                    ->where('id', '<=', $attemptRequest->id)
                    ->count();
                $roundText = $requestNumber > 1 ? " (Lần {$requestNumber})" : "";

                $reasonText = $rejectionReason ? " Lý do: {$rejectionReason}" : '';

                app(NotificationService::class)->send(
                    $student,
                    'Yêu cầu cấp lại lượt làm Quiz bị từ chối',
                    "Yêu cầu xin cấp lại lượt làm bài Quiz \"{$quizTitle}\" (Khóa học: {$courseTitle}){$roundText} của bạn chưa được chấp thuận.{$reasonText}",
                    'quiz_attempt_rejected',
                    $quizUrl
                );
            }
        } catch (\Throwable $e) {
            Log::error('Gửi thông báo từ chối cấp lại lượt Quiz thất bại: ' . $e->getMessage());
        }

        return back()->with('success', "Đã từ chối yêu cầu cấp lại lượt của học viên {$attemptRequest->user?->name}.");
    }
}
