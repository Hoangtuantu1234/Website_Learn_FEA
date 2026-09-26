@extends('layouts.app')

@section('title', 'Khóa học - Fea LMS')

@section('content')
@php
    $pricingOptions = [
        '' => 'Tất cả mức giá',
        'free' => 'Miễn phí (0đ)',
        '0_200k' => 'Từ 0đ - 200.000đ',
        '200k_500k' => 'Từ 200.000đ - 500.000đ',
        '500k_1tr' => 'Từ 500.000đ - 1.000.000đ',
        '1tr_2tr' => 'Từ 1.000.000đ - 2.000.000đ',
        '2tr_5tr' => 'Từ 2.000.000đ - 5.000.000đ',
    ];
    $ratingOptions = [
        '' => 'Tất cả đánh giá',
        '1' => '★ 1.0 đến dưới 2.0 sao',
        '2' => '★ 2.0 đến dưới 3.0 sao',
        '3' => '★ 3.0 đến dưới 4.0 sao',
        '4' => '★ 4.0 đến dưới 5.0 sao',
        '5' => '★ 5.0 sao',
    ];
    $categoryCount = $categories->sum(fn ($parent) => $parent->children->count());
    $overviewSections = $showCourseOverview ? [
        [
            'key' => 'all',
            'title' => 'Danh sách khóa học',
            'description' => 'Các khóa học mới nhất đã được kiểm duyệt và sẵn sàng đăng ký.',
            'items' => $allCoursesPreview,
            'moreUrl' => route('courses.index', ['view' => 'all']),
        ],
        [
            'key' => 'paid',
            'title' => 'Khóa học trả phí',
            'description' => 'Nội dung chuyên sâu với lộ trình, bài giảng và hỗ trợ đầy đủ.',
            'items' => $paidCoursesPreview,
            'moreUrl' => route('courses.index', ['pricing' => 'paid']),
        ],
        [
            'key' => 'free',
            'title' => 'Khóa học miễn phí',
            'description' => 'Bắt đầu học ngay với những khóa học hoàn toàn miễn phí.',
            'items' => $freeCoursesPreview,
            'moreUrl' => route('courses.index', ['pricing' => 'free']),
        ],
    ] : [];

    $categoriesJson = $categories->map(function ($parent) {
        return [
            'id' => $parent->id,
            'name' => $parent->name,
            'slug' => $parent->slug,
            'children' => $parent->children->map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'slug' => $child->slug,
                    'count' => $child->courses_count,
                ];
            })->values()->all(),
        ];
    })->values()->all();

    $currentCategoryLabel = $selectedCategory ? $selectedCategory->name : 'Tất cả danh mục';
    $currentCategorySlug = $selectedCategory ? $selectedCategory->slug : '';
    $hasActiveFilters = filled($search) || filled($selectedCategory) || filled($level) || filled($pricing) || filled($minPrice) || filled($maxPrice) || filled($rating);
@endphp

<section class="bg-slate-950 text-white">
    <div class="mx-auto max-w-[1536px] px-4 pt-6 sm:px-6 lg:px-8">
        <button type="button" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '{{ route('home') }}'; }" class="inline-flex items-center gap-2 text-sm sm:text-base font-bold text-blue-300 hover:text-white cursor-pointer transition py-1">
            ← Quay lại
        </button>
    </div>
    <div class="mx-auto grid max-w-[1536px] gap-10 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:px-8 lg:py-12">
        <div>
            <span class="inline-flex rounded-full border border-indigo-400/30 bg-indigo-500/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-indigo-200">
                Fea Course Catalog
            </span>
            <h1 class="mt-5 max-w-3xl text-4xl font-extrabold tracking-tight sm:text-5xl">
                Khám phá khóa học đang mở trên Fea LMS
            </h1>
            <p class="mt-4 max-w-2xl text-base leading-7 text-slate-300 sm:text-lg">
                Tìm khóa học phù hợp theo danh mục, trình độ và khoảng giá mong muốn. Tất cả khóa học tại đây đã được admin duyệt và sẵn sàng cho học viên đăng ký.
            </p>
            <div class="mt-7 flex flex-wrap gap-3 text-sm text-slate-300">
                <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2">{{ $courses->total() }} khóa học đã xuất bản</span>
                <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2">{{ $categoryCount }} danh mục</span>
                <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2">Học thử với bài preview</span>
            </div>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/10 p-5 shadow-2xl shadow-indigo-950/40 backdrop-blur">
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-white/10 p-4">
                    <span class="text-xs font-semibold uppercase tracking-wide text-indigo-200">Learning</span>
                    <strong class="mt-2 block text-2xl">Online</strong>
                </div>
                <div class="rounded-xl bg-white/10 p-4">
                    <span class="text-xs font-semibold uppercase tracking-wide text-emerald-200">Progress</span>
                    <strong class="mt-2 block text-2xl">Theo dõi</strong>
                </div>
                <div class="col-span-2 rounded-xl bg-slate-950/60 p-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-semibold text-slate-200">Khóa học mới cập nhật</span>
                        <span class="text-indigo-200">Live</span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/10">
                        <div class="h-full w-3/4 rounded-full bg-gradient-to-r from-indigo-400 to-violet-400"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="bg-slate-50 py-10 dark:bg-[#0a0a0a]">
    <div class="mx-auto max-w-[1536px] px-4 sm:px-6 lg:px-8">
        {{-- BỘ LỌC TÌM KIẾM HIỆN ĐẠI --}}
        <form method="GET" action="{{ route('courses.index') }}" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-md shadow-slate-200/50 dark:border-slate-800 dark:bg-[#161615] dark:shadow-none sm:p-6 transition">
            {{-- Hàng lọc chính --}}
            <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.4fr)_minmax(160px,.8fr)_minmax(140px,.7fr)_minmax(170px,.9fr)_minmax(140px,.7fr)_auto]">
                {{-- Ô tìm kiếm --}}
                <div class="relative">
                    <x-course-search-suggest :value="$search" placeholder="Tìm tên khóa học, giảng viên..." input-class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/70 pl-10 pr-4 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-800 dark:bg-slate-950 dark:text-white dark:focus:bg-slate-900" />
                </div>

                {{-- Danh mục có ô tìm kiếm nhanh --}}
                <div class="relative" x-data="{
                    open: false,
                    catSearch: '',
                    selectedSlug: '{{ $currentCategorySlug }}',
                    selectedName: '{{ addslashes($currentCategoryLabel) }}',
                    allCategories: {{ json_encode($categoriesJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) }},
                    get filteredCategories() {
                        if (!this.catSearch.trim()) return this.allCategories;
                        const q = this.catSearch.toLowerCase().trim();
                        return this.allCategories.map(parent => {
                            const parentMatches = parent.name.toLowerCase().includes(q);
                            const matchingChildren = parent.children ? parent.children.filter(c => c.name.toLowerCase().includes(q)) : [];
                            if (parentMatches || matchingChildren.length > 0) {
                                return {
                                    ...parent,
                                    children: parentMatches ? parent.children : matchingChildren
                                };
                            }
                            return null;
                        }).filter(Boolean);
                    },
                    selectCategory(slug, name) {
                        this.selectedSlug = slug;
                        this.selectedName = name;
                        this.open = false;
                    }
                }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <input type="hidden" name="category" :value="selectedSlug">

                    <!-- Nút bấm mở dropdown -->
                    <button type="button" @click="open = !open; if (open) $nextTick(() => $refs.catSearchInput.focus())"
                            class="flex h-11 w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 text-left text-sm text-slate-900 outline-none transition hover:bg-white focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-800 dark:bg-slate-950 dark:text-white dark:focus:bg-slate-900">
                        <span class="truncate pr-2" x-text="selectedName"></span>
                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <!-- Popup tìm kiếm & danh sách danh mục -->
                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1 scale-95" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 translate-y-0 scale-100" x-transition:leave-end="opacity-0 translate-y-1 scale-95"
                         class="absolute left-0 top-full z-50 mt-1.5 w-72 sm:w-80 max-h-80 rounded-2xl border border-slate-200 bg-white p-2.5 shadow-xl shadow-slate-900/10 dark:border-slate-800 dark:bg-slate-900">
                        
                        <!-- Ô tìm kiếm danh mục -->
                        <div class="relative mb-2">
                            <div class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </div>
                            <input type="text" x-ref="catSearchInput" x-model="catSearch"
                                   placeholder="Tìm kiếm danh mục..."
                                   class="h-9 w-full rounded-lg border border-slate-200 bg-slate-50 pl-8 pr-3 text-xs text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-slate-800 dark:bg-slate-950 dark:text-white">
                        </div>

                        <!-- Danh sách danh mục cuộn -->
                        <div class="max-h-56 overflow-y-auto space-y-1 px-1 custom-scrollbar">
                            <!-- Tất cả danh mục -->
                            <button type="button" @click="selectCategory('', 'Tất cả danh mục')"
                                    class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-xs font-semibold transition"
                                    :class="selectedSlug === '' ? 'bg-indigo-50 text-indigo-700 font-bold dark:bg-indigo-950/50 dark:text-indigo-300' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800'">
                                <span>Tất cả danh mục</span>
                                <span x-show="selectedSlug === ''" class="text-indigo-600 dark:text-indigo-400">✓</span>
                            </button>

                            <!-- Danh mục cha & con -->
                            <template x-for="parent in filteredCategories" :key="parent.id">
                                <div class="pt-1.5 first:pt-0">
                                    <!-- Danh mục cha -->
                                    <button type="button" @click="selectCategory(parent.slug, 'Tất cả ' + parent.name)"
                                            class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-xs font-bold transition"
                                            :class="selectedSlug === parent.slug ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300' : 'bg-slate-50/70 text-slate-900 hover:bg-slate-100 dark:bg-slate-800/40 dark:text-slate-100 dark:hover:bg-slate-800'">
                                        <span x-text="parent.name"></span>
                                        <span x-show="selectedSlug === parent.slug" class="text-indigo-600 dark:text-indigo-400 font-bold">✓</span>
                                    </button>

                                    <!-- Danh mục con -->
                                    <template x-if="parent.children && parent.children.length > 0">
                                        <div class="pl-2 space-y-0.5 mt-0.5 border-l-2 border-slate-100 dark:border-slate-800 ml-2">
                                            <template x-for="child in parent.children" :key="child.id">
                                                <button type="button" @click="selectCategory(child.slug, child.name)"
                                                        class="flex w-full items-center justify-between rounded-md px-2 py-1 text-xs transition"
                                                        :class="selectedSlug === child.slug ? 'bg-indigo-50 text-indigo-700 font-bold dark:bg-indigo-950/50 dark:text-indigo-300' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-200'">
                                                    <span class="truncate" x-text="child.name"></span>
                                                    <div class="flex items-center gap-1 shrink-0 ml-2">
                                                        <span class="text-[10px] text-slate-400" x-text="'(' + child.count + ')'"></span>
                                                        <span x-show="selectedSlug === child.slug" class="text-indigo-600 dark:text-indigo-400 font-bold">✓</span>
                                                    </div>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <!-- Khi không tìm thấy -->
                            <div x-show="filteredCategories.length === 0" class="py-4 text-center text-xs text-slate-400">
                                Không tìm thấy danh mục phù hợp.
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Trình độ --}}
                <div class="relative">
                    <select name="level" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-800 dark:bg-slate-950 dark:text-white dark:focus:bg-slate-900">
                        <option value="">Tất cả trình độ</option>
                        @foreach($levelOptions as $value => $label)
                            <option value="{{ $value }}" @selected($level === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Khoảng giá (Dropdown preset) --}}
                <div class="relative">
                    <select name="pricing" id="pricing-select" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-800 dark:bg-slate-950 dark:text-white dark:focus:bg-slate-900">
                        @foreach($pricingOptions as $value => $label)
                            <option value="{{ $value }}" @selected($pricing === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Đánh giá --}}
                <div class="relative">
                    <select name="rating" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-800 dark:bg-slate-950 dark:text-white dark:focus:bg-slate-900">
                        @foreach($ratingOptions as $value => $label)
                            <option value="{{ $value }}" @selected((string) $rating === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Nút bấm Lọc & Xóa --}}
                <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-1">
                    <button type="submit" class="inline-flex h-11 flex-1 items-center justify-center gap-1.5 rounded-xl bg-indigo-600 px-5 text-sm font-bold text-white shadow-md shadow-indigo-600/25 transition hover:bg-indigo-700 active:scale-[0.98] lg:flex-none">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                        </svg>
                        Lọc
                    </button>
                    @if($hasActiveFilters)
                        <a href="{{ route('courses.index') }}" title="Xóa tất cả bộ lọc" class="inline-flex h-11 items-center justify-center rounded-xl border border-rose-200 bg-rose-50/50 px-3.5 text-sm font-bold text-rose-600 transition hover:bg-rose-100 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-400">
                            Xóa lọc
                        </a>
                    @else
                        <a href="{{ route('courses.index') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3.5 text-sm font-bold text-slate-600 transition hover:bg-slate-100 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                            Xóa
                        </a>
                    @endif
                </div>
            </div>
        </form>

        @if($showCourseOverview)
            <div class="mt-10 space-y-14">
                @foreach($overviewSections as $section)
                    <section data-course-section="{{ $section['key'] }}" aria-labelledby="course-section-{{ $section['key'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <span class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">FEA Learning</span>
                                <h2 id="course-section-{{ $section['key'] }}" class="mt-1 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl dark:text-white">{{ $section['title'] }}</h2>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400">{{ $section['description'] }}</p>
                            </div>
                            <a data-view-more href="{{ $section['moreUrl'] }}" class="inline-flex h-11 shrink-0 items-center justify-center gap-2 self-start rounded-xl border border-indigo-200 bg-white px-4 text-sm font-bold text-indigo-700 transition hover:border-indigo-600 hover:bg-indigo-600 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 sm:self-auto dark:border-indigo-500/40 dark:bg-slate-900 dark:text-indigo-300 dark:hover:bg-indigo-500 dark:hover:text-white">
                                Xem thêm
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                            </a>
                        </div>

                        @if($section['items']->isEmpty())
                            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-[#161615] dark:text-slate-400">
                                Chưa có khóa học phù hợp trong danh sách này.
                            </div>
                        @else
                            @include('courses._catalog-grid', ['courseItems' => $section['items']])
                        @endif
                    </section>
                @endforeach
            </div>
        @else
            <div class="mt-8 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-2xl font-extrabold tracking-tight text-slate-950 dark:text-white">Danh sách khóa học</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Hiển thị {{ $courses->firstItem() ?? 0 }}-{{ $courses->lastItem() ?? 0 }} trong {{ $courses->total() }} khóa học phù hợp.
                    </p>
                </div>
                <a href="{{ route('courses.index') }}" class="text-sm font-bold text-indigo-600 transition hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">← Quay lại các nhóm khóa học</a>
            </div>

            @if($courses->isEmpty())
                <div class="mt-8 rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-800 dark:bg-[#161615]">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">
                        <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5s3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18s-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <h3 class="mt-4 text-lg font-bold text-slate-950 dark:text-white">Chưa tìm thấy khóa học phù hợp</h3>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Thử đổi từ khóa, danh mục hoặc mức giá để xem thêm lựa chọn.</p>
                </div>
            @else
                @include('courses._catalog-grid', ['courseItems' => $courses])
                <div class="mt-8">{{ $courses->links() }}</div>
            @endif
        @endif
    </div>
</section>
@endsection
