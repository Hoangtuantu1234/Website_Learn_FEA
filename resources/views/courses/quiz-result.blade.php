@extends('layouts.app')

@section('title', 'Kết quả Quiz - ' . $quiz->title)

@section('content')
@php
    $questionsReview = $review['questions'] ?? null;
    $allAttempts = $review['all_attempts'] ?? [];
    $currentAttemptNumber = $review['attempt_number'] ?? null;
    $reviewMode = $review['review_mode'] ?? 'restricted';
    $isFullReview = $reviewMode === 'full';
    $reviewRestrictionReason = $review['review_restriction_reason'] ?? null;
@endphp

<section class="bg-slate-50 py-8 dark:bg-[#0a0a0a] min-h-[calc(100vh-4rem)]">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        {{-- Header Breadcrumb & Status --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 dark:border-slate-800 pb-5">
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 dark:text-slate-400">
                    <a href="{{ route('courses.show', $course->slug) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                        {{ $course->title }}
                    </a>
                    <span>/</span>
                    <span>{{ $lesson->title }}</span>
                </div>
                <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950 dark:text-white sm:text-3xl flex items-center gap-3">
                    <span>Xem lại kết quả bài Quiz</span>
                    @if($currentAttemptNumber)
                        <span class="text-base font-semibold px-3 py-1 rounded-full bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            Lần làm #{{ $currentAttemptNumber }}
                        </span>
                    @endif
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400 font-medium">{{ $quiz->title }}</p>
            </div>
            
            <div class="flex flex-col sm:items-end gap-1">
                <span class="inline-flex w-fit items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-extrabold ring-1 {{ $attempt->passed ? 'bg-emerald-50 text-emerald-700 ring-emerald-300 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' : 'bg-rose-50 text-rose-700 ring-rose-300 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' }}">
                    @if($attempt->passed)
                        <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        ĐẠT YÊU CẦU
                    @else
                        <svg class="w-4 h-4 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                        CHƯA ĐẠT
                    @endif
                </span>
                @if($attempt->completed_at)
                    <time class="text-xs text-slate-400">{{ $attempt->completed_at->format('d/m/Y H:i') }}</time>
                @endif
            </div>
        </div>

        {{-- Multiple Attempts Switcher --}}
        @if(count($allAttempts) > 1)
            <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-[#161615]">
                <span class="block text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3">
                    Lịch sử các lần làm bài ({{ count($allAttempts) }} lần):
                </span>
                <div class="flex flex-wrap gap-2">
                    @foreach($allAttempts as $att)
                        @php
                            $isCurrent = (int)$att['id'] === (int)$attempt->id;
                        @endphp
                        <a href="{{ route('learn.lessons.quiz.attempts.show', [$course->slug, $lesson, $att['id']]) }}"
                           class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-bold transition border {{ $isCurrent ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm' : 'bg-slate-50 text-slate-700 hover:bg-slate-100 border-slate-200 dark:bg-slate-900/60 dark:text-slate-300 dark:border-slate-800 dark:hover:bg-slate-800' }}">
                            <span>Lần {{ $att['attempt_number'] }}</span>
                            <span class="px-1.5 py-0.5 rounded text-[11px] {{ $isCurrent ? 'bg-white/20 text-white' : ($att['passed'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300') }}">
                                {{ number_format((float)$att['percent'], 0) }}%
                            </span>
                            @if($isCurrent)
                                <span class="text-[10px] uppercase bg-white text-indigo-700 font-extrabold px-1 rounded">Đang xem</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Statistics Overview Card --}}
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-[#161615] sm:p-6 mb-6">
            <div class="grid gap-3 grid-cols-2 sm:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/70 border border-slate-100 dark:border-slate-800">
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Điểm số</span>
                    <strong class="mt-1 block text-2xl font-black text-slate-950 dark:text-white">{{ $attempt->score }} / {{ $attempt->total_score }}</strong>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/70 border border-slate-100 dark:border-slate-800">
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Tỷ lệ đạt</span>
                    <strong class="mt-1 block text-2xl font-black {{ $attempt->passed ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                        {{ number_format((float) $attempt->percent, 1) }}%
                    </strong>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/70 border border-slate-100 dark:border-slate-800">
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Điểm yêu cầu</span>
                    <strong class="mt-1 block text-2xl font-black text-slate-950 dark:text-white">{{ $quiz->pass_score }}%</strong>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/70 border border-slate-100 dark:border-slate-800">
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Số câu đúng</span>
                    <strong class="mt-1 block text-2xl font-black text-slate-950 dark:text-white">
                        {{ $review['correct_questions_count'] ?? 0 }} / {{ $review['total_questions'] ?? 0 }}
                    </strong>
                </div>
            </div>
        </article>

        {{-- Question List Review --}}
        @unless($isFullReview)
            <aside class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100" role="status">
                @if($reviewRestrictionReason === 'abnormal_end')
                    Lượt làm bài này kết thúc bất thường nên đáp án đúng và lời giải không được hiển thị.
                @else
                    Đáp án đúng và lời giải sẽ được hiển thị khi bạn đã sử dụng hết số lượt làm bài.
                @endif
            </aside>
        @endunless

        <div class="space-y-6">
            @if(is_array($questionsReview))
                @foreach($questionsReview as $q)
                    <article class="rounded-2xl border {{ $q['is_unanswered'] ? 'border-amber-200/80 dark:border-amber-500/20 bg-white dark:bg-[#161615]' : ($q['is_correct'] ? 'border-emerald-200/80 dark:border-emerald-500/20 bg-white dark:bg-[#161615]' : 'border-rose-200/80 dark:border-rose-500/20 bg-white dark:bg-[#161615]') }} p-5 shadow-sm sm:p-6 transition">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between border-b border-slate-100 dark:border-slate-800/80 pb-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-extrabold ring-1 {{ $q['is_unanswered'] ? 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' : ($q['is_correct'] ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' : 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30') }}">
                                        @if($q['is_unanswered'])
                                            CHƯA TRẢ LỜI
                                        @elseif($q['is_correct'])
                                            <svg class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            ĐÚNG
                                        @else
                                            <svg class="w-3.5 h-3.5 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                            SAI
                                        @endif
                                    </span>
                                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                                        {{ $q['form_type'] ?? $q['type'] }}
                                    </span>
                                </div>
                                <h2 class="mt-3 text-base font-bold text-slate-950 dark:text-white leading-relaxed">
                                    Câu {{ $q['question_number'] }}. <span data-math-content>{{ $q['question'] }}</span>
                                </h2>
                            </div>
                            <span class="shrink-0 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                {{ $q['points'] }} điểm
                            </span>
                        </div>

                        <div class="mt-4 space-y-2.5">
                            @foreach($q['options'] as $option)
                                @php
                                    $isSelected = $option['is_selected'];
                                    $isCorrect = $isFullReview ? ($option['is_correct'] ?? false) : null;
                                    $selectedCorrect = $isSelected
                                        ? ($isFullReview ? $isCorrect : ($option['selected_correct'] ?? false))
                                        : null;
                                    $isStaff = auth()->check() && (auth()->user()->isAdmin() || (auth()->user()->isInstructor() && $course->isOwnedBy(auth()->user())));

                                    if ($isSelected && $selectedCorrect) {
                                        // Student selected correctly
                                        $boxClass = 'border-emerald-500 bg-emerald-50/70 text-emerald-950 ring-1 ring-emerald-500/40 dark:border-emerald-500/50 dark:bg-emerald-500/10 dark:text-emerald-100';
                                    } elseif ($isSelected && !$selectedCorrect) {
                                        // Student selected incorrectly
                                        $boxClass = 'border-rose-500 bg-rose-50/70 text-rose-950 ring-1 ring-rose-500/40 dark:border-rose-500/50 dark:bg-rose-500/10 dark:text-rose-100';
                                    } elseif ($isStaff && $isFullReview && !$isSelected && $isCorrect) {
                                        // Correct option that wasn't selected (only visible to staff)
                                        $boxClass = 'border-emerald-400 bg-emerald-50/30 text-emerald-900 border-dashed dark:border-emerald-500/40 dark:bg-emerald-500/5 dark:text-emerald-200';
                                    } else {
                                        // Normal neutral option
                                        $boxClass = 'border-slate-200 text-slate-700 dark:border-slate-800 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-900/40';
                                    }
                                @endphp

                                <div class="flex items-start justify-between gap-3 rounded-xl border p-3.5 text-sm transition {{ $boxClass }}">
                                    <div class="flex items-start gap-2.5 min-w-0">
                                        <div class="mt-0.5 shrink-0">
                                            @if($isSelected && $selectedCorrect)
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-600 text-white text-xs font-black shadow-xs">✓</span>
                                            @elseif($isSelected && !$selectedCorrect)
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-white text-xs font-black shadow-xs">✗</span>
                                            @elseif($isStaff && $isFullReview && !$isSelected && $isCorrect)
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-black ring-1 ring-emerald-500/40">✓</span>
                                            @else
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-slate-300 text-slate-400 text-xs dark:border-slate-700"></span>
                                            @endif
                                        </div>
                                        <span class="leading-6 font-medium" data-math-content>{{ $option['option_text'] }}</span>
                                    </div>

                                    <div class="flex shrink-0 flex-wrap justify-end gap-1.5">
                                        @if($isSelected && $selectedCorrect)
                                            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2 py-0.5 text-xs font-bold text-white shadow-xs">
                                                ✓ Bạn chọn đúng
                                            </span>
                                        @elseif($isSelected && !$selectedCorrect)
                                            <span class="inline-flex items-center gap-1 rounded-md bg-rose-600 px-2 py-0.5 text-xs font-bold text-white shadow-xs">
                                                ✗ Bạn chọn sai
                                            </span>
                                        @elseif($isStaff && $isFullReview && !$isSelected && $isCorrect)
                                            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 text-xs font-bold dark:bg-emerald-950/80 dark:text-emerald-300 dark:border-emerald-700">
                                                ✓ Đáp án đúng
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if($isFullReview && !empty($q['explanation']) && ($isStaff || !empty($q['is_correct'])))
                            <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-700 border border-slate-200/80 dark:bg-slate-900/60 dark:text-slate-300 dark:border-slate-800 flex items-start gap-2.5">
                                <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <div>
                                    <strong class="text-slate-900 dark:text-white font-bold">Giải thích:</strong>
                                    <span class="ml-1" data-math-content>{{ $q['explanation'] }}</span>
                                </div>
                            </div>
                        @endif
                    </article>
                @endforeach
            @else
                <p class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100">
                    Không thể tải dữ liệu xem lại an toàn cho lượt làm bài này.
                </p>
            @endif
        </div>

        {{-- Bottom Actions --}}
        <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 dark:border-slate-800 pt-6">
            <a href="{{ route('courses.lessons.show', [$course, $lesson]) }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-slate-300 px-5 text-sm font-extrabold text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Quay lại bài học
            </a>

            <div class="flex flex-wrap items-center gap-3">
                @if($review['has_remaining_attempts'] ?? false)
                    <a href="{{ route('learn.lessons.quiz.show', [$course->slug, $lesson]) }}" class="inline-flex h-11 items-center rounded-xl border border-indigo-600 px-5 text-sm font-extrabold text-indigo-600 transition hover:bg-indigo-50 dark:border-indigo-400 dark:text-indigo-400 dark:hover:bg-indigo-950/50">
                        Làm lại Quiz
                    </a>
                @endif
                <a href="{{ route('courses.show', $course->slug) }}" class="inline-flex h-11 items-center rounded-xl bg-indigo-600 px-6 text-sm font-extrabold text-white transition hover:bg-indigo-700 shadow-sm">
                    Về trang khóa học
                </a>
            </div>
        </div>
    </div>
</section>
@endsection
