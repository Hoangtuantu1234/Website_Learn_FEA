@props([
    'value' => '',
    'placeholder' => 'Tìm kiếm khóa học, kỹ năng hoặc giảng viên',
    'inputClass' => 'h-10 w-full rounded-full border border-slate-300 bg-white pl-11 pr-4 text-sm text-slate-900 outline-none transition focus:border-[#0056D2] focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-blue-950',
])

<div class="relative" x-data="{
    query: @js((string) $value),
    open: false,
    loading: false,
    suggestions: [],
    courses: [],
    categories: [],
    instructors: [],
    timer: null,
    url: '{{ route('courses.suggestions', [], false) }}',
    hasResults() {
        return this.courses.length > 0 || this.categories.length > 0 || this.instructors.length > 0;
    },
    fetchSuggestions() {
        const q = this.query.trim();
        if (q.length < 2) {
            this.suggestions = [];
            this.courses = [];
            this.categories = [];
            this.instructors = [];
            this.open = false;
            this.loading = false;
            return;
        }
        this.loading = true;
        fetch(this.url + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then((response) => response.ok ? response.json() : { suggestions: [], courses: [], categories: [], instructors: [] })
          .then((data) => {
              this.suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
              this.courses = Array.isArray(data.courses) ? data.courses : [];
              this.categories = Array.isArray(data.categories) ? data.categories : [];
              this.instructors = Array.isArray(data.instructors) ? data.instructors : [];
              this.open = true;
          })
          .catch(() => {
              this.suggestions = [];
              this.courses = [];
              this.categories = [];
              this.instructors = [];
              this.open = true;
          })
          .finally(() => { this.loading = false; });
    },
    onInput() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetchSuggestions(), 300);
    }
}" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
    <label class="relative block">
        <span class="sr-only">Tìm kiếm khóa học</span>
        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z"/></svg>
        <input type="search" name="search" x-model="query" x-on:input="onInput()" x-on:focus="if (hasResults() || query.trim().length >= 2) open = true" value="{{ $value }}" placeholder="{{ $placeholder }}" autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="open.toString()" {{ $attributes->merge(['class' => $inputClass]) }}>
    </label>
    <div x-cloak x-show="open" x-transition.opacity class="absolute inset-x-0 top-full z-50 mt-1.5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <template x-if="loading">
            <div class="flex items-center gap-2.5 px-4 py-3 text-xs font-medium text-slate-500">
                <svg class="h-4 w-4 animate-spin text-[#0056D2]" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Đang tìm kiếm gợi ý...</span>
            </div>
        </template>
        <template x-if="!loading && query.trim().length >= 2 && !hasResults()">
            <p class="px-4 py-3.5 text-center text-sm text-slate-500 dark:text-slate-400">Không tìm thấy kết quả phù hợp.</p>
        </template>
        <div x-show="!loading && hasResults()" class="max-h-96 overflow-y-auto py-1 divide-y divide-slate-100 dark:divide-slate-800">
            {{-- Nhóm 1: Khóa học --}}
            <template x-if="courses.length > 0">
                <div class="py-1">
                    <div class="flex items-center gap-1.5 px-3.5 py-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        <span>Gợi ý khóa học</span>
                    </div>
                    <template x-for="course in courses" :key="'course-' + course.id">
                        <a :href="course.url" class="group flex items-center gap-3 px-3.5 py-2 text-sm transition hover:bg-blue-50/80 dark:hover:bg-slate-800">
                            <div class="h-10 w-14 shrink-0 overflow-hidden rounded bg-slate-100 dark:bg-slate-800">
                                <template x-if="course.thumbnail">
                                    <img :src="course.thumbnail" :alt="course.label" class="h-full w-full object-cover">
                                </template>
                                <template x-if="!course.thumbnail">
                                    <div class="flex h-full w-full items-center justify-center text-slate-400">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-slate-900 group-hover:text-[#0056D2] dark:text-white dark:group-hover:text-blue-400" x-text="course.label"></span>
                                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                    <span class="truncate" x-text="course.meta"></span>
                                    <span class="text-slate-300 dark:text-slate-700">•</span>
                                    <span class="shrink-0 font-medium text-emerald-600 dark:text-emerald-400" x-text="course.price_text"></span>
                                </div>
                            </div>
                            <svg class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-[#0056D2] dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </template>
                </div>
            </template>

            {{-- Nhóm 2: Danh mục --}}
            <template x-if="categories.length > 0">
                <div class="py-1">
                    <div class="flex items-center gap-1.5 px-3.5 py-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                        <span>Danh mục</span>
                    </div>
                    <template x-for="cat in categories" :key="'cat-' + cat.id">
                        <a :href="cat.url" class="group flex items-center gap-3 px-3.5 py-2 text-sm transition hover:bg-blue-50/80 dark:hover:bg-slate-800">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-slate-900 group-hover:text-[#0056D2] dark:text-white dark:group-hover:text-blue-400" x-text="cat.label"></span>
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400" x-text="cat.meta"></span>
                            </div>
                            <svg class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-[#0056D2] dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </template>
                </div>
            </template>

            {{-- Nhóm 3: Giảng viên --}}
            <template x-if="instructors.length > 0">
                <div class="py-1">
                    <div class="flex items-center gap-1.5 px-3.5 py-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <span>Giảng viên</span>
                    </div>
                    <template x-for="inst in instructors" :key="'inst-' + inst.id">
                        <a :href="inst.url" class="group flex items-center gap-3 px-3.5 py-2 text-sm transition hover:bg-blue-50/80 dark:hover:bg-slate-800">
                            <div class="h-8 w-8 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                <template x-if="inst.avatar">
                                    <img :src="inst.avatar" :alt="inst.label" class="h-full w-full object-cover">
                                </template>
                                <template x-if="!inst.avatar">
                                    <div class="flex h-full w-full items-center justify-center font-bold text-slate-500" x-text="inst.label.charAt(0)"></div>
                                </template>
                            </div>
                            <div class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-slate-900 group-hover:text-[#0056D2] dark:text-white dark:group-hover:text-blue-400" x-text="inst.label"></span>
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400" x-text="inst.meta"></span>
                            </div>
                            <svg class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-[#0056D2] dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </template>
                </div>
            </template>
        </div>

        {{-- Footer xem tất cả --}}
        <div x-show="query.trim().length >= 2" class="border-t border-slate-100 bg-slate-50/70 p-2 text-center dark:border-slate-800 dark:bg-slate-800/40">
            <a :href="'{{ route('courses.index') }}?search=' + encodeURIComponent(query.trim())" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#0056D2] transition hover:underline dark:text-blue-400">
                <span>Xem tất cả kết quả cho</span>
                <span class="font-bold truncate max-w-[200px]" x-text="'&quot;' + query.trim() + '&quot;'"></span>
                <span>→</span>
            </a>
        </div>
    </div>
</div>
