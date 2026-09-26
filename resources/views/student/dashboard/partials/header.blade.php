@php
    $user = auth()->user();
    $primaryNav = [
        ['label' => 'Trang chủ', 'route' => 'home', 'active' => ['home']],
        ['label' => 'Khóa học', 'route' => 'courses.index', 'active' => ['courses.*']],
        ['label' => 'Xếp hạng', 'route' => 'leaderboard', 'active' => ['leaderboard']],
        ['label' => 'Lộ trình', 'route' => 'learning-paths.index', 'active' => ['learning-paths.*']],
        ['label' => 'Giảng viên', 'route' => 'instructors.index', 'active' => ['instructors.*']],
    ];
@endphp

<header data-public-header data-student-header data-student-dashboard-header
        x-data="{ accountOpen: false, searchOpen: false }"
        x-on:keydown.escape.window="accountOpen = false; searchOpen = false"
        class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
    <div class="flex h-16 w-full items-center gap-2 px-3 sm:px-4 lg:gap-3 lg:px-6 xl:px-8">
        <div class="flex min-w-0 shrink-0 items-center gap-2 lg:gap-3">
            <button type="button"
                    x-on:click="$dispatch('toggle-student-sidebar')"
                    :aria-controls="window.matchMedia('(min-width: 1024px)').matches ? 'student-desktop-sidebar' : 'student-mobile-sidebar'"
                    :aria-expanded="(window.matchMedia('(min-width: 1024px)').matches ? studentSidebarDesktopOpen : studentSidebarOpen).toString()"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-slate-700 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:text-slate-200 dark:hover:bg-slate-800"
                    aria-label="Ẩn/hiện menu học viên" title="Menu học viên">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>

            <a href="{{ route('home') }}" class="flex shrink-0 items-center" aria-label="OnlineFEA - Trang chủ" title="Trang chủ">
                <img src="{{ asset('images/fea-logo.png') }}" alt="OnlineFEA" class="h-10 w-auto object-contain sm:h-11">
            </a>

            <nav data-student-primary-nav class="hidden h-16 items-center gap-0.5 xl:flex" aria-label="Điều hướng chính">
                @foreach($primaryNav as $item)
                    @php $active = request()->routeIs(...$item['active']); @endphp
                    <a href="{{ route($item['route']) }}" @if($active) aria-current="page" @endif
                       class="inline-flex h-full items-center border-b-2 px-2 text-[13px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#0056D2] {{ $active ? 'border-[#0056D2] text-[#0056D2] dark:border-blue-400 dark:text-blue-300' : 'border-transparent text-slate-600 hover:border-blue-200 hover:text-[#0056D2] dark:text-slate-300 dark:hover:border-slate-600 dark:hover:text-blue-300' }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>

        <div data-student-header-actions class="ml-auto flex min-w-0 shrink-0 items-center gap-1.5 sm:gap-2">
            <form data-student-search method="GET" action="{{ route('courses.index') }}" class="hidden w-[clamp(17.5rem,23vw,22.5rem)] min-w-0 lg:block">
                <x-course-search-suggest :value="request('search')" placeholder="Tìm kiếm khóa học…" />
            </form>

            <button type="button" x-on:click="searchOpen = !searchOpen; accountOpen = false"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-600 transition hover:bg-slate-100 hover:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] lg:hidden dark:text-slate-300 dark:hover:bg-slate-800"
                    aria-label="Tìm kiếm khóa học" title="Tìm kiếm" aria-controls="student-mobile-search" :aria-expanded="searchOpen.toString()">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z"/></svg>
            </button>

            <a data-student-wishlist
               data-favorite-count="{{ $favoriteCourseCount ?? 0 }}"
               x-data="{ count: {{ (int) ($favoriteCourseCount ?? 0) }} }"
               x-on:favorite-updated.window="
                   if (typeof $event.detail.count !== 'undefined') {
                       count = $event.detail.count;
                   } else {
                       count = $event.detail.favorited ? count + 1 : Math.max(0, count - 1);
                   }
               "
               :data-favorite-count="count"
               href="{{ route('student.wishlist') }}"
               class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-600 transition hover:bg-blue-50 hover:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:text-slate-300 dark:hover:bg-slate-800"
               aria-label="Khóa học yêu thích" title="Khóa học yêu thích">
                <svg class="h-5 w-5" :fill="count > 0 ? 'currentColor' : 'none'" fill="{{ ($favoriteCourseCount ?? 0) > 0 ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.3 6.3a4.5 4.5 0 0 1 6.4 0L12 7.6l1.3-1.3a4.5 4.5 0 1 1 6.4 6.4L12 20.4l-7.7-7.7a4.5 4.5 0 0 1 0-6.4Z"/></svg>
                @if(($favoriteCourseCount ?? 0) > 0)
                    <span x-show="count > 0" data-favorite-badge class="absolute right-0 top-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold leading-none text-white" x-text="count > 99 ? '99+' : count">{{ $favoriteCourseCount > 99 ? '99+' : $favoriteCourseCount }}</span>
                @else
                    <template x-if="count > 0">
                        <span data-favorite-badge class="absolute right-0 top-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold leading-none text-white" x-text="count > 99 ? '99+' : count"></span>
                    </template>
                @endif
            </a>

            <a data-student-cart data-cart-count="{{ $studentCartCount ?? 0 }}"
               href="{{ route('cart') }}"
               class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-600 transition hover:bg-blue-50 hover:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:text-slate-300 dark:hover:bg-slate-800"
               aria-label="Giỏ hàng" title="Giỏ hàng">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13 5.4 5M7 13l-2 5h13M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 0 0-1-2 1 1 0 0 0 0 2Z"/></svg>
                @if(($studentCartCount ?? 0) > 0)
                    <span class="absolute right-0 top-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold leading-none text-white">{{ $studentCartCount > 99 ? '99+' : $studentCartCount }}</span>
                @endif
            </a>

            <div class="relative" x-on:click.outside="accountOpen = false">
                <button data-student-account type="button" x-on:click="accountOpen = !accountOpen; searchOpen = false"
                        class="flex h-10 items-center gap-2 rounded-lg pl-1 pr-1 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] sm:pl-2 dark:hover:bg-slate-800"
                        aria-haspopup="true" :aria-expanded="accountOpen.toString()" aria-label="Mở menu tài khoản" title="Tài khoản">
                    <span class="hidden max-w-28 truncate text-sm font-semibold text-slate-700 2xl:block dark:text-slate-200">{{ $user->name }}</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-[#0878B8] text-xs font-bold text-white dark:border-slate-700">
                        @if($user->avatar)
                            <img src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}" class="h-full w-full object-cover">
                        @else
                            {{ collect(explode(' ', trim($user->name)))->filter()->take(-2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}
                        @endif
                    </span>
                </button>

                <div x-cloak x-show="accountOpen"
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     class="absolute right-0 mt-2 w-64 max-w-[calc(100vw-1rem)] overflow-hidden rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900" role="menu">
                    <div class="mb-1 flex items-center gap-3 border-b border-slate-100 px-2 py-2.5 dark:border-slate-800">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[#0878B8] text-xs font-bold text-white">
                            @if($user->avatar)<img src="{{ $user->avatarUrl() }}" alt="" class="h-full w-full object-cover">@else{{ strtoupper(substr($user->name, 0, 1)) }}@endif
                        </span>
                        <span class="min-w-0"><strong class="block truncate text-sm">{{ $user->name }}</strong><span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</span></span>
                    </div>
                    <a href="{{ route('student.profile') }}" class="block rounded-lg px-3 py-2.5 text-sm font-medium hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:hover:bg-slate-800" role="menuitem">Hồ sơ cá nhân</a>
                    <a href="{{ route('student.profile.security') }}" class="block rounded-lg px-3 py-2.5 text-sm font-medium hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:hover:bg-slate-800" role="menuitem">Bảo mật</a>
                    <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-slate-100 pt-1 dark:border-slate-800">
                        @csrf
                        <button type="submit" class="w-full rounded-lg px-3 py-2.5 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 dark:text-rose-400 dark:hover:bg-rose-950/30">Đăng xuất</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="student-mobile-search" x-cloak x-show="searchOpen" x-transition.opacity
         x-on:click.outside="searchOpen = false"
         class="absolute inset-x-0 top-full border-b border-slate-200 bg-white p-3 shadow-lg lg:hidden dark:border-slate-800 dark:bg-slate-900">
        <form method="GET" action="{{ route('courses.index') }}" class="mx-auto max-w-2xl">
            <x-course-search-suggest :value="request('search')" placeholder="Tìm kiếm khóa học…" input-class="h-11 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-sm outline-none focus:border-[#0056D2] focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:focus:ring-blue-950" />
        </form>
    </div>
</header>
