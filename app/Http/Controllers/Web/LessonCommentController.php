<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\StoreLessonCommentRequest;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LessonCommentController extends Controller
{
    /**
     * Get all comments for a lesson and render response.
     */
    private function renderCommentsResponse(Lesson $lesson, string $message = ''): array
    {
        $user = auth()->user();
        $course = $lesson->course;

        $isOwnerInstructor = $user && $user->isInstructor() && (int) $course->instructor_id === (int) $user->id;
        $isAdmin = $user && $user->isAdmin();
        $isEnrolled = $user && Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->withLearningAccess()
            ->exists();
        $canComment = ($user && $user->isStudent() && $isEnrolled) || $isOwnerInstructor || $isAdmin;

        $commentsQuery = LessonComment::where('lesson_id', $lesson->id)
            ->whereNull('parent_id');

        if ($isOwnerInstructor || $isAdmin) {
            $comments = $commentsQuery->with(['user', 'replies' => function ($q) {
                $q->with('user')->oldest();
            }])->latest()->get();
        } else {
            $comments = $commentsQuery->where('is_hidden', false)
                ->with(['user', 'replies' => function ($q) {
                    $q->where('is_hidden', false)->with('user')->oldest();
                }])->latest()->get();
        }

        $html = view('components.learning.lesson-comments-list', [
            'lesson' => $lesson,
            'course' => $course,
            'comments' => $comments,
            'user' => $user,
            'isInstructor' => $isOwnerInstructor,
            'isAdmin' => $isAdmin,
            'canComment' => $canComment,
            'isEnrolled' => $isEnrolled,
        ])->render();

        return [
            'count' => $comments->count(),
            'html' => $html,
            'message' => $message,
        ];
    }

    /**
     * Display a listing of lesson comments for polling/real-time refresh.
     */
    public function index(Request $request, Lesson $lesson)
    {
        $rendered = $this->renderCommentsResponse($lesson);

        return response()->json([
            'success' => true,
            'count' => $rendered['count'],
            'html' => $rendered['html'],
        ]);
    }

    /**
     * Store a new comment or reply for a lesson.
     */
    public function store(StoreLessonCommentRequest $request, Lesson $lesson)
    {
        $parentId = $request->validated('parent_id');

        if ($parentId) {
            $parentComment = LessonComment::findOrFail($parentId);
            Gate::authorize('reply', $parentComment);

            // Đảm bảo parent_id thuộc về lesson này
            abort_unless((int) $parentComment->lesson_id === (int) $lesson->id, 400, 'Invalid parent comment for this lesson.');
        } else {
            Gate::authorize('create', [LessonComment::class, $lesson]);
        }

        $comment = LessonComment::create([
            'lesson_id' => $lesson->id,
            'user_id' => auth()->id(),
            'parent_id' => $parentId,
            'content' => $request->validated('content'),
            'is_hidden' => false,
        ]);

        $comment->load('user');

        if ($request->wantsJson() || $request->ajax()) {
            $rendered = $this->renderCommentsResponse($lesson, 'Đã gửi bình luận thành công.');

            return response()->json([
                'success' => true,
                'message' => $rendered['message'],
                'count' => $rendered['count'],
                'html' => $rendered['html'],
                'comment' => [
                    'id' => $comment->id,
                    'lesson_id' => $comment->lesson_id,
                    'user_id' => $comment->user_id,
                    'parent_id' => $comment->parent_id,
                    'content' => $comment->content,
                    'is_hidden' => $comment->is_hidden,
                    'created_at_human' => $comment->created_at->diffForHumans(),
                    'user_name' => $comment->user ? $comment->user->name : 'NĐT',
                    'user_avatar' => $comment->user ? $comment->user->avatarUrl() : 'https://ui-avatars.com/api/?name=User',
                    'is_instructor' => $comment->user && $comment->user->isInstructor() && (int) $lesson->course->instructor_id === (int) $comment->user->id,
                    'is_admin' => $comment->user && $comment->user->isAdmin(),
                ],
            ]);
        }

        return back()->with('success', 'Đã gửi bình luận thành công.');
    }

    /**
     * Toggle hide state on a lesson comment.
     */
    public function toggleHide(Request $request, LessonComment $comment)
    {
        Gate::authorize('toggleHide', $comment);

        $comment->is_hidden = ! $comment->is_hidden;
        $comment->save();

        $message = $comment->is_hidden ? 'Đã ẩn bình luận.' : 'Đã bỏ ẩn bình luận.';

        if ($request->wantsJson() || $request->ajax()) {
            $rendered = $this->renderCommentsResponse($comment->lesson, $message);

            return response()->json([
                'success' => true,
                'is_hidden' => $comment->is_hidden,
                'message' => $message,
                'count' => $rendered['count'],
                'html' => $rendered['html'],
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Update the specified lesson comment.
     */
    public function update(StoreLessonCommentRequest $request, LessonComment $comment)
    {
        Gate::authorize('update', $comment);

        $comment->update([
            'content' => $request->validated('content'),
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            $rendered = $this->renderCommentsResponse($comment->lesson, 'Đã cập nhật bình luận.');

            return response()->json([
                'success' => true,
                'message' => 'Đã cập nhật bình luận.',
                'content' => $comment->content,
                'count' => $rendered['count'],
                'html' => $rendered['html'],
            ]);
        }

        return back()->with('success', 'Đã cập nhật bình luận.');
    }

    /**
     * Remove the specified lesson comment.
     */
    public function destroy(Request $request, LessonComment $comment)
    {
        Gate::authorize('delete', $comment);

        $lesson = $comment->lesson;
        $comment->delete();

        if ($request->wantsJson() || $request->ajax()) {
            $rendered = $this->renderCommentsResponse($lesson, 'Đã xóa bình luận.');

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa bình luận.',
                'count' => $rendered['count'],
                'html' => $rendered['html'],
            ]);
        }

        return back()->with('success', 'Đã xóa bình luận.');
    }
}
