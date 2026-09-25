<x-instructor-layout title="Yêu cầu cấp lại lượt Quiz" pageTitle="Yêu cầu cấp lại lượt Quiz" breadcrumb="Giảng viên / Yêu cầu làm lại Quiz">
    <div class="space-y-6" x-data="{
        rejectModalOpen: false,
        activeRequestId: null,
        activeStudentName: '',
        activeRejectUrl: '',

        detailModalOpen: false,
        activeDetail: null,

        openDetailModal(data) {
            this.activeDetail = data;
            this.detailModalOpen = true;
        },
        closeDetailModal() {
            this.detailModalOpen = false;
            this.activeDetail = null;
        },
        
        openRejectModal(id, name, url) {
            this.activeRequestId = id;
            this.activeStudentName = name;
            this.activeRejectUrl = url;
            this.rejectModalOpen = true;
        },
        closeRejectModal() {
            this.rejectModalOpen = false;
            this.activeRequestId = null;
            this.activeStudentName = '';
            this.activeRejectUrl = '';
        }
    }">
        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950/30 dark:text-emerald-300 flex items-center justify-between">
                <span>{{ session('success') }}</span>
            </div>
        @endif
        @if(session('error'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800 dark:border-rose-500/30 dark:bg-rose-950/30 dark:text-rose-300 flex items-center justify-between">
                <span>{{ session('error') }}</span>
            </div>
        @endif

        {{-- Filter Header --}}
        <form method="GET" action="{{ route('instructor.quiz-attempt-requests.index') }}" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase">Khóa học</label>
                <select name="course_id" onchange="this.form.submit()" class="w-full cursor-pointer rounded-xl border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
                    <option value="">Tất cả khóa học</option>
                    @foreach($ownedCourses as $course)
                        <option value="{{ $course->id }}" @selected(request('course_id') == $course->id)>{{ $course->title }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase">Trạng thái</label>
                <select name="status" onchange="this.form.submit()" class="w-full cursor-pointer rounded-xl border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
                    <option value="">Tất cả trạng thái</option>
                    <option value="pending" @selected(request('status') === 'pending')>Chờ xử lý ({{ $pendingCount }})</option>
                    <option value="approved" @selected(request('status') === 'approved')>Đã duyệt</option>
                    <option value="rejected" @selected(request('status') === 'rejected')>Đã từ chối</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase">Tìm học viên</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Tên hoặc email..." class="w-full rounded-xl border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="w-full cursor-pointer rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700">
                    Lọc danh sách
                </button>
                @if(request()->hasAny(['course_id', 'status', 'search']))
                    <a href="{{ route('instructor.quiz-attempt-requests.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        Đặt lại
                    </a>
                @endif
            </div>
        </form>

        {{-- Table Container --}}
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-100 dark:bg-slate-950 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4">Học viên</th>
                            <th class="px-6 py-4">Khóa học / Bài Quiz</th>
                            <th class="px-6 py-4 text-center">Lượt làm</th>
                            <th class="px-6 py-4">Lý do xin cấp lại</th>
                            <th class="px-6 py-4">Thời gian gửi</th>
                            <th class="px-6 py-4 text-center">Trạng thái</th>
                            <th class="px-6 py-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse($requests as $req)
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/50 transition">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="h-9 w-9 rounded-full bg-slate-200 dark:bg-slate-700 flex items-center justify-center font-bold text-slate-700 dark:text-slate-200 shrink-0">
                                            {{ strtoupper(substr($req->user?->name ?? 'U', 0, 1)) }}
                                        </div>
                                        <div>
                                            <p class="font-bold text-slate-900 dark:text-white">{{ $req->user?->name }}</p>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ $req->user?->email }}</p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4 max-w-xs">
                                    <p class="font-bold text-slate-900 dark:text-white truncate">{{ $req->course?->title }}</p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">
                                        📝 {{ $req->lesson?->title ?? $req->quiz?->title ?? 'Bài kiểm tra' }}
                                    </p>
                                    <div class="mt-1">
                                        <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 font-bold text-[10px] {{ ($req->request_number ?? 1) > 1 ? 'bg-amber-100 text-amber-800 border border-amber-300 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-700' : 'bg-slate-100 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700' }}">
                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            Yêu cầu cấp lần {{ $req->request_number ?? 1 }}
                                        </span>
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-lg bg-slate-100 px-2.5 py-1 font-bold text-slate-800 dark:bg-slate-800 dark:text-slate-200">
                                        {{ $req->attempts_used }}/{{ $req->max_attempts }} lượt
                                    </span>
                                </td>

                                <td class="px-6 py-4 max-w-xs">
                                    <p class="text-slate-700 dark:text-slate-300 line-clamp-3 leading-relaxed">
                                        {{ $req->reason }}
                                    </p>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-500 dark:text-slate-400">
                                    {{ $req->created_at->format('d/m/Y H:i') }}
                                </td>

                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 font-bold text-[11px] {{ $req->getStatusBadgeClass() }}">
                                        {{ $req->getStatusLabel() }}
                                        @if($req->isApproved() && $req->extra_attempts_granted > 0)
                                            (+{{ $req->extra_attempts_granted }} lượt)
                                        @endif
                                    </span>
                                    @if($req->isRejected() && $req->rejection_reason)
                                        <p class="text-[10px] text-rose-500 mt-1 max-w-[150px] truncate" title="{{ $req->rejection_reason }}">
                                            {{ $req->rejection_reason }}
                                        </p>
                                    @endif
                                </td>

                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    @php
                                        $detailData = [
                                            'id' => $req->id,
                                            'student_name' => $req->user?->name ?? 'N/A',
                                            'student_email' => $req->user?->email ?? 'N/A',
                                            'student_initial' => strtoupper(substr($req->user?->name ?? 'U', 0, 1)),
                                            'course_title' => $req->course?->title ?? 'N/A',
                                            'quiz_title' => $req->lesson?->title ?? $req->quiz?->title ?? 'Bài kiểm tra',
                                            'attempts_used' => $req->attempts_used,
                                            'max_attempts' => $req->max_attempts,
                                            'reason' => $req->reason,
                                            'created_at' => $req->created_at->format('d/m/Y H:i'),
                                            'status' => $req->status,
                                            'status_label' => $req->getStatusLabel(),
                                            'status_badge_class' => $req->getStatusBadgeClass(),
                                            'extra_attempts_granted' => $req->extra_attempts_granted,
                                            'rejection_reason' => $req->rejection_reason,
                                            'reviewed_by_name' => $req->reviewer?->name,
                                            'reviewed_at' => $req->reviewed_at?->format('d/m/Y H:i'),
                                            'approve_url' => route('instructor.quiz-attempt-requests.approve', $req),
                                            'reject_url' => route('instructor.quiz-attempt-requests.reject', $req),
                                            'request_number' => $req->request_number ?? 1,
                                            'total_requests' => $req->total_requests ?? 1,
                                            'history' => $req->request_history ?? [],
                                        ];
                                    @endphp

                                    <div class="inline-flex items-center gap-2">
                                        {{-- Nút Icon mắt xem chi tiết trước nút Duyệt --}}
                                        <div class="relative group/tooltip inline-flex items-center">
                                            <button type="button"
                                                @click="openDetailModal(@js($detailData))"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-indigo-500/50 dark:hover:bg-slate-700 dark:hover:text-indigo-400 cursor-pointer"
                                                title="Xem">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                            </button>
                                            <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 hidden group-hover/tooltip:block z-30 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[11px] font-semibold text-white shadow-lg pointer-events-none dark:bg-slate-700">
                                                Xem
                                                <span class="absolute top-full left-1/2 -translate-x-1/2 -mt-0.5 border-4 border-transparent border-t-slate-900 dark:border-t-slate-700"></span>
                                            </div>
                                        </div>

                                        @if($req->isPending())
                                            <form method="POST" action="{{ route('instructor.quiz-attempt-requests.approve', $req) }}" onsubmit="return confirm('Xác nhận duyệt cấp thêm 1 lượt làm Quiz cho học viên {{ addslashes($req->user?->name) }}?')">
                                                @csrf
                                                <input type="hidden" name="extra_attempts" value="1">
                                                <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-600 px-3 py-1.5 font-bold text-white shadow-sm hover:bg-emerald-700 transition cursor-pointer">
                                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    Duyệt (+1 lượt)
                                                </button>
                                            </form>

                                            <button type="button" @click="openRejectModal({{ $req->id }}, '{{ addslashes($req->user?->name) }}', '{{ route('instructor.quiz-attempt-requests.reject', $req) }}')" class="inline-flex items-center gap-1 rounded-xl border border-rose-300 bg-rose-50 px-3 py-1.5 font-bold text-rose-700 hover:bg-rose-100 transition cursor-pointer dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                Từ chối
                                            </button>
                                        @else
                                            <span class="text-[11px] text-slate-400">
                                                Đã duyệt lúc {{ $req->reviewed_at?->format('d/m/Y H:i') ?? 'N/A' }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-slate-400 dark:text-slate-500">
                                    <svg class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Không có yêu cầu cấp lại lượt làm Quiz nào.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($requests->hasPages())
                <div class="p-4 border-t border-slate-100 dark:border-slate-800">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>

        {{-- Modal Từ chối yêu cầu --}}
        <div x-show="rejectModalOpen" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="rejectModalOpen" x-transition.opacity class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="closeRejectModal()"></div>

                <div x-show="rejectModalOpen" x-transition class="inline-block transform overflow-hidden rounded-2xl bg-white text-left align-bottom shadow-xl transition-all dark:bg-slate-900 sm:my-8 sm:w-full sm:max-w-md sm:align-middle p-6 border border-slate-200 dark:border-slate-800">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white" id="modal-title">
                        Từ chối yêu cầu cấp lại lượt Quiz
                    </h3>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Học viên: <strong class="text-slate-800 dark:text-slate-200" x-text="activeStudentName"></strong>
                    </p>

                    <form :action="activeRejectUrl" method="POST" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="rejection_reason" class="block text-xs font-semibold uppercase text-slate-500 mb-1">
                                Lý do từ chối (tùy chọn)
                            </label>
                            <textarea name="rejection_reason" id="rejection_reason" rows="3" class="w-full rounded-xl border-slate-300 text-sm placeholder-slate-400 focus:border-rose-500 focus:ring-rose-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white" placeholder="Nhập lý do không chấp thuận để học viên được biết..."></textarea>
                        </div>

                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="closeRejectModal()" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 transition cursor-pointer dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                                Hủy bỏ
                            </button>
                            <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white hover:bg-rose-700 transition cursor-pointer">
                                Xác nhận từ chối
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Modal Xem chi tiết yêu cầu cấp lại --}}
        <div x-show="detailModalOpen" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="detail-modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4 text-center sm:p-0">
                <div x-show="detailModalOpen" x-transition.opacity class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="closeDetailModal()"></div>

                <div x-show="detailModalOpen" x-transition class="relative inline-block w-full max-w-xl transform overflow-hidden rounded-2xl bg-white text-left align-middle shadow-2xl transition-all dark:bg-slate-900 p-6 border border-slate-200 dark:border-slate-800 my-8">
                    {{-- Header modal --}}
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white" id="detail-modal-title">
                                    Chi tiết yêu cầu cấp lại lượt Quiz
                                    <span class="ml-1 text-xs font-semibold text-amber-600 dark:text-amber-400" x-text="'(Lần ' + (activeDetail?.request_number || 1) + ')'"></span>
                                </h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Gửi lúc: <span class="font-medium text-slate-700 dark:text-slate-300" x-text="activeDetail?.created_at"></span>
                                </p>
                            </div>
                        </div>
                        <button type="button" @click="closeDetailModal()" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-200 transition cursor-pointer">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    {{-- Body modal --}}
                    <div class="mt-4 space-y-4" x-show="activeDetail">
                        {{-- Thông tin học viên --}}
                        <div class="flex items-center gap-3 rounded-xl bg-slate-50 p-3.5 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800">
                            <div class="h-11 w-11 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold text-base shrink-0 shadow-sm" x-text="activeDetail?.student_initial"></div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-slate-900 dark:text-white" x-text="activeDetail?.student_name"></p>
                                <p class="text-xs text-slate-500 dark:text-slate-400" x-text="activeDetail?.student_email"></p>
                            </div>
                            <div class="text-right flex flex-col items-end gap-1">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 font-bold text-[11px]" :class="activeDetail?.status_badge_class" x-text="activeDetail?.status_label"></span>
                                <span class="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300" x-show="(activeDetail?.request_number || 1) > 1" x-text="'Yêu cầu cấp lần ' + activeDetail?.request_number"></span>
                            </div>
                        </div>

                        {{-- Thông tin khóa học & Quiz --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <span class="text-slate-400 dark:text-slate-500 font-medium block mb-1">Khóa học</span>
                                <p class="font-bold text-slate-900 dark:text-white" x-text="activeDetail?.course_title"></p>
                            </div>
                            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <span class="text-slate-400 dark:text-slate-500 font-medium block mb-1">Bài Quiz / Bài học</span>
                                <p class="font-bold text-slate-900 dark:text-white" x-text="activeDetail?.quiz_title"></p>
                            </div>
                            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <span class="text-slate-400 dark:text-slate-500 font-medium block mb-1">Số lượt đã sử dụng</span>
                                <p class="font-bold text-amber-600 dark:text-amber-400">
                                    <span x-text="activeDetail?.attempts_used"></span>/<span x-text="activeDetail?.max_attempts"></span> lượt
                                </p>
                            </div>
                            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <span class="text-slate-400 dark:text-slate-500 font-medium block mb-1">Thời gian gửi</span>
                                <p class="font-bold text-slate-800 dark:text-slate-200" x-text="activeDetail?.created_at"></p>
                            </div>
                        </div>

                        {{-- Toàn bộ Lý do xin cấp lại --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                                Lý do xin cấp lại của học viên
                            </label>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 text-xs text-slate-800 leading-relaxed dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200 whitespace-pre-wrap max-h-48 overflow-y-auto" x-text="activeDetail?.reason"></div>
                        </div>

                        {{-- Thông tin thêm nếu đã duyệt --}}
                        <div x-show="activeDetail?.status === 'approved'" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950/30 dark:text-emerald-300">
                            <p class="font-bold">Đã duyệt cấp lại: +<span x-text="activeDetail?.extra_attempts_granted || 1"></span> lượt làm bài</p>
                            <p class="text-[11px] mt-0.5" x-show="activeDetail?.reviewed_at">
                                Duyệt bởi: <strong x-text="activeDetail?.reviewed_by_name || 'Giảng viên'"></strong> lúc <span x-text="activeDetail?.reviewed_at"></span>
                            </p>
                        </div>

                        {{-- Thông tin thêm nếu bị từ chối --}}
                        <div x-show="activeDetail?.status === 'rejected'" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 dark:border-rose-500/30 dark:bg-rose-950/30 dark:text-rose-300">
                            <p class="font-bold">Lý do từ chối:</p>
                            <p class="mt-1 text-xs" x-text="activeDetail?.rejection_reason || 'Không có lý do cụ thể.'"></p>
                            <p class="text-[11px] mt-1 text-rose-600 dark:text-rose-400" x-show="activeDetail?.reviewed_at">
                                Từ chối bởi: <strong x-text="activeDetail?.reviewed_by_name || 'Giảng viên'"></strong> lúc <span x-text="activeDetail?.reviewed_at"></span>
                            </p>
                        </div>

                        {{-- Lịch sử các lần yêu cầu bài Quiz này nếu có > 1 lần --}}
                        <div x-show="activeDetail?.history && activeDetail?.history.length > 1" class="pt-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                                Lịch sử tất cả các lần xin cấp lại bài Quiz này (<span x-text="activeDetail?.history?.length"></span> lần)
                            </label>
                            <div class="space-y-2 rounded-xl border border-slate-200 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-950/50 max-h-48 overflow-y-auto">
                                <template x-for="(hist, idx) in activeDetail?.history" :key="hist.id">
                                    <div class="rounded-lg border p-2.5 text-xs transition"
                                        :class="hist.id === activeDetail?.id ? 'border-amber-300 bg-amber-50/60 dark:border-amber-500/30 dark:bg-amber-950/20' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900'">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-1.5 font-bold">
                                                <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] text-slate-700 dark:bg-slate-800 dark:text-slate-300" x-text="'Lần ' + hist.request_number"></span>
                                                <span class="text-slate-500 text-[11px]" x-text="hist.created_at"></span>
                                                <span x-show="hist.id === activeDetail?.id" class="text-[10px] font-semibold text-amber-600 dark:text-amber-400">(Hiện tại)</span>
                                            </div>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 font-bold text-[10px]" :class="hist.status_badge_class" x-text="hist.status_label"></span>
                                        </div>
                                        <p class="mt-1 text-slate-700 dark:text-slate-300 text-[11px] leading-relaxed">
                                            <span class="text-slate-400">Lý do:</span> <span x-text="hist.reason"></span>
                                        </p>
                                        <div x-show="hist.status === 'approved' && hist.reviewed_at" class="mt-1 text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">
                                            ✔ Đã cấp +<span x-text="hist.extra_attempts_granted || 1"></span> lượt bởi <span x-text="hist.reviewed_by_name || 'Giảng viên'"></span> lúc <span x-text="hist.reviewed_at"></span>
                                        </div>
                                        <div x-show="hist.status === 'rejected' && hist.reviewed_at" class="mt-1 text-[10px] text-rose-600 dark:text-rose-400 font-medium">
                                            ✖ Từ chối bởi <span x-text="hist.reviewed_by_name || 'Giảng viên'"></span>: <span x-text="hist.rejection_reason || 'Không chấp thuận'"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Footer modal với nút Duyệt / Từ chối (nếu pending) & Nút đóng --}}
                    <div class="mt-6 flex items-center justify-end gap-2.5 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <button type="button" @click="closeDetailModal()" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 transition cursor-pointer dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            Đóng
                        </button>

                        <div x-show="activeDetail?.status === 'pending'" class="flex items-center gap-2">
                            <button type="button" @click="const d = activeDetail; closeDetailModal(); openRejectModal(d.id, d.student_name, d.reject_url)" class="inline-flex items-center gap-1 rounded-xl border border-rose-300 bg-rose-50 px-3.5 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100 transition cursor-pointer dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                Từ chối
                            </button>
                            <form method="POST" :action="activeDetail?.approve_url" onsubmit="return confirm('Xác nhận duyệt cấp thêm 1 lượt làm Quiz cho học viên này?')">
                                @csrf
                                <input type="hidden" name="extra_attempts" value="1">
                                <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 transition cursor-pointer">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Duyệt (+1 lượt)
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-instructor-layout>
