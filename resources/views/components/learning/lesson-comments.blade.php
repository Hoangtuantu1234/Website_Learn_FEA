@props([
    'lesson',
    'course',
    'comments' => collect(),
    'isEnrolled' => false,
])

@php
    $user = auth()->user();
    $isInstructor = $user && $user->isInstructor() && (int) $course->instructor_id === (int) $user->id;
    $isAdmin = $user && $user->isAdmin();
    $canComment = ($user && $user->isStudent() && $isEnrolled) || $isInstructor || $isAdmin;
@endphp

<div class="py-2" id="lesson-comments-section">
    <div class="flex items-center justify-between mb-4">
        <h5 class="font-bold text-base text-[#1c1d1f] flex items-center gap-2 mb-0">
            <span>💬</span> Bình luận bài học <span id="comments-header-count" class="text-xs font-normal text-slate-500">({{ $comments->count() }})</span>
        </h5>
    </div>

    @if(!$isEnrolled && !$isInstructor && !$isAdmin)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-center text-amber-800" role="alert">
            <p class="text-sm font-semibold mb-0">Bạn cần đăng ký khóa học này để có thể xem và tham gia bình luận bài học.</p>
        </div>
    @else
        <!-- FORM GỬI BÌNH LUẬN MỚI (CHÍNH) -->
        @if($canComment)
            <div class="mb-6 rounded-lg border border-[#d1d7dc] bg-white p-4 shadow-xs">
                <form action="{{ route('lessons.comments.store', $lesson) }}" method="POST" class="space-y-3 ajax-comment-form">
                    @csrf
                    <div class="flex items-start gap-3">
                        <img src="{{ $user->avatarUrl() }}" alt="Avatar" class="rounded-full shrink-0 border border-slate-200" style="width: 38px; height: 38px; object-fit: cover;">
                        <div class="flex-1">
                            <textarea name="content" rows="2" class="w-full rounded-lg border border-[#d1d7dc] px-3 py-2 text-sm text-[#1c1d1f] focus:outline-none focus:ring-2 focus:ring-[#0056D2]" placeholder="Viết bình luận bài học của bạn..." required></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end pt-1">
                        <button type="submit" class="inline-flex items-center rounded-lg bg-[#1c1d1f] px-4 py-2 text-xs font-bold text-white hover:bg-black transition cursor-pointer">
                            Bình luận
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <!-- LUỒNG BÌNH LUẬN (COMMENT STREAM) -->
        <div class="space-y-4" id="comment-list-container" data-poll-url="{{ route('lessons.comments.index', $lesson) }}">
            @include('components.learning.lesson-comments-list', [
                'lesson' => $lesson,
                'course' => $course,
                'comments' => $comments,
                'user' => $user,
                'isInstructor' => $isInstructor,
                'isAdmin' => $isAdmin,
                'canComment' => $canComment,
                'isEnrolled' => $isEnrolled,
            ])
        </div>
    @endif
</div>

<!-- SCRIPT XỬ LÝ AJAX BÌNH LUẬN / SỬA / XÓA / ẨN & TỰ ĐỘNG CẬP NHẬT REALTIME -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const commentsSection = document.getElementById('lesson-comments-section');
    if (!commentsSection) return;

    const commentListContainer = document.getElementById('comment-list-container');
    const commentsHeaderCount = document.getElementById('comments-header-count');
    const tabCommentsButtonText = document.getElementById('tab-comments-button-text');
    const pollUrl = commentListContainer?.getAttribute('data-poll-url');

    let isSubmitting = false;
    let lastRenderedHtml = commentListContainer ? commentListContainer.innerHTML.trim() : '';

    const updateCommentCounts = (count) => {
        if (count === undefined || count === null) return;
        if (commentsHeaderCount) {
            commentsHeaderCount.textContent = `(${count})`;
        }
        if (tabCommentsButtonText) {
            tabCommentsButtonText.textContent = count > 0 ? `Bình luận (${count})` : 'Bình luận';
        }
    };

    const showCommentToast = (message, type = 'error') => {
        const safeMessage = typeof message === 'string' && message.trim() !== ''
            ? message
            : (type === 'error' ? 'Không thể thực hiện thao tác. Vui lòng thử lại.' : 'Thành công.');

        if (window.AppToast?.show) {
            window.AppToast.show({ type, message: safeMessage });
            return;
        }

        if (type === 'error') {
            console.error(safeMessage);
        } else {
            console.log(safeMessage);
        }
    };

    const updateCommentsContainer = (html, count) => {
        if (!commentListContainer) return;
        if (html !== undefined && html !== null) {
            commentListContainer.innerHTML = html;
            lastRenderedHtml = html.trim();
            if (window.Alpine) {
                window.Alpine.initTree(commentListContainer);
            }
        }
        updateCommentCounts(count);
    };

    // Auto-polling for new comments while watching video (every 4s)
    let pollInterval = null;
    const startPolling = () => {
        if (!pollUrl || pollInterval) return;

        pollInterval = setInterval(async () => {
            if (isSubmitting || !commentListContainer) return;

            // Do not replace HTML if user is currently typing in an inline reply or edit textarea
            const hasActiveTyping = Array.from(commentListContainer.querySelectorAll('textarea')).some(
                ta => ta === document.activeElement || (ta.value && ta.value.trim().length > 0)
            );
            if (hasActiveTyping) return;

            try {
                const res = await fetch(pollUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                if (!res.ok) return;
                const data = await res.json();
                if (data.success && data.html) {
                    const trimmedNew = data.html.trim();
                    if (trimmedNew !== lastRenderedHtml) {
                        updateCommentsContainer(data.html, data.count);
                    }
                }
            } catch (err) {
                // Silently ignore network glitches during background polling
            }
        }, 4000);
    };

    startPolling();

    // Event delegation for submit inside commentsSection
    commentsSection.addEventListener('submit', function (e) {
        const form = e.target;

        // 1. GỬI BÌNH LUẬN MỚI HOẶC REPLY BẰNG AJAX (KHÔNG RELOAD TRANG)
        if (form.classList.contains('ajax-comment-form')) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            isSubmitting = true;

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || formData.get('_token')
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    form.reset();
                    // If it was a reply form, reset and close Alpine reply form
                    const alpineCard = form.closest('[x-data]');
                    if (alpineCard && window.Alpine && window.Alpine.$data) {
                        const alpineData = window.Alpine.$data(alpineCard);
                        if (alpineData && 'showReplyForm' in alpineData) {
                            alpineData.showReplyForm = false;
                            alpineData.replyContent = '';
                        }
                    }

                    updateCommentsContainer(data.html, data.count);
                    showCommentToast(data.message || 'Đã gửi bình luận thành công.', 'success');
                } else {
                    showCommentToast(data.message || 'Có lỗi xảy ra khi gửi bình luận.');
                }
            })
            .catch(err => {
                console.error(err);
                showCommentToast('Không thể gửi bình luận. Vui lòng kiểm tra lại kết nối mạng.');
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
                isSubmitting = false;
            });
        }

        // 2. CHỈNH SỬA BÌNH LUẬN BẰNG AJAX (KHÔNG RELOAD TRANG)
        if (form.classList.contains('ajax-edit-comment-form')) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            isSubmitting = true;

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || formData.get('_token')
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateCommentsContainer(data.html, data.count);
                    showCommentToast(data.message || 'Đã cập nhật bình luận.', 'success');
                } else {
                    showCommentToast(data.message || 'Không thể cập nhật bình luận.');
                }
            })
            .catch(err => {
                console.error(err);
                showCommentToast('Có lỗi xảy ra khi cập nhật bình luận.');
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
                isSubmitting = false;
            });
        }

        // 3. XÓA BÌNH LUẬN BẰNG AJAX (KHÔNG RELOAD TRANG)
        if (form.classList.contains('ajax-delete-comment-form')) {
            e.preventDefault();
            if (!confirm('Bạn có chắc chắn muốn xóa bình luận này?')) return;

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            isSubmitting = true;

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || formData.get('_token')
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateCommentsContainer(data.html, data.count);
                    showCommentToast(data.message || 'Đã xóa bình luận.', 'success');
                } else {
                    showCommentToast(data.message || 'Không thể xóa bình luận.');
                }
            })
            .catch(err => {
                console.error(err);
                showCommentToast('Có lỗi xảy ra khi xóa bình luận.');
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
                isSubmitting = false;
            });
        }

        // 4. TOGGLE HIDE / UNHIDE BẰNG AJAX (KHÔNG RELOAD TRANG)
        if (form.classList.contains('ajax-toggle-hide-form')) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            isSubmitting = true;

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || formData.get('_token')
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateCommentsContainer(data.html, data.count);
                    showCommentToast(data.message || 'Đã cập nhật trạng thái.', 'success');
                } else {
                    showCommentToast(data.message || 'Không thể thay đổi trạng thái.');
                }
            })
            .catch(err => {
                console.error(err);
                showCommentToast('Có lỗi xảy ra khi thay đổi trạng thái.');
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
                isSubmitting = false;
            });
        }
    });
});
</script>
