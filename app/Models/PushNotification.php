<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushNotification extends Model
{
    protected $fillable = [
        'user_id',
        'created_by',
        'title',
        'message',
        'type',
        'url',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsRead(): void
    {
        if ($this->is_read) {
            return;
        }

        $this->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'announcement' => 'Thông báo chung',
            'badge_unlocked' => 'Huy hiệu',
            'discussion_reply' => 'Trao đổi',
            'new_discussion' => 'Trao đổi mới',
            'course_approved' => 'Khóa học duyệt',
            'course_rejected' => 'Khóa học từ chối',
            'new_enrollment' => 'Ghi danh mới',
            'order_paid' => 'Thanh toán',
            'withdrawal_requested' => 'Yêu cầu rút tiền',
            'certificate_issued' => 'Chứng chỉ',
            'course_review_created' => 'Đánh giá mới',
            'course_review_replied', 'review_reply' => 'Phản hồi đánh giá',
            'course_review_moderated' => 'Kiểm duyệt đánh giá',
            'assignment_graded' => 'Kết quả bài tập',
            'course_lesson_created' => 'Bài học mới',
            'course_lesson_updated' => 'Cập nhật bài học',
            'course_video_updated' => 'Cập nhật video',
            'study_group' => 'Nhóm học tập',
            'study_group_invitation' => 'Lời mời tham gia nhóm',
            'quiz_attempt_granted' => 'Cấp lại lượt Quiz',
            'quiz_attempt_rejected' => 'Từ chối cấp lại Quiz',
            default => 'Thông báo',
        };
    }

    public function typeColor(): string
    {
        return match ($this->type) {
            'announcement' => 'bg-blue-100 text-blue-700',
            'badge_unlocked' => 'bg-amber-100 text-amber-700',
            'discussion_reply', 'new_discussion' => 'bg-violet-100 text-violet-700',
            'course_approved' => 'bg-emerald-100 text-emerald-700',
            'course_rejected' => 'bg-red-100 text-red-700',
            'new_enrollment' => 'bg-cyan-100 text-cyan-700',
            'order_paid' => 'bg-indigo-100 text-indigo-700',
            'withdrawal_requested' => 'bg-amber-100 text-amber-700',
            'certificate_issued' => 'bg-teal-100 text-teal-700',
            'course_review_created', 'course_review_replied' => 'bg-amber-100 text-amber-700',
            'review_reply' => 'bg-emerald-100 text-emerald-700',
            'course_review_moderated' => 'bg-fuchsia-100 text-fuchsia-700',
            'assignment_graded' => 'bg-indigo-100 text-indigo-700',
            'course_lesson_created' => 'bg-emerald-100 text-emerald-700',
            'course_lesson_updated' => 'bg-blue-100 text-blue-700',
            'course_video_updated' => 'bg-purple-100 text-purple-700',
            'study_group' => 'bg-blue-100 text-blue-700',
            'study_group_invitation' => 'bg-indigo-100 text-indigo-700',
            'quiz_attempt_granted' => 'bg-emerald-100 text-emerald-700',
            'quiz_attempt_rejected' => 'bg-rose-100 text-rose-700',
            default => 'bg-slate-100 text-slate-700',
        };
    }
}
