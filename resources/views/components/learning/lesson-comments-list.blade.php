@php
    $user = auth()->user();
    $isInstructor = $user && $user->isInstructor() && (int) $course->instructor_id === (int) $user->id;
    $isAdmin = $user && $user->isAdmin();
    $isEnrolled = $isEnrolled ?? ($user && \App\Models\Enrollment::where('user_id', $user->id)->where('course_id', $course->id)->withLearningAccess()->exists());
    $canComment = $canComment ?? (($user && $user->isStudent() && $isEnrolled) || $isInstructor || $isAdmin);
@endphp

@if($comments->isEmpty())
    <div id="comment-empty-state" class="rounded-lg border border-dashed border-[#d1d7dc] bg-[#f7f9fa] text-center py-6">
        <p class="text-sm text-[#6a6f73] font-semibold mb-1">Chưa có bình luận nào cho bài học này.</p>
        <p class="text-xs text-[#6a6f73] mb-0">Hãy là người đầu tiên gửi bình luận!</p>
    </div>
@else
    @foreach($comments as $comment)
        @php
            $commentUserIsInstructor = $comment->user && $comment->user->isInstructor() && (int) $course->instructor_id === (int) $comment->user->id;
            $canModerate = $isInstructor || $isAdmin;
            $canEditComment = $user && ($isAdmin || (int) $comment->user_id === (int) $user->id);
            $canDeleteComment = $user && ($isAdmin || $isInstructor || (int) $comment->user_id === (int) $user->id);
        @endphp
        <div class="rounded-lg border border-[#d1d7dc] bg-white p-4 transition shadow-2xs {{ $comment->is_hidden ? 'bg-slate-50 opacity-75 border-dashed border-rose-300' : '' }}" id="comment-card-{{ $comment->id }}" x-data="{ showReplyForm: false, replyContent: '', isEditing: false, showMenu: false }">
            
            <!-- COMMENT CẤP 1 (MAIN COMMENT) -->
            <div class="flex items-start gap-3">
                <img src="{{ $comment->user ? $comment->user->avatarUrl() : 'https://ui-avatars.com/api/?name=User' }}" alt="Avatar" class="rounded-full shrink-0 border border-slate-200" style="width: 36px; height: 36px; object-fit: cover;">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-sm text-[#1c1d1f]">{{ $comment->user ? $comment->user->name : 'NĐT' }}</span>
                            @if($commentUserIsInstructor)
                                <span class="inline-flex items-center rounded bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 border border-amber-200">Giảng viên</span>
                            @elseif($comment->user && $comment->user->isAdmin())
                                <span class="inline-flex items-center rounded bg-purple-50 px-2 py-0.5 text-[11px] font-semibold text-purple-800 border border-purple-200">Admin</span>
                            @else
                                <span class="inline-flex items-center rounded bg-gray-50 px-2 py-0.5 text-[11px] font-semibold text-gray-600 border border-gray-200">Học viên</span>
                            @endif

                            <span id="comment-status-badge-{{ $comment->id }}" class="{{ $comment->is_hidden ? 'inline-flex' : 'hidden' }} items-center rounded bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-800 border border-rose-200">🔒 Đã ẩn</span>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="text-xs text-[#6a6f73]">{{ $comment->created_at->diffForHumans() }}</span>

                            @if($canEditComment || $canDeleteComment || $canModerate)
                                <div class="relative" @click.away="showMenu = false">
                                    <button type="button" class="p-1 rounded-full text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition cursor-pointer" @click="showMenu = !showMenu">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/>
                                        </svg>
                                    </button>

                                    <div x-show="showMenu" x-transition class="absolute right-0 mt-1 w-36 rounded-lg bg-white shadow-lg border border-[#d1d7dc] py-1 z-20 text-xs" x-cloak>
                                        @if($canEditComment)
                                            <button type="button" class="w-full text-left px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-100 flex items-center gap-2 cursor-pointer" @click="isEditing = true; showMenu = false">
                                                <span>✏️</span> Chỉnh sửa
                                            </button>
                                        @endif

                                        @if($canDeleteComment)
                                            <form action="{{ route('comments.destroy', $comment) }}" method="POST" class="ajax-delete-comment-form" data-comment-id="{{ $comment->id }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="w-full text-left px-3 py-1.5 font-semibold text-rose-600 hover:bg-rose-50 flex items-center gap-2 cursor-pointer">
                                                    <span>🗑️</span> Xóa
                                                </button>
                                            </form>
                                        @endif

                                        @if($canModerate)
                                            <form action="{{ route('comments.toggle-hide', $comment) }}" method="POST" class="ajax-toggle-hide-form" data-comment-id="{{ $comment->id }}">
                                                @csrf
                                                <button type="submit" class="w-full text-left px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-100 flex items-center gap-2 cursor-pointer text-toggle-btn">
                                                    {{ $comment->is_hidden ? '👁️‍🗨️ Bỏ ẩn' : '👁️ Ẩn' }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- HIỂN THỊ NỘI DUNG / FORM EDIT INLINE -->
                    <div x-show="!isEditing">
                        <p class="text-sm text-[#1c1d1f] whitespace-pre-line leading-relaxed mb-2" id="comment-text-{{ $comment->id }}">{{ $comment->content }}</p>
                    </div>

                    @if($canEditComment)
                        <div x-show="isEditing" class="mb-2 p-2 rounded-lg bg-[#f7f9fa] border border-[#d1d7dc]" x-cloak>
                            <form action="{{ route('comments.update', $comment) }}" method="POST" class="space-y-2 ajax-edit-comment-form" data-comment-id="{{ $comment->id }}">
                                @csrf
                                @method('PUT')
                                <textarea name="content" rows="2" class="w-full rounded border border-[#d1d7dc] p-2 text-xs text-[#1c1d1f] focus:outline-none focus:ring-2 focus:ring-[#0056D2]" required>{{ $comment->content }}</textarea>
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="px-2.5 py-1 rounded text-xs font-semibold text-gray-600 border border-gray-300 hover:bg-gray-100 cursor-pointer" @click="isEditing = false">Hủy</button>
                                    <button type="submit" class="px-2.5 py-1 rounded text-xs font-bold text-white bg-[#0056D2] hover:bg-blue-700 cursor-pointer">Lưu</button>
                                </div>
                            </form>
                        </div>
                    @endif

                    <div class="flex items-center gap-4 text-xs pt-1">
                        @if($canComment && !$comment->is_hidden)
                            <button type="button" class="font-bold text-[#0056D2] hover:underline flex items-center gap-1 cursor-pointer" @click="showReplyForm = !showReplyForm; if(showReplyForm && !replyContent) replyContent = ''">
                                <span>💬</span> Trả lời
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            <!-- FORM REPLY CẤP 2 -->
            <div x-show="showReplyForm" class="mt-3 ml-10 p-3 rounded-lg bg-[#f7f9fa] border border-[#d1d7dc]" x-transition x-cloak>
                <form action="{{ route('lessons.comments.store', $lesson) }}" method="POST" class="space-y-2 ajax-comment-form">
                    @csrf
                    <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                    <textarea name="content" x-model="replyContent" x-ref="replyTextarea-{{ $comment->id }}" rows="2" class="w-full rounded border border-[#d1d7dc] p-2 text-xs text-[#1c1d1f] focus:outline-none focus:ring-2 focus:ring-[#0056D2]" placeholder="Viết phản hồi..." required></textarea>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" class="px-3 py-1 rounded text-xs font-semibold text-gray-600 border border-gray-300 hover:bg-gray-100 cursor-pointer" @click="showReplyForm = false">Hủy</button>
                        <button type="submit" class="px-3 py-1 rounded text-xs font-bold text-white bg-[#1c1d1f] hover:bg-black cursor-pointer">Gửi phản hồi</button>
                    </div>
                </form>
            </div>

            <!-- DANH SÁCH PHẢN HỒI (REPLIES LEVEL 2) -->
            <div class="mt-3 ml-9 border-l-2 border-[#d1d7dc] pl-3 space-y-2 {{ ($comment->replies && $comment->replies->isNotEmpty()) ? '' : 'hidden' }}" id="replies-container-{{ $comment->id }}">
                @if($comment->replies)
                    @foreach($comment->replies as $reply)
                        @php
                            $replyUserIsInstructor = $reply->user && $reply->user->isInstructor() && (int) $course->instructor_id === (int) $reply->user->id;
                            $canEditReply = $user && ($isAdmin || (int) $reply->user_id === (int) $user->id);
                            $canDeleteReply = $user && ($isAdmin || $isInstructor || (int) $reply->user_id === (int) $user->id);
                        @endphp
                        <div class="flex items-start gap-2.5 p-2 rounded-md {{ $reply->is_instructor_answer ? 'bg-amber-50/60 border border-amber-200/60' : 'bg-gray-50/80 border border-gray-100' }} {{ $reply->is_hidden ? 'opacity-60 border-dashed border-rose-300' : '' }}" id="comment-card-{{ $reply->id }}" x-data="{ isEditingReply: false, showReplyMenu: false }">
                            <img src="{{ $reply->user ? $reply->user->avatarUrl() : 'https://ui-avatars.com/api/?name=User' }}" alt="Avatar" class="rounded-full shrink-0 border border-slate-200" style="width: 28px; height: 28px; object-fit: cover;">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-xs text-[#1c1d1f]">{{ $reply->user ? $reply->user->name : 'NĐT' }}</span>
                                        @if($replyUserIsInstructor)
                                            <span class="inline-flex items-center rounded bg-amber-100 px-1.5 py-0.2 text-[10px] font-bold text-amber-800">Giảng viên</span>
                                        @elseif($reply->user && $reply->user->isAdmin())
                                            <span class="inline-flex items-center rounded bg-purple-100 px-1.5 py-0.2 text-[10px] font-bold text-purple-800">Admin</span>
                                        @endif

                                        <span id="comment-status-badge-{{ $reply->id }}" class="{{ $reply->is_hidden ? 'inline-flex' : 'hidden' }} items-center rounded bg-rose-100 px-1.5 py-0.2 text-[10px] font-bold text-rose-800 border border-rose-200">🔒 Đã ẩn</span>
                                    </div>

                                    <div class="flex items-center gap-1.5">
                                        <span class="text-[11px] text-[#6a6f73]">{{ $reply->created_at->diffForHumans() }}</span>

                                        @if($canEditReply || $canDeleteReply || $canModerate)
                                            <div class="relative" @click.away="showReplyMenu = false">
                                                <button type="button" class="p-1 rounded-full text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition cursor-pointer" @click="showReplyMenu = !showReplyMenu">
                                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                                                        <path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/>
                                                    </svg>
                                                </button>

                                                <div x-show="showReplyMenu" x-transition class="absolute right-0 mt-1 w-36 rounded-lg bg-white shadow-lg border border-[#d1d7dc] py-1 z-20 text-xs" x-cloak>
                                                    @if($canEditReply)
                                                        <button type="button" class="w-full text-left px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-100 flex items-center gap-2 cursor-pointer" @click="isEditingReply = true; showReplyMenu = false">
                                                            <span>✏️</span> Chỉnh sửa
                                                        </button>
                                                    @endif

                                                    @if($canDeleteReply)
                                                        <form action="{{ route('comments.destroy', $reply) }}" method="POST" class="ajax-delete-comment-form" data-comment-id="{{ $reply->id }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="w-full text-left px-3 py-1.5 font-semibold text-rose-600 hover:bg-rose-50 flex items-center gap-2 cursor-pointer">
                                                                <span>🗑️</span> Xóa
                                                            </button>
                                                        </form>
                                                    @endif

                                                    @if($canModerate)
                                                        <form action="{{ route('comments.toggle-hide', $reply) }}" method="POST" class="ajax-toggle-hide-form" data-comment-id="{{ $reply->id }}">
                                                            @csrf
                                                            <button type="submit" class="w-full text-left px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-100 flex items-center gap-2 cursor-pointer text-toggle-btn">
                                                                {{ $reply->is_hidden ? '👁️‍🗨️ Bỏ ẩn' : '👁️ Ẩn' }}
                                                            </button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div x-show="!isEditingReply">
                                    <p class="text-xs text-[#1c1d1f] whitespace-pre-line leading-relaxed mb-1" id="comment-text-{{ $reply->id }}">{{ $reply->content }}</p>
                                </div>

                                @if($canEditReply)
                                    <div x-show="isEditingReply" class="mb-2 p-2 rounded-lg bg-white border border-[#d1d7dc]" x-cloak>
                                        <form action="{{ route('comments.update', $reply) }}" method="POST" class="space-y-2 ajax-edit-comment-form" data-comment-id="{{ $reply->id }}">
                                            @csrf
                                            @method('PUT')
                                            <textarea name="content" rows="2" class="w-full rounded border border-[#d1d7dc] p-1.5 text-xs text-[#1c1d1f] focus:outline-none focus:ring-2 focus:ring-[#0056D2]" required>{{ $reply->content }}</textarea>
                                            <div class="flex justify-end gap-2">
                                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold text-gray-600 border border-gray-300 hover:bg-gray-100 cursor-pointer" @click="isEditingReply = false">Hủy</button>
                                                <button type="submit" class="px-2 py-0.5 rounded text-[11px] font-bold text-white bg-[#0056D2] hover:bg-blue-700 cursor-pointer">Lưu</button>
                                            </div>
                                        </form>
                                    </div>
                                @endif

                                <div class="flex items-center gap-3 text-[11px] pt-0.5">
                                    @if($canComment && !$comment->is_hidden && !$reply->is_hidden)
                                        <button type="button" class="font-bold text-[#0056D2] hover:underline flex items-center gap-1 cursor-pointer" @click="showReplyForm = true; replyContent = '@' + '{{ $reply->user ? $reply->user->name : 'NĐT' }}' + ' '; $nextTick(() => { if($refs['replyTextarea-{{ $comment->id }}']) $refs['replyTextarea-{{ $comment->id }}'].focus(); })">
                                            <span>💬</span> Trả lời
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    @endforeach
@endif
