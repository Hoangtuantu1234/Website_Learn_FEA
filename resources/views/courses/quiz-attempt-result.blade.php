@extends('layouts.quiz')

@section('title', 'Ket qua quiz - ' . $result['version']->title)

@section('content')
@php
    $isFullReview = ($result['review_mode'] ?? 'restricted') === 'full';
    $reviewRestrictionReason = $result['review_restriction_reason'] ?? null;
@endphp
<section class="bg-slate-50 py-8 dark:bg-[#0a0a0a]">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <a href="{{ route('courses.lessons.show', [$course, $lesson]) }}" class="text-sm font-bold text-indigo-600 hover:underline dark:text-indigo-300">Quay lại bài học</a>
                <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950 dark:text-white sm:text-3xl">Ket qua Quiz - Phien ban V{{ $result['version']->version }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $result['version']->title }}</p>
            </div>
            <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold ring-1 {{ $result['attempt']->passed ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' : 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' }}">{{ $result['attempt']->passed ? 'Dat' : 'Chua dat' }}</span>
        </div>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-[#161615] sm:p-6">
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/70"><span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Diem</span><strong class="mt-1 block text-2xl text-slate-950 dark:text-white">{{ $result['attempt']->score }}</strong></div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/70"><span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Tong diem</span><strong class="mt-1 block text-2xl text-slate-950 dark:text-white">{{ $result['attempt']->total_score }}</strong></div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/70"><span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Phan tram</span><strong class="mt-1 block text-2xl text-slate-950 dark:text-white">{{ number_format((float) $result['attempt']->percent, 2) }}%</strong></div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/70"><span class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Diem dat</span><strong class="mt-1 block text-2xl text-slate-950 dark:text-white">{{ $result['version']->pass_score }}%</strong></div>
            </div>
        </article>

        @if($result['attempt']->termination_reason !== \App\Models\QuizAttempt::REASON_SUBMITTED)
            <aside class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-900 dark:border-rose-500/30 dark:bg-rose-950/30 dark:text-rose-100" role="status">
                <p class="font-extrabold">Phiên làm bài đã kết thúc.</p>
                <p class="mt-1">Lý do: {{ $result['attempt']->getTerminationReasonLabel() }}. Kết quả đã được ghi nhận.</p>
            </aside>
        @endif

        @if(!$result['regrade'] && collect($result['questions'])->contains('is_excluded', true))
            <aside class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm font-semibold text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100" role="status">
                Câu hỏi đã bị hủy — không tính điểm. Điểm hiện tại được tính trên các câu hỏi hợp lệ.
            </aside>
        @endif

        @if($result['regrade'])
            @php
                $regrade = $result['regrade'];
            @endphp
            <aside class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100" role="status">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.3 3.9 2.6 17.1A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                    <div>
                        <p class="font-extrabold">Kết quả đã được tính lại vì có câu hỏi bị hủy.</p>
                        <p class="mt-1">Theo các câu hỏi hợp lệ: {{ $regrade->recalculated_score }}/{{ $regrade->recalculated_total_score }} ({{ number_format((float) $regrade->recalculated_percent, 2) }}%) — {{ $regrade->recalculated_passed ? 'Đạt' : 'Chưa đạt' }}.</p>
                        @if((int) $regrade->effective_score !== (int) $regrade->recalculated_score || (float) $regrade->effective_percent !== (float) $regrade->recalculated_percent || (bool) $regrade->effective_passed !== (bool) $regrade->recalculated_passed)
                            <p class="mt-1 font-bold">Kết quả cũ được giữ nguyên để không ảnh hưởng người học: {{ $regrade->effective_score }}/{{ $regrade->effective_total_score }} ({{ number_format((float) $regrade->effective_percent, 2) }}%) — {{ $regrade->effective_passed ? 'Đạt' : 'Chưa đạt' }}.</p>
                        @endif
                    </div>
                </div>
            </aside>
        @endif

        @unless($isFullReview)
            <aside class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100" role="status">
                @if($reviewRestrictionReason === 'abnormal_end')
                    Lượt làm bài này kết thúc bất thường nên đáp án đúng và lời giải không được hiển thị.
                @else
                    Đáp án đúng và lời giải sẽ được hiển thị khi bạn đã sử dụng hết số lượt làm bài.
                @endif
            </aside>
        @endunless

        <div class="mt-6 space-y-5">
            @foreach($result['questions'] as $question)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-[#161615] sm:p-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ ($question['is_excluded'] || $question['is_unanswered']) ? 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/30' : ($question['is_correct'] ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' : 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30') }}">{{ $question['is_excluded'] ? 'Câu hỏi đã bị hủy — không tính điểm' : ($question['is_unanswered'] ? 'Chưa trả lời' : ($question['is_correct'] ? 'Đúng' : 'Sai')) }}</span>
                            <h2 class="mt-3 text-base font-extrabold text-slate-950 dark:text-white">Câu {{ $question['number'] }}. <span data-math-content>{{ $question['question'] }}</span></h2>
                            @if($question['image_url'] ?? null)
                                <img src="{{ $question['image_url'] }}" alt="Minh họa câu hỏi" class="mt-3 max-h-72 w-full rounded-lg object-contain">
                            @endif
                        </div>
                        <span class="rounded-lg bg-slate-100 px-3 py-2 text-sm font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $question['is_excluded'] ? 'Không tính điểm' : $question['points'].' điểm' }}</span>
                    </div>

                    @if($question['is_excluded'])
                        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">Câu hỏi đã bị hủy — không tính điểm.</p>
                    @endif

                    @if($question['is_unanswered'])
                        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">Chưa trả lời.</p>
                    @elseif($question['has_missing_selection'])
                        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">Đáp án trước đây không còn khả dụng.</p>
                    @endif

                    <div class="mt-4 space-y-2">
                        @foreach($question['options'] as $option)
                            @php
                                $isSelected = $option['is_selected'];
                                $isCorrect = $isFullReview ? ($option['is_correct'] ?? false) : null;
                                $selectedCorrect = $isSelected
                                    ? ($isFullReview ? $isCorrect : ($option['selected_correct'] ?? false))
                                    : null;
                                $optionClass = ($isSelected && $selectedCorrect)
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100'
                                    : (($isSelected && !$selectedCorrect)
                                        ? 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100'
                                        : 'border-slate-200 text-slate-700 dark:border-slate-800 dark:text-slate-200');
                            @endphp
                            <div class="flex items-start justify-between gap-3 rounded-xl border p-3 text-sm {{ $optionClass }}">
                                <span class="leading-6" data-math-content>{{ $option['text'] }}</span>
                                <div class="flex shrink-0 flex-wrap justify-end gap-2">
                                    @if($isSelected && $selectedCorrect)<span class="rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-bold text-white">✓ Bạn chọn đúng</span>@endif
                                    @if($isSelected && !$selectedCorrect)<span class="rounded-full bg-rose-600 px-2 py-0.5 text-xs font-bold text-white">✗ Bạn chọn sai</span>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($isFullReview && !empty($question['explanation']) && !empty($question['is_correct']))
                        <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600 dark:bg-slate-900/70 dark:text-slate-300"><strong class="text-slate-900 dark:text-white">Giải thích:</strong> <span data-math-content>{{ $question['explanation'] }}</span></div>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</section>
@endsection
