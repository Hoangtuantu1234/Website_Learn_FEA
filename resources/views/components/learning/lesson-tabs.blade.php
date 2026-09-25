@props([
    'lesson',
    'course',
    'sectionTitle' => null,
    'navigation' => ['previous' => null, 'next' => null],
    'lessonState' => 'available',
    'isEnrolled' => false,
    'canAccessLesson' => false,
    'canUseLessonAi' => false,
    'aiSummaryUrl' => null,
    'aiExplainUrl' => null,
    'discussions' => collect(),
    'activeDiscussion' => null,
    'lessonComments' => collect(),
    'canUseLessonNotes' => false,
    'lessonNotes' => collect(),
    'lessonNotesIndexUrl' => null,
    'lessonNotesStoreUrl' => null,
    'videoDurationSeconds' => 0,
    'notesPayload' => null,
])

@php
    $videoDurationSeconds = $videoDurationSeconds ?: (isset($lesson) ? ((int) ($lesson->duration_seconds ?: $lesson->duration ?: 0)) : 0);
    $notesPayload = $notesPayload ?? $lessonNotes;

    $resourceCount = (!empty($lesson->document_file) ? 1 : 0) + (is_array($lesson->attachments) ? count($lesson->attachments) : 0);

    $tabsList = [
        'overview' => 'Nội dung',
        'notes' => 'Ghi chú',
        'ai' => 'AI hỗ trợ',
        'resources' => 'Tài liệu' . ($resourceCount > 0 ? " ($resourceCount)" : ''),
    ];

    if (!in_array($lesson->type, ['quiz', 'assignment'], true)) {
        $tabsList['comments'] = 'Bình luận' . (isset($lessonComments) && $lessonComments->isNotEmpty() ? ' (' . $lessonComments->count() . ')' : '');
    }

    $initialTab = 'overview';
    if (request()->query('tab') === 'comments') {
        $initialTab = 'comments';
    } elseif (request()->query('tab') === 'notes') {
        $initialTab = 'notes';
    } elseif (request()->query('tab') === 'ai') {
        $initialTab = 'ai';
    } elseif (request()->query('tab') === 'resources') {
        $initialTab = 'resources';
    }
@endphp

<div class="learning-tabs border-t border-[#d1d7dc] bg-white" id="learning-tabs">
    <div class="border-b border-[#d1d7dc] px-4 sm:px-6" 
         x-data="{ tab: '{{ $initialTab }}' }"
    >
        <div class="flex gap-1 overflow-x-auto" role="tablist">
            @foreach($tabsList as $key => $label)
                <button
                    type="button"
                    role="tab"
                    data-tab-name="{{ $key }}"
                    class="shrink-0 border-b-2 px-4 py-3 text-sm font-semibold transition"
                    :class="tab === '{{ $key }}' ? 'border-[#1c1d1f] text-[#1c1d1f]' : 'border-transparent text-[#6a6f73] hover:text-[#1c1d1f]'"
                    x-on:click="tab = '{{ $key }}'"
                    :aria-selected="tab === '{{ $key }}'"
                >
                    @if($key === 'comments')
                        <span id="tab-comments-button-text">{{ $label }}</span>
                    @else
                        {{ $label }}
                    @endif
                </button>
            @endforeach
        </div>

        <div class="max-w-4xl px-4 py-6 sm:px-6">
            <div x-show="tab === 'overview'" x-cloak>
                <h1 class="text-xl font-bold text-[#1c1d1f] sm:text-2xl">{{ $lesson->title }}</h1>
                @if($sectionTitle)
                    <p class="mt-1 text-sm text-[#6a6f73]">Chương: {{ $sectionTitle }}</p>
                @endif

                @if($lesson->content)
                    {{-- Collapsible content block --}}
                    <div
                        x-data="{
                            expanded: false,
                            shouldCollapse: false,
                            init() {
                                this.$nextTick(() => {
                                    const el = this.$refs.contentBody;
                                    if (el && el.scrollHeight > 220) {
                                        this.shouldCollapse = true;
                                    }
                                });
                            }
                        }"
                        class="mt-4 relative"
                    >
                        {{-- Content body with max-height clamp when collapsed --}}
                        <div
                            x-ref="contentBody"
                            class="whitespace-pre-line text-sm leading-7 text-[#1c1d1f] overflow-hidden transition-all duration-300"
                            :style="shouldCollapse && !expanded ? 'max-height: 200px;' : 'max-height: none;'"
                        >{{ $lesson->content }}</div>

                        {{-- Fade overlay when collapsed --}}
                        <div
                            x-show="shouldCollapse && !expanded"
                            class="absolute bottom-0 left-0 right-0 h-16 bg-gradient-to-t from-white to-transparent pointer-events-none"
                        ></div>

                        {{-- Toggle button --}}
                        <button
                            x-show="shouldCollapse"
                            x-on:click="expanded = !expanded"
                            type="button"
                            class="mt-2 flex items-center gap-1.5 text-sm font-semibold text-[#0056D2] hover:text-[#0040a0] transition-colors"
                        >
                            <span x-text="expanded ? 'Thu gọn' : 'Xem thêm'"></span>
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4 transition-transform duration-200"
                                :class="expanded ? 'rotate-180' : ''"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                    </div>
                @else
                    <p class="mt-4 text-sm text-[#6a6f73]">Bài học chưa có mô tả chi tiết.</p>
                @endif

                @if($lesson->document_file)
                    @php
                        $overviewExt = strtolower(pathinfo($lesson->document_file, PATHINFO_EXTENSION));
                        $overviewDownloadName = \Illuminate\Support\Str::slug($lesson->title ?: 'tai-lieu') . ($overviewExt ? '.' . $overviewExt : '');
                        $overviewFileUrl = asset('storage/'.$lesson->document_file);
                        $overviewBadgeClass = match($overviewExt) {
                            'pdf' => 'bg-rose-100 text-rose-700 border-rose-200',
                            'doc', 'docx' => 'bg-blue-100 text-blue-700 border-blue-200',
                            'xls', 'xlsx' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                            'ppt', 'pptx' => 'bg-amber-100 text-amber-700 border-amber-200',
                            'zip', 'rar', '7z' => 'bg-purple-100 text-purple-700 border-purple-200',
                            default => 'bg-slate-100 text-slate-700 border-slate-200',
                        };
                        $overviewSizeFormatted = null;
                        try {
                            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($lesson->document_file)) {
                                $bytes = \Illuminate\Support\Facades\Storage::disk('public')->size($lesson->document_file);
                                if ($bytes >= 1048576) {
                                    $overviewSizeFormatted = number_format($bytes / 1048576, 2) . ' MB';
                                } elseif ($bytes >= 1024) {
                                    $overviewSizeFormatted = number_format($bytes / 1024, 1) . ' KB';
                                } else {
                                    $overviewSizeFormatted = $bytes . ' B';
                                }
                            }
                        } catch (\Throwable $e) {}
                    @endphp
                    <div class="mt-6 border-t border-[#d1d7dc] pt-5">
                        <h3 class="text-sm font-bold text-[#1c1d1f]">Tài nguyên đính kèm</h3>
                        <div class="mt-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl border border-[#d1d7dc] bg-[#f7f9fa] p-3.5 transition hover:border-[#0056D2]/50 hover:bg-white hover:shadow-2xs">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border {{ $overviewBadgeClass }} font-black text-xs">
                                    {{ strtoupper($overviewExt ?: 'FILE') }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="font-bold text-xs sm:text-sm text-[#1c1d1f] truncate">
                                        {{ $lesson->title . ($overviewExt ? '.' . $overviewExt : '') }}
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-2 text-[11px] text-[#6a6f73]">
                                        <span>Tài liệu bài học</span>
                                        @if($overviewSizeFormatted)
                                            <span>•</span>
                                            <span>{{ $overviewSizeFormatted }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <a href="{{ $overviewFileUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-8 items-center gap-1 rounded-md border border-[#d1d7dc] bg-white px-3 text-xs font-semibold text-[#1c1d1f] hover:bg-[#f7f9fa] hover:text-[#0056D2]">
                                    <span>Xem</span>
                                </a>
                                <a href="{{ $overviewFileUrl }}" download="{{ $overviewDownloadName }}" class="inline-flex h-8 items-center gap-1 rounded-md bg-[#0056D2] px-3 text-xs font-bold text-white hover:bg-[#0046B8]">
                                    <span>Tải về</span>
                                </a>
                            </div>
                        </div>
                    </div>
                @endif

                <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="font-semibold text-[#6a6f73]">Giảng viên</dt>
                        <dd class="mt-1 text-[#1c1d1f]">{{ $course->instructor?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-[#6a6f73]">Cập nhật</dt>
                        <dd class="mt-1 text-[#1c1d1f]">{{ $lesson->updated_at?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div
                x-show="tab === 'ai'"
                x-cloak
                data-lesson-ai
                data-ai-summary-url="{{ $aiSummaryUrl }}"
                data-ai-explain-url="{{ $aiExplainUrl }}"
                data-can-use-ai="{{ $canUseLessonAi ? '1' : '0' }}"
            >
                @if($canUseLessonAi)
                    <div class="space-y-6">
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-base font-bold text-[#1c1d1f]">Tóm tắt bài học</h2>
                                    <p class="mt-1 text-xs text-[#6a6f73]">Dựa trên nội dung/transcript đã lưu. Không quét video YouTube.</p>
                                </div>
                                <button
                                    type="button"
                                    data-ai-generate-summary
                                    class="inline-flex h-9 items-center rounded bg-[#0056D2] px-3 text-sm font-semibold text-white hover:bg-[#0046B8] disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Tóm tắt bài học
                                </button>
                            </div>

                            <p data-ai-summary-status class="mt-2 text-xs text-[#6a6f73]"></p>
                            <p data-ai-summary-error class="mt-2 hidden text-sm text-red-600"></p>

                            <div data-ai-summary-panel class="mt-3 space-y-4">
                                <div>
                                    <h3 class="text-sm font-bold text-[#1c1d1f]">Tóm tắt ngắn</h3>
                                    <div data-ai-summary-box class="mt-2 min-h-[72px] whitespace-pre-line rounded border border-[#d1d7dc] bg-[#f7f9fa] p-4 text-sm leading-6 text-[#1c1d1f]">
                                        Chưa có bản tóm tắt. Nhấn “Tóm tắt bài học” để tạo.
                                    </div>
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-[#1c1d1f]">Các ý chính</h3>
                                    <ul data-ai-key-points class="mt-2 list-disc space-y-1 pl-5 text-sm text-[#1c1d1f]"></ul>
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-[#1c1d1f]">Kiến thức cần nhớ</h3>
                                    <ul data-ai-takeaways class="mt-2 list-disc space-y-1 pl-5 text-sm text-[#1c1d1f]"></ul>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-[#d1d7dc] pt-5">
                            <h2 class="text-base font-bold text-[#1c1d1f]">AI giải thích</h2>
                            <p class="mt-1 text-xs text-[#6a6f73]">Ưu tiên nội dung bài học, đồng thời có thể giải thích thêm bằng kiến thức học tập liên quan. Không tiết lộ đáp án quiz.</p>

                            <form data-ai-ask-form class="mt-3 space-y-3" novalidate>
                                <label class="block text-sm font-semibold text-[#1c1d1f]" for="lesson-ai-question">Bạn chưa hiểu phần nào?</label>
                                <div>
                                    <textarea
                                        id="lesson-ai-question"
                                        name="question"
                                        rows="3"
                                        maxlength="1000"
                                        data-ai-question-input
                                        class="w-full rounded border border-[#d1d7dc] px-3 py-2 text-sm text-[#1c1d1f] outline-none ring-[#0056D2] focus:ring-2"
                                        placeholder="Ví dụ: Phần routing trong bài này hoạt động thế nào?"
                                    ></textarea>
                                    <p data-ai-ask-error class="mt-1 hidden text-xs font-semibold text-rose-600"></p>
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p data-ai-ask-status class="text-xs text-[#6a6f73]"></p>
                                    <button
                                        type="submit"
                                        data-ai-ask-submit
                                        class="inline-flex h-9 items-center rounded bg-[#1c1d1f] px-4 text-sm font-semibold text-white hover:bg-black disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Giải thích
                                    </button>
                                </div>
                            </form>

                            <div data-ai-chat-log class="mt-4 space-y-3 max-h-[450px] overflow-y-auto rounded-lg border border-[#d1d7dc] bg-white p-3 empty:hidden"></div>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-[#6a6f73]">Bạn cần đăng nhập, xác thực email và ghi danh khóa học để dùng AI hỗ trợ.</p>
                @endif
            </div>

            <div
                x-show="tab === 'notes'"
                x-cloak
                data-lesson-notes
                data-can-use-notes="{{ $canUseLessonNotes ? '1' : '0' }}"
                data-lesson-type="{{ $lesson->type }}"
                data-video-duration="{{ $videoDurationSeconds }}"
                data-store-url="{{ $lessonNotesStoreUrl }}"
                data-index-url="{{ $lessonNotesIndexUrl }}"
            >
                <script type="application/json" data-lesson-notes-json>@json($notesPayload)</script>


                @if($canUseLessonNotes)
                    <div class="space-y-5">
                        <form data-note-create-form class="space-y-3 rounded border border-[#d1d7dc] bg-[#f7f9fa] p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-base font-bold text-[#1c1d1f]">Ghi chú bài học</h2>
                                    <p class="mt-1 text-xs text-[#6a6f73]">Ghi chú chỉ hiển thị với riêng bạn.</p>
                                </div>

                                @if($lesson->type === 'video')
                                    <div class="flex flex-wrap items-center gap-2 text-sm">
                                        <span class="font-semibold text-[#6a6f73]">Mốc:</span>
                                        <input
                                            type="hidden"
                                            name="timestamp_seconds"
                                            data-note-timestamp
                                            value="0"
                                        >
                                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-[#eef5ff] border border-[#d0e2ff] px-2.5 py-1 font-mono text-sm font-bold text-[#0056D2]">
                                            <svg class="h-4 w-4 text-[#0056D2]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span data-note-timestamp-label>00:00</span>
                                        </span>
                                        <span class="text-[11px] text-[#6a6f73] hidden sm:inline">Tự động lấy theo video</span>
                                    </div>
                                @endif
                            </div>

                            <div>
                                <textarea
                                    name="content"
                                    rows="4"
                                    maxlength="2000"
                                    data-note-content
                                    class="w-full rounded border border-[#d1d7dc] bg-white px-3 py-2 text-sm leading-6 text-[#1c1d1f] outline-none focus:ring-2 focus:ring-[#0056D2]"
                                    placeholder="Nhập ghi chú của bạn..."
                                ></textarea>
                                <p data-note-form-error class="hidden mt-1 text-xs font-semibold text-rose-600"></p>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="space-y-1">
                                    <p data-note-form-status class="text-xs text-[#6a6f73]"></p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span data-note-char-count class="text-xs font-semibold text-[#6a6f73]">0/2000</span>
                                    <button type="submit" data-note-submit class="inline-flex h-9 items-center rounded bg-[#0056D2] px-4 text-sm font-bold text-white hover:bg-[#0046B8] disabled:cursor-not-allowed disabled:opacity-60">
                                        Lưu ghi chú
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div>
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <h3 class="text-sm font-bold text-[#1c1d1f]">Ghi chú của bạn</h3>
                                <span data-note-count class="text-xs font-semibold text-[#6a6f73]"></span>
                            </div>
                            <div data-note-empty class="rounded border border-dashed border-[#d1d7dc] p-5 text-center text-sm text-[#6a6f73]">
                                Bạn chưa có ghi chú nào cho bài học này.
                            </div>
                            <div data-note-list class="space-y-3"></div>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-[#6a6f73]">Bạn cần đăng nhập bằng tài khoản học viên và có quyền học khóa này để sử dụng ghi chú.</p>
                @endif
            </div>

            <div x-show="tab === 'resources'" x-cloak class="space-y-6">
                @php
                    $hasDocumentFile = !empty($lesson->document_file);
                    $attachments = is_array($lesson->attachments) ? $lesson->attachments : [];
                    $hasAnyResources = $hasDocumentFile || !empty($attachments);
                @endphp

                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#d1d7dc] pb-4">
                    <div>
                        <h2 class="text-base font-bold text-[#1c1d1f]">Tài liệu & Tệp đính kèm</h2>
                        <p class="mt-1 text-xs text-[#6a6f73]">Danh sách tài liệu học tập, slide bài giảng hoặc bài tập do giảng viên cung cấp cho bài học này.</p>
                    </div>
                </div>

                @if($hasAnyResources)
                    <div class="space-y-3">
                        {{-- Tài liệu chính (document_file) --}}
                        @if($hasDocumentFile)
                            @php
                                $ext = strtolower(pathinfo($lesson->document_file, PATHINFO_EXTENSION));
                                $downloadName = \Illuminate\Support\Str::slug($lesson->title ?: 'tai-lieu') . ($ext ? '.' . $ext : '');
                                $rawFileName = basename($lesson->document_file);
                                $fileUrl = asset('storage/' . $lesson->document_file);

                                $badgeClass = match($ext) {
                                    'pdf' => 'bg-rose-100 text-rose-700 border-rose-200',
                                    'doc', 'docx' => 'bg-blue-100 text-blue-700 border-blue-200',
                                    'xls', 'xlsx' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                    'ppt', 'pptx' => 'bg-amber-100 text-amber-700 border-amber-200',
                                    'zip', 'rar', '7z' => 'bg-purple-100 text-purple-700 border-purple-200',
                                    default => 'bg-slate-100 text-slate-700 border-slate-200',
                                };

                                $formatLabel = strtoupper($ext ?: 'FILE');

                                $fileSizeFormatted = null;
                                try {
                                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($lesson->document_file)) {
                                        $bytes = \Illuminate\Support\Facades\Storage::disk('public')->size($lesson->document_file);
                                        if ($bytes >= 1048576) {
                                            $fileSizeFormatted = number_format($bytes / 1048576, 2) . ' MB';
                                        } elseif ($bytes >= 1024) {
                                            $fileSizeFormatted = number_format($bytes / 1024, 1) . ' KB';
                                        } else {
                                            $fileSizeFormatted = $bytes . ' B';
                                        }
                                    }
                                } catch (\Throwable $e) {}
                            @endphp

                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-xl border border-[#d1d7dc] bg-[#f7f9fa] p-4 transition duration-150 hover:border-[#0056D2]/50 hover:bg-white hover:shadow-sm">
                                <div class="flex items-start gap-3.5 min-w-0">
                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border {{ $badgeClass }} font-black text-xs shadow-2xs">
                                        {{ $formatLabel }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-bold text-sm text-[#1c1d1f] truncate" title="{{ $lesson->title ?: $rawFileName }}">
                                            {{ $lesson->title ? $lesson->title . ($ext ? '.' . $ext : '') : $rawFileName }}
                                        </h3>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[#6a6f73]">
                                            <span class="inline-flex items-center gap-1 font-semibold text-[#0056D2]">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                Tài liệu bài giảng
                                            </span>
                                            <span>•</span>
                                            <span>Định dạng: {{ $formatLabel }}</span>
                                            @if($fileSizeFormatted)
                                                <span>•</span>
                                                <span>{{ $fileSizeFormatted }}</span>
                                            @endif
                                            <span>•</span>
                                            <span>Cập nhật: {{ $lesson->updated_at?->format('d/m/Y') }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 shrink-0 sm:self-center">
                                    <a
                                        href="{{ $fileUrl }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#d1d7dc] bg-white px-3.5 text-xs font-bold text-[#1c1d1f] shadow-2xs transition hover:bg-[#f7f9fa] hover:border-[#0056D2] hover:text-[#0056D2]"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                        <span>Xem tệp</span>
                                    </a>
                                    <a
                                        href="{{ $fileUrl }}"
                                        download="{{ $downloadName }}"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#0056D2] px-3.5 text-xs font-bold text-white shadow-2xs transition hover:bg-[#0046B8]"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        <span>Tải về</span>
                                    </a>
                                </div>
                            </div>
                        @endif

                        {{-- Danh sách attachments bổ sung nếu có --}}
                        @foreach($attachments as $item)
                            @php
                                $itemPath = is_array($item) ? ($item['path'] ?? $item['file'] ?? '') : (string) $item;
                                $itemTitle = is_array($item) ? ($item['title'] ?? $item['name'] ?? basename($itemPath)) : basename($itemPath);
                                $itemExt = strtolower(pathinfo($itemPath, PATHINFO_EXTENSION));
                                $itemDownloadName = \Illuminate\Support\Str::slug($itemTitle) . ($itemExt ? '.' . $itemExt : '');
                                $itemUrl = asset('storage/' . $itemPath);
                            @endphp
                            @if($itemPath)
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-xl border border-[#d1d7dc] bg-[#f7f9fa] p-4 transition hover:bg-white hover:border-[#0056D2]/50 hover:shadow-sm">
                                    <div class="flex items-start gap-3.5 min-w-0">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border bg-slate-100 text-slate-700 border-slate-200 font-black text-xs shadow-2xs">
                                            {{ strtoupper($itemExt ?: 'FILE') }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h3 class="font-bold text-sm text-[#1c1d1f] truncate" title="{{ $itemTitle }}">
                                                {{ $itemTitle }}
                                            </h3>
                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[#6a6f73]">
                                                <span class="inline-flex items-center gap-1 font-semibold text-slate-600">
                                                    Tệp đính kèm bổ trợ
                                                </span>
                                                <span>•</span>
                                                <span>{{ strtoupper($itemExt) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0 sm:self-center">
                                        <a href="{{ $itemUrl }}" target="_blank" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#d1d7dc] bg-white px-3.5 text-xs font-bold text-[#1c1d1f] shadow-2xs hover:bg-[#f7f9fa]">
                                            <span>Mở tệp</span>
                                        </a>
                                        <a href="{{ $itemUrl }}" download="{{ $itemDownloadName }}" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#0056D2] px-3.5 text-xs font-bold text-white shadow-2xs hover:bg-[#0046B8]">
                                            <span>Tải về</span>
                                        </a>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-[#d1d7dc] bg-[#f7f9fa]/60 p-8 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <h3 class="mt-3 text-sm font-bold text-[#1c1d1f]">Không có tài liệu đính kèm</h3>
                        <p class="mt-1 text-xs text-[#6a6f73] max-w-sm mx-auto">
                            Bài học này hiện chưa có tệp tài liệu đính kèm. Giảng viên sẽ bổ sung slide hoặc file tài liệu tham khảo khi cần thiết.
                        </p>
                    </div>
                @endif
            </div>

            @if(!in_array($lesson->type, ['quiz', 'assignment'], true))
                <div x-show="tab === 'comments'" x-cloak>
                    <x-learning.lesson-comments
                        :lesson="$lesson"
                        :course="$course"
                        :comments="$lessonComments"
                        :is-enrolled="$isEnrolled"
                    />
                </div>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#d1d7dc] px-4 py-4 sm:px-6">
        <div>
            @if($navigation['previous'])
                <a href="{{ $navigation['previous']['url'] }}" class="inline-flex h-10 items-center rounded border border-[#d1d7dc] px-4 text-sm font-semibold text-[#1c1d1f] hover:bg-[#f7f9fa]">
                    ← Bài trước
                </a>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if($navigation['next'])
                <a href="{{ $navigation['next']['url'] }}" class="inline-flex h-10 items-center rounded bg-[#1c1d1f] px-4 text-sm font-bold text-white hover:bg-black">
                    Bài tiếp theo →
                </a>
            @endif
        </div>
    </div>
</div>
