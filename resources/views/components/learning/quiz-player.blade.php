@props([
    'quizContext',
    'lesson',
    'standalone' => false,
])

@if($quizContext)
    <div
        class="learning-quiz-panel relative min-h-[320px] bg-[#1c1d1f] p-4 sm:p-6 lg:min-h-[calc(100vh-14rem)] select-none"
        data-quiz-player
        data-quiz-standalone="{{ $standalone ? 'true' : 'false' }}"
        data-quiz='@json($quizContext)'
    >
        {{-- Offline Network Banner --}}
        <div data-quiz-offline-alert class="mb-4 hidden rounded-lg border border-amber-500/30 bg-amber-500/20 px-4 py-2.5 text-xs font-bold text-amber-200 flex items-center gap-2">
            <svg class="h-4 w-4 text-amber-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <span>Đang mất kết nối mạng. Đáp án của bạn vẫn được lưu tạm thời, hệ thống sẽ tự động đồng bộ khi có kết nối trở lại.</span>
        </div>

        <div class="mx-auto mb-3 hidden max-w-2xl rounded border border-amber-400/40 bg-amber-500/10 px-4 py-2 text-sm font-semibold text-amber-100" data-quiz-security-warning></div>

        {{-- Dynamic Watermark Layer --}}
        <div data-quiz-watermark class="pointer-events-none absolute inset-0 z-10 overflow-hidden opacity-[0.07] select-none" hidden>
            <div data-quiz-watermark-pattern class="w-full h-full text-white font-mono text-xs leading-relaxed transform -rotate-12 flex flex-wrap gap-12 p-8"></div>
        </div>
        <div data-quiz-intro class="mx-auto flex min-h-[280px] max-w-2xl flex-col justify-center py-4 text-white">
            <p class="text-xs font-semibold uppercase tracking-wide text-violet-300">Quiz</p>
            <h2 class="mt-2 text-2xl font-bold">{{ $quizContext['title'] }}</h2>
            @if($quizContext['description'])
                <p class="mt-3 text-sm leading-6 text-white/80">{{ $quizContext['description'] }}</p>
            @endif
            <dl class="mt-6 grid gap-3 sm:grid-cols-2">
                <div class="rounded border border-white/10 bg-white/5 p-3">
                    <dt class="text-xs text-white/60">Số câu hỏi</dt>
                    <dd class="mt-1 text-lg font-bold">{{ $quizContext['total_questions'] }}</dd>
                </div>
                <div class="rounded border border-white/10 bg-white/5 p-3">
                    <dt class="text-xs text-white/60">Điểm đạt</dt>
                    <dd class="mt-1 text-lg font-bold">{{ $quizContext['pass_score'] }}%</dd>
                </div>
                @if($quizContext['time_limit_minutes'])
                    <div class="rounded border border-white/10 bg-white/5 p-3">
                        <dt class="text-xs text-white/60">Thời gian</dt>
                        <dd class="mt-1 text-lg font-bold">{{ $quizContext['time_limit_minutes'] }} phút</dd>
                    </div>
                @endif
                <div class="rounded border border-white/10 bg-white/5 p-3">
                    <dt class="text-xs text-white/60">Lần làm</dt>
                    <dd class="mt-1 text-lg font-bold">
                        {{ $quizContext['attempts_count'] }}@if($quizContext['max_attempts'])/{{ $quizContext['max_attempts'] }}@endif
                    </dd>
                </div>
            </dl>

            {{-- Thông báo Quy định làm bài kiểm tra --}}
            <div class="mt-6 rounded-xl border border-indigo-500/30 bg-indigo-950/40 p-5">
                <div class="flex items-center gap-2 text-indigo-300 font-bold text-sm">
                    <svg class="h-5 w-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <span>Quy định làm bài kiểm tra</span>
                </div>
                <ul class="mt-3 list-disc list-inside space-y-1.5 text-xs leading-relaxed text-slate-300">
                    <li>Bài kiểm tra yêu cầu người học tập trung trong suốt quá trình làm bài.</li>
                    <li><strong>Không được chuyển sang tab khác</strong> hoặc ứng dụng khác.</li>
                    <li><strong>Không được thoát khỏi trang làm bài</strong> hoặc thoát chế độ toàn màn hình (Fullscreen).</li>
                    <li>Khi phát hiện hành vi rời khỏi trang hoặc chuyển tab, hệ thống sẽ <strong>tự động lưu trạng thái và kết thúc lần làm bài</strong> hiện tại.</li>
                    <li>Người học chỉ có tối đa <strong>{{ $quizContext['max_attempts'] ?? 3 }} lần làm bài</strong>.</li>
                    <li>Thời gian làm bài sẽ tiếp tục chạy liên tục trong suốt quá trình.</li>
                    <li>Hãy kiểm tra kết nối Internet trước khi bắt đầu.</li>
                </ul>

                @if($quizContext['can_take'])
                    <div class="mt-4 pt-3 border-t border-white/10">
                        <label class="flex items-start gap-2.5 cursor-pointer text-xs font-semibold text-white">
                            <input type="checkbox" data-quiz-agree-rules class="mt-0.5 rounded border-slate-600 bg-slate-800 text-[#0056D2] focus:ring-[#0056D2]">
                            <span>Tôi đã đọc và đồng ý với quy định làm bài kiểm tra.</span>
                        </label>
                    </div>
                @endif
            </div>

            @if($quizContext['can_take'])
                <button type="button" data-quiz-start disabled class="mt-6 inline-flex h-11 items-center justify-center rounded bg-[#0056D2] px-6 text-sm font-bold text-white transition hover:bg-[#0046B8] disabled:opacity-40 disabled:cursor-not-allowed">
                    {{ !empty($quizContext['previous_attempts']) ? 'Làm lại bài quiz' : 'Bắt đầu làm bài' }}
                </button>
            @elseif($quizContext['attempt_limit_reached'])
                <div class="mt-6 rounded-xl border border-amber-400/30 bg-amber-500/10 p-5 text-amber-100 space-y-3">
                    <p class="font-bold text-sm">
                        Bạn đã hết số lần làm quiz này ({{ $quizContext['attempts_count'] }}/{{ $quizContext['max_attempts'] }} lượt).
                    </p>

                    @if(!empty($quizContext['has_pending_request']))
                        <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-amber-400/20">
                            <button type="button" disabled class="cursor-not-allowed rounded-lg bg-amber-500/20 px-4 py-2 text-xs font-bold text-amber-300 border border-amber-500/30">
                                Đang chờ giảng viên xử lý
                            </button>
                            <span class="text-xs text-amber-200/80">
                                Đã gửi yêu cầu lúc {{ $quizContext['latest_attempt_request']['created_at'] ?? '' }}. Vui lòng chờ giảng viên xử lý.
                            </span>
                        </div>
                    @else
                        @if(!empty($quizContext['latest_attempt_request']) && $quizContext['latest_attempt_request']['status'] === 'rejected')
                            <div class="rounded-lg bg-rose-950/40 border border-rose-500/30 p-3 text-xs text-rose-200">
                                <span class="font-bold">Yêu cầu cấp lại lượt đã bị từ chối.</span>
                                @if(!empty($quizContext['latest_attempt_request']['rejection_reason']))
                                    <span>Lý do: {{ $quizContext['latest_attempt_request']['rejection_reason'] }}</span>
                                @endif
                            </div>
                        @endif

                        <div class="pt-2 border-t border-amber-400/20 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-amber-200/80">Bạn có thể gửi yêu cầu đến giảng viên để xin cấp thêm lượt làm bài.</p>
                            <button type="button" id="open-quiz-request-btn" class="rounded-xl bg-amber-500 px-4 py-2 text-xs font-bold text-slate-950 hover:bg-amber-400 transition cursor-pointer shadow">
                                Yêu cầu cấp lại lượt Quiz
                            </button>
                        </div>
                    @endif
                </div>
            @else
                <p class="mt-6 rounded border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/80">Đăng nhập và đăng ký khóa học để làm quiz.</p>
            @endif

            @if(!empty($quizContext['previous_attempts']))
                <div class="mt-6 border-t border-white/10 pt-5">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-white/70">Lịch sử làm bài</h3>
                    <div class="mt-3 space-y-2">
                        @foreach($quizContext['previous_attempts'] as $att)
                            <div class="flex items-center justify-between rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 text-xs">
                                <div class="flex items-center gap-3">
                                    <span class="font-bold text-white">Lần {{ $att['attempt_number'] }}</span>
                                    <span class="rounded px-2 py-0.5 font-bold {{ $att['passed'] ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300' }}">
                                        {{ number_format($att['percent'], 0) }}% - {{ $att['passed'] ? 'Đạt' : 'Chưa đạt' }}
                                    </span>
                                    <span class="text-white/50">{{ $att['completed_at'] }}</span>
                                </div>
                                <a href="{{ $att['review_url'] }}" class="inline-flex items-center gap-1 font-bold text-sky-400 hover:text-sky-300 hover:underline">
                                    Xem lại bài làm →
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Màn hình Đang làm Quiz --}}
        <div data-quiz-active class="mx-auto max-w-2xl text-white relative z-20" hidden>
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <p class="text-sm font-semibold" data-quiz-progress-label>Câu 1 / 1</p>
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[10px] font-bold text-emerald-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        Đang giám sát
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <p class="text-sm font-semibold text-violet-300" data-quiz-timer hidden></p>
                </div>
            </div>
            <div class="mb-3 h-1.5 overflow-hidden rounded-full bg-white/20">
                <div class="h-full rounded-full bg-[#0056D2] transition-all" data-quiz-progress-bar style="width: 0%"></div>
            </div>
            <div data-quiz-question-container></div>
            <div class="mt-6 flex flex-wrap justify-between gap-3">
                <button type="button" data-quiz-prev class="rounded border border-white/20 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10 disabled:opacity-40" disabled>Câu trước</button>
                <button type="button" data-quiz-next class="rounded bg-[#0056D2] px-4 py-2 text-sm font-bold text-white hover:bg-[#0046B8]">Câu tiếp theo</button>
            </div>
        </div>

        {{-- Màn hình Kết thúc do Vi phạm Quy định (Terminated) --}}
        <div data-quiz-terminated class="mx-auto max-w-2xl text-white py-6 relative z-20" hidden>
            <div class="rounded-2xl border border-rose-500/30 bg-rose-950/40 p-6 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-rose-500/20 text-rose-400">
                    <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h3 class="mt-4 text-xl font-extrabold text-white">Phiên làm bài đã kết thúc</h3>
                <p class="mt-2 text-sm leading-6 text-rose-200" data-quiz-terminated-msg>
                    Hệ thống phát hiện bạn đã rời khỏi màn hình làm bài. Theo quy định của bài kiểm tra, lần làm bài hiện tại đã được kết thúc và kết quả đã được lưu.
                </p>
                <div class="mt-4 inline-block rounded-lg bg-black/30 px-4 py-2 text-xs font-semibold text-white/90" data-quiz-terminated-attempts>
                    Bạn còn {{ $quizContext['remaining_attempts'] ?? 0 }}/{{ $quizContext['max_attempts'] ?? 3 }} lần làm bài.
                </div>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <button type="button" data-quiz-terminated-retry class="rounded bg-[#0056D2] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#0046B8]" hidden>
                        Làm lại
                    </button>
                    <a href="{{ route('courses.show', $lesson->course?->slug ?? '') }}" class="rounded border border-white/20 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/10">
                        Quay lại khóa học
                    </a>
                </div>
            </div>
        </div>

        {{-- Màn hình Kết quả Bình thường (Submitted / Result) --}}
        <div data-quiz-result class="mx-auto max-w-2xl text-white relative z-20" hidden></div>
    </div>

    {{-- Modal Yêu cầu cấp lại lượt Quiz --}}
    @if(!empty($quizContext['attempt_limit_reached']) && empty($quizContext['has_pending_request']))
        <div id="quiz-request-attempt-modal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4 text-center">
                <div class="fixed inset-0 bg-black/70 backdrop-blur-sm transition-opacity" id="quiz-request-attempt-backdrop"></div>

                <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-slate-900 border border-white/20 p-6 text-left shadow-2xl transition-all z-10 text-white">
                    <div class="flex items-center justify-between pb-3 border-b border-white/10">
                        <h3 class="text-base font-bold text-white">Yêu cầu cấp lại lượt làm Quiz</h3>
                        <button type="button" id="close-quiz-request-btn" class="text-slate-400 hover:text-white cursor-pointer text-lg font-bold">&times;</button>
                    </div>

                    <form id="quiz-request-attempt-form" action="{{ $quizContext['request_attempt_url'] }}" method="POST" novalidate class="mt-4 space-y-4">
                        @csrf
                        <div class="rounded-xl bg-white/5 p-3.5 border border-white/10 text-xs space-y-1.5 text-slate-300">
                            <p><span class="text-slate-400">Khóa học:</span> <strong class="text-white">{{ $lesson->course?->title }}</strong></p>
                            <p><span class="text-slate-400">Bài Quiz:</span> <strong class="text-white">{{ $quizContext['title'] }}</strong></p>
                            <p><span class="text-slate-400">Học viên:</span> <strong class="text-white">{{ $quizContext['user_info']['name'] ?? auth()->user()?->name }}</strong></p>
                            <p><span class="text-slate-400">Số lượt đã sử dụng:</span> <strong class="text-amber-400">{{ $quizContext['attempts_count'] }}/{{ $quizContext['max_attempts'] }} lượt</strong></p>
                            @if(($quizContext['next_request_number'] ?? 1) > 1)
                                <p><span class="text-slate-400">Yêu cầu lần:</span> <strong class="text-amber-400">Lần {{ $quizContext['next_request_number'] }}</strong></p>
                            @endif
                        </div>

                        <div>
                            <label for="quiz-request-reason" class="block text-xs font-semibold uppercase text-slate-300 mb-1.5">
                                Lý do xin cấp lại <span class="text-rose-400">*</span>
                            </label>
                            <textarea name="reason" id="quiz-request-reason" rows="3" maxlength="1000" class="w-full rounded-xl border border-white/20 bg-slate-950 p-3 text-xs text-white placeholder-slate-500 focus:border-amber-400 focus:outline-none focus:ring-1 focus:ring-amber-400 transition" placeholder="Ví dụ: Em bị gián đoạn đường truyền mạng khi làm bài, em xin phép giảng viên cấp thêm lượt..."></textarea>
                            <p id="quiz-request-error" class="text-xs text-rose-400 mt-1.5 hidden"></p>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button type="button" id="cancel-quiz-request-btn" class="rounded-xl border border-white/20 px-4 py-2 text-xs font-bold text-slate-300 hover:bg-white/10 transition cursor-pointer">
                                Hủy
                            </button>
                            <button type="submit" id="submit-quiz-request-btn" class="rounded-xl bg-amber-500 px-5 py-2 text-xs font-bold text-slate-950 hover:bg-amber-400 transition cursor-pointer shadow">
                                Gửi yêu cầu
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Xác nhận nộp bài khi còn câu chưa làm --}}
    <div id="quiz-confirm-submit-modal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center p-4 text-center">
            <div class="fixed inset-0 bg-black/70 backdrop-blur-sm transition-opacity" id="quiz-confirm-submit-backdrop"></div>

            <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-slate-900 border border-white/20 p-6 text-left shadow-2xl transition-all z-10 text-white">
                <div class="flex items-center gap-3 pb-3 border-b border-white/10">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">Xác nhận nộp bài</h3>
                        <p class="text-xs text-slate-400">Kiểm tra lại câu hỏi trước khi nộp</p>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <p class="text-sm text-slate-300 leading-relaxed" id="quiz-confirm-submit-message">
                        Bạn vẫn còn câu hỏi chưa trả lời. Bạn có chắc chắn muốn nộp bài không?
                    </p>
                    <div class="rounded-xl bg-white/5 p-3 border border-white/10 text-xs text-amber-300 flex items-center gap-2">
                        <svg class="h-4 w-4 shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>Các câu bỏ qua sẽ không được tính điểm.</span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-5">
                    <button type="button" id="quiz-confirm-submit-cancel" class="rounded-xl border border-white/20 px-4 py-2 text-xs font-bold text-slate-300 hover:bg-white/10 transition cursor-pointer">
                        Làm tiếp
                    </button>
                    <button type="button" id="quiz-confirm-submit-ok" class="rounded-xl bg-[#0056D2] px-5 py-2 text-xs font-bold text-white hover:bg-[#0046B8] transition cursor-pointer shadow">
                        Xác nhận nộp bài
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!document.querySelector('script[data-mathjax-loader]')) {
                window.MathJax = {tex: {inlineMath: [['\\(', '\\)'], ['$', '$']], displayMath: [['\\[', '\\]'], ['$$', '$$']]}};
                const mathScript = document.createElement('script');
                mathScript.defer = true;
                mathScript.dataset.mathjaxLoader = '1';
                mathScript.src = 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js';
                document.head.appendChild(mathScript);
            }
            const panel = document.querySelector('[data-quiz-player]');
            if (!panel) return;
            const context = JSON.parse(panel.dataset.quiz || '{}');
            const warning = panel.querySelector('[data-quiz-security-warning]');
            const watermark = panel.querySelector('[data-quiz-watermark]');
            let active = Boolean(context.attempt_id);
            let isConfirmingOrSubmitting = false;
            let lastReportedAt = 0;

            const deactivateSecurity = () => {
                active = false;
                isConfirmingOrSubmitting = true;
                if (warning) {
                    warning.textContent = '';
                    warning.classList.add('hidden');
                }
            };

            window.addEventListener('quiz:deactivate', deactivateSecurity);
            window.addEventListener('quiz:submitting', () => {
                isConfirmingOrSubmitting = true;
                if (warning) warning.classList.add('hidden');
            });

            // Tự động tắt cảnh báo bảo mật khi màn hình kết quả hoặc thông báo vi phạm hiển thị
            const resultEl = panel.querySelector('[data-quiz-result]');
            if (resultEl) {
                const observer = new MutationObserver(() => {
                    if (!resultEl.hidden) {
                        deactivateSecurity();
                    }
                });
                observer.observe(resultEl, { attributes: true, attributeFilter: ['hidden'] });
            }

            if (active) {
                watermark?.classList.remove('hidden');
                watermark?.classList.add('flex');
            }

            panel.querySelector('[data-quiz-start]')?.addEventListener('click', async () => {
                active = true;
                isConfirmingOrSubmitting = false;
                watermark?.classList.remove('hidden');
                watermark?.classList.add('flex');
                try { await panel.requestFullscreen?.(); } catch (_) {}
            });

            const report = async (message) => {
                if (!active || isConfirmingOrSubmitting || Date.now() - lastReportedAt < 1500) return;
                lastReportedAt = Date.now();
                if (warning) {
                    warning.textContent = message;
                    warning.classList.remove('hidden');
                }
                if (context.focus_violation_url) {
                    await fetch(context.focus_violation_url, {
                        method: 'POST',
                        headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json'}
                    }).catch(() => {});
                }
            };

            document.addEventListener('visibilitychange', () => {
                if (active && !isConfirmingOrSubmitting && (document.hidden || document.visibilityState === 'hidden')) {
                    report('Đã ghi nhận việc rời khỏi màn hình làm quiz.');
                }
            });

            window.addEventListener('blur', () => {
                if (!active || isConfirmingOrSubmitting) return;
                setTimeout(() => {
                    if (active && !isConfirmingOrSubmitting && (document.hidden || document.visibilityState === 'hidden')) {
                        report('Đã ghi nhận việc chuyển sang cửa sổ khác.');
                    }
                }, 400);
            });

            document.addEventListener('fullscreenchange', () => active && !isConfirmingOrSubmitting && !document.fullscreenElement && report('Bạn đã thoát chế độ toàn màn hình.'));
            panel.addEventListener('contextmenu', event => active && event.preventDefault());
            panel.addEventListener('copy', event => active && event.preventDefault());

            // Xử lý modal yêu cầu cấp lại lượt quiz
            const modal = document.getElementById('quiz-request-attempt-modal');
            const openBtn = document.getElementById('open-quiz-request-btn');
            const closeBtn = document.getElementById('close-quiz-request-btn');
            const cancelBtn = document.getElementById('cancel-quiz-request-btn');
            const backdrop = document.getElementById('quiz-request-attempt-backdrop');
            const reqForm = document.getElementById('quiz-request-attempt-form');
            const reasonInput = document.getElementById('quiz-request-reason');
            const errorEl = document.getElementById('quiz-request-error');
            const submitBtn = document.getElementById('submit-quiz-request-btn');

            const clearError = () => {
                if (errorEl) {
                    errorEl.textContent = '';
                    errorEl.classList.add('hidden');
                }
                reasonInput?.classList.remove('!border-rose-500', 'focus:!border-rose-500', 'focus:!ring-rose-500');
            };

            const showError = (msg) => {
                if (errorEl) {
                    errorEl.textContent = msg;
                    errorEl.classList.remove('hidden');
                }
                reasonInput?.classList.add('!border-rose-500', 'focus:!border-rose-500', 'focus:!ring-rose-500');
                reasonInput?.focus();
            };

            const closeModal = () => {
                clearError();
                modal?.classList.add('hidden');
            };

            const openModal = () => {
                clearError();
                modal?.classList.remove('hidden');
                setTimeout(() => reasonInput?.focus(), 100);
            };

            openBtn?.addEventListener('click', openModal);
            closeBtn?.addEventListener('click', closeModal);
            cancelBtn?.addEventListener('click', closeModal);
            backdrop?.addEventListener('click', closeModal);

            reasonInput?.addEventListener('input', () => {
                if (errorEl && !errorEl.classList.contains('hidden')) {
                    clearError();
                }
            });

            reqForm?.addEventListener('submit', async (e) => {
                e.preventDefault();
                clearError();

                const reasonVal = reasonInput?.value?.trim() || '';

                if (!reasonVal) {
                    showError('Vui lòng nhập lý do xin cấp lại.');
                    return;
                }

                if (reasonVal.length < 5) {
                    showError('Lý do xin cấp lại phải có ít nhất 5 ký tự.');
                    return;
                }

                if (reasonVal.length > 1000) {
                    showError('Lý do xin cấp lại không được vượt quá 1000 ký tự.');
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.textContent = 'Đang gửi...';

                try {
                    const response = await fetch(reqForm.action, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify({ reason: reasonVal })
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        const errMsg = (data.errors && data.errors.reason && data.errors.reason[0]) || data.message || 'Không thể gửi yêu cầu lúc này.';
                        throw new Error(errMsg);
                    }

                    alert('Đã gửi yêu cầu cấp lại lượt Quiz. Vui lòng chờ giảng viên xử lý.');
                    window.location.reload();
                } catch (err) {
                    showError(err.message || 'Có lỗi xảy ra, vui lòng thử lại.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Gửi yêu cầu';
                }
            });
        });
    </script>
@endif
