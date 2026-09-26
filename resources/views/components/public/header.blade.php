@props(['studentDashboard' => false])

@php
    $user = auth()->user();
    $studentCartCount = $studentCartCount ?? 0;
    $favoriteCourseCount = $favoriteCourseCount ?? 0;
    $primaryNav = [
        ['label' => 'Trang chủ', 'route' => 'home', 'active' => ['home']],
        ['label' => 'Khóa học', 'route' => 'courses.index', 'active' => ['courses.*']],
        ['label' => 'Xếp hạng', 'route' => 'leaderboard', 'active' => ['leaderboard']],
        ['label' => 'Lộ trình', 'route' => 'learning-paths.index', 'active' => ['learning-paths.*']],
        ['label' => 'Giảng viên', 'route' => 'instructors.index', 'active' => ['instructors.*']],
    ];
    $navigationMenus = [
        'explore' => ['label' => 'Khám phá', 'links' => [
            ['Khóa học', route('courses.index')], ['Lộ trình học', route('learning-paths.index')],
            ['Giảng viên', route('instructors.index')], ['Xếp hạng', route('leaderboard')],
        ]],
    ];
    if ($user?->isStudent()) {
        $navigationMenus['learning'] = ['label' => 'Học tập', 'links' => [
            ['Tổng quan', route('student.dashboard')], ['Khóa học của tôi', route('student.courses')],
            ['Nhóm học tập', route('student.study-groups.index')],
        ]];
        $navigationMenus['support'] = ['label' => 'Hỗ trợ', 'links' => [
            ['Trung tâm hỗ trợ', route('support.tickets.index')], ['Hồ sơ cá nhân', route('student.profile')],
            ['Bảo mật tài khoản', route('student.profile.security')],
        ]];
    } elseif ($user) {
        $navigationMenus['management'] = ['label' => 'Quản lý', 'links' => [
            ['Tổng quan', $user->dashboardUrl()],
            ['Hồ sơ', route($user->isInstructor() ? 'instructor.profile' : 'admin.profile')],
            ['Nhóm học tập', route('study-groups.index')],
        ]];
    } else {
        $navigationMenus['account'] = ['label' => 'Tài khoản', 'links' => [
            ['Đăng nhập', route('login')], ['Đăng ký', route('register')],
        ]];
    }
    $menuItemClass = 'flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-blue-50 hover:text-[#0056D2] focus-visible:bg-blue-50 focus-visible:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#0056D2] dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-blue-300';
    $accountActive = $user && (
        ($user->isStudent() && request()->routeIs('student.dashboard', 'student.profile*', 'student.orders*', 'student.wishlist*', 'student.cart*', 'student.checkout.*', 'favorites.*'))
        || ($user->isInstructor() && request()->routeIs('instructor.profile*'))
        || ($user->isAdmin() && request()->routeIs('admin.profile*'))
    );
@endphp

<header data-public-header
        @if($studentDashboard) data-student-header data-student-dashboard-header @endif
        x-data="publicHeader()"
        x-on:keydown.escape.window="closeMenus(); mobileOpen = false"
        class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
    <div class="mx-auto flex h-14 w-full max-w-[1920px] items-center gap-2 px-3 sm:px-5 lg:gap-3 lg:px-6">
        <div data-header-left class="flex min-w-0 shrink-0 items-center gap-1.5 lg:gap-2.5">
            <button type="button"
                    @if($studentDashboard)
                        x-on:click="$dispatch('toggle-student-sidebar')"
                        :aria-controls="window.matchMedia('(min-width: 1024px)').matches ? 'student-desktop-sidebar' : 'student-mobile-sidebar'"
                        :aria-expanded="(window.matchMedia('(min-width: 1024px)').matches ? studentSidebarDesktopOpen : studentSidebarOpen).toString()"
                    @else
                        x-on:click="mobileOpen = true"
                    @endif
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-slate-700 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:text-slate-200 dark:hover:bg-slate-800"
                    aria-label="{{ $studentDashboard ? 'Ẩn/hiện menu học viên' : 'Mở menu điều hướng' }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>

            <a href="{{ route('home') }}" class="flex h-10 w-16 shrink-0 items-center justify-center overflow-hidden" aria-label="FEA Learning - Trang chủ">
                <img src="{{ asset('images/fea-logo.png') }}" alt="FEA Learning" class="h-full w-full scale-[1.65] object-contain">
            </a>

                <nav data-primary-navigation class="hidden h-14 items-center gap-0.5 lg:flex" aria-label="Điều hướng chính">
                    <a href="{{ route('home') }}" class="inline-flex h-full items-center px-2.5 text-sm font-medium text-slate-700 transition hover:text-[#0056D2] dark:text-slate-200 dark:hover:text-blue-300">Trang chủ</a>

                    @foreach($navigationMenus as $menuKey => $menu)
                        <div class="relative h-full" x-on:click.outside="if (isOpen('{{ $menuKey }}')) closeMenus()">
                            <button type="button" x-on:click="toggleMenu('{{ $menuKey }}')" class="inline-flex h-full items-center gap-1 px-2.5 text-sm font-medium text-slate-700 transition hover:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#0056D2] dark:text-slate-200 dark:hover:text-blue-300" :aria-expanded="isOpen('{{ $menuKey }}').toString()">
                                {{ $menu['label'] }}
                                <svg class="h-3.5 w-3.5 transition-transform" :class="isOpen('{{ $menuKey }}') ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div x-cloak x-show="isOpen('{{ $menuKey }}')" x-transition class="absolute left-0 top-full z-50 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                @foreach($menu['links'] as [$label, $url])
                                    <a href="{{ $url }}" class="block rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-[#0056D2] dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-blue-300">{{ $label }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>

        </div>

        <div data-header-right class="ml-auto flex min-w-0 flex-1 items-center justify-end gap-0.5 sm:gap-1">
            <form data-student-search method="GET" action="{{ route('courses.index') }}" class="mr-auto hidden w-full max-w-[360px] min-w-48 lg:block xl:max-w-[420px]">
                <x-course-search-suggest :value="request('search')" placeholder="Tìm kiếm khóa học, kỹ năng hoặc giảng viên" />
            </form>

            <button type="button" x-on:click="toggleMenu('search')"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] lg:hidden dark:text-slate-300 dark:hover:bg-slate-800"
                    aria-label="Tìm kiếm khóa học" aria-controls="header-mobile-search" :aria-expanded="isOpen('search').toString()">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z"/></svg>
            </button>

                <button type="button" data-theme-toggle onclick="toggleTheme()"
                        class="hidden h-11 w-11 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] sm:inline-flex dark:text-slate-300 dark:hover:bg-slate-800"
                        aria-label="Đổi giao diện" aria-pressed="false">
                    <svg class="hidden h-5 w-5 dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364-.707.707M6.343 17.657l-.707.707m0-12.728.707.707m12.728 12.728-.707-.707M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/></svg>
                    <svg class="block h-5 w-5 dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 0 1 8.646 3.646 9.003 9.003 0 0 0 12 21a9.003 9.003 0 0 0 8.354-5.646Z"/></svg>
                </button>

            @auth
                @if($studentDashboard || $user->isStudent())
                    <a data-student-wishlist data-favorite-count="{{ $favoriteCourseCount }}"
                       x-data="{ count: {{ (int) $favoriteCourseCount }} }"
                       x-on:favorite-updated.window="
                           if (typeof $event.detail.count !== 'undefined') {
                               count = $event.detail.count;
                           } else {
                               count = $event.detail.favorited ? count + 1 : Math.max(0, count - 1);
                           }
                       "
                       :data-favorite-count="count"
                       href="{{ route('student.wishlist') }}"
                       class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 transition hover:bg-blue-50 hover:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-blue-300"
                       aria-label="Khóa học yêu thích">
                        <svg class="h-5 w-5" :fill="count > 0 ? 'currentColor' : 'none'" fill="{{ $favoriteCourseCount > 0 ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.3 6.3a4.5 4.5 0 0 1 6.4 0L12 7.6l1.3-1.3a4.5 4.5 0 1 1 6.4 6.4L12 20.4l-7.7-7.7a4.5 4.5 0 0 1 0-6.4Z"/>
                        </svg>
                        <span x-show="count > 0" data-favorite-badge class="absolute top-1 right-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white shadow-sm" x-text="count > 99 ? '99+' : count">{{ $favoriteCourseCount > 99 ? '99+' : $favoriteCourseCount }}</span>
                    </a>

                    <a data-student-cart data-cart-count="{{ $studentCartCount }}"
                       href="{{ route('cart') }}"
                       class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 transition hover:bg-blue-50 hover:text-[#0056D2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-blue-300"
                       aria-label="Giỏ hàng">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13 5.4 5M7 13l-2 5h13M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 0 0-1-2 1 1 0 0 0 0 2Z"/>
                        </svg>
                        @if($studentCartCount > 0)
                            <span class="absolute top-1 right-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white shadow-sm">{{ $studentCartCount > 99 ? '99+' : $studentCartCount }}</span>
                        @endif
                    </a>
                @endif
                <x-notifications.bell :recent-notifications="$recentNotifications ?? collect()" :unread-count="$unreadNotificationCount ?? 0" />

                <div data-student-account class="relative" x-on:click.outside="if (isOpen('account')) closeMenus()">
                    <button type="button" x-on:click="toggleMenu('account')"
                            class="flex h-11 items-center gap-2.5 rounded-xl px-1 sm:px-2 transition hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:hover:bg-slate-800 {{ $accountActive ? 'bg-blue-50 dark:bg-slate-800' : '' }}"
                            aria-haspopup="true" :aria-expanded="isOpen('account').toString()" aria-controls="public-nav-account" aria-label="Mở menu tài khoản">
                        <span class="hidden max-w-32 truncate text-sm font-semibold text-[#0056D2] xl:block dark:text-blue-300">{{ $user->name }}</span>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-[#0878B8] text-xs font-bold text-white dark:border-slate-700">
                            @if($user->avatar)
                                <img src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}" class="h-full w-full object-cover">
                            @else
                                {{ collect(explode(' ', trim($user->name)))->filter()->take(-2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}
                            @endif
                        </span>
                        <svg class="hidden h-4 w-4 text-slate-400 transition-transform xl:block" :class="isOpen('account') ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div id="public-nav-account" x-cloak x-show="isOpen('account')"
                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                         class="absolute right-0 mt-2 w-72 max-w-[calc(100vw-1rem)] overflow-hidden rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900"
                         role="menu">
                        <div class="mb-1 flex items-center gap-3 border-b border-slate-100 px-2 py-2.5 dark:border-slate-800">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[#0878B8] text-xs font-bold text-white">
                                @if($user->avatar)<img src="{{ $user->avatarUrl() }}" alt="" class="h-full w-full object-cover">@else{{ strtoupper(substr($user->name, 0, 1)) }}@endif
                            </span>
                            <span class="min-w-0">
                                <strong class="block truncate text-sm text-slate-900 dark:text-white">{{ $user->name }}</strong>
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</span>
                            </span>
                        </div>

                        @if($user->isStudent())
                            <a href="{{ route('student.dashboard') }}" x-on:click="closeMenus()" class="{{ $menuItemClass }} {{ request()->routeIs('student.dashboard') ? 'bg-blue-50 text-[#0056D2] dark:bg-slate-800 dark:text-blue-300' : '' }}" role="menuitem">Tổng quan</a>
                            <a href="{{ route('student.profile') }}" x-on:click="closeMenus()" class="{{ $menuItemClass }} {{ request()->routeIs('student.profile') ? 'bg-blue-50 text-[#0056D2] dark:bg-slate-800 dark:text-blue-300' : '' }}" role="menuitem">Hồ sơ cá nhân</a>
                            <a href="{{ route('student.profile.security') }}" x-on:click="closeMenus()" class="{{ $menuItemClass }} {{ request()->routeIs('student.profile.security') ? 'bg-blue-50 text-[#0056D2] dark:bg-slate-800 dark:text-blue-300' : '' }}" role="menuitem">Bảo mật tài khoản</a>
                            <a href="{{ route('student.orders') }}" x-on:click="closeMenus()" class="{{ $menuItemClass }} {{ request()->routeIs('student.orders*') ? 'bg-blue-50 text-[#0056D2] dark:bg-slate-800 dark:text-blue-300' : '' }}" role="menuitem">Đơn hàng</a>
                            <a href="{{ route('student.wishlist') }}" x-on:click="closeMenus()" class="{{ $menuItemClass }} {{ request()->routeIs('student.wishlist*') ? 'bg-blue-50 text-[#0056D2] dark:bg-slate-800 dark:text-blue-300' : '' }}" role="menuitem">Yêu thích</a>
                            <a href="{{ route('cart') }}" x-on:click="closeMenus()" class="{{ $menuItemClass }} {{ request()->routeIs('cart*') ? 'bg-blue-50 text-[#0056D2] dark:bg-slate-800 dark:text-blue-300' : '' }}" role="menuitem">Giỏ hàng</a>
                        @elseif($user->isInstructor())
                            <a href="{{ $user->dashboardUrl() }}" x-on:click="closeMenus()" class="{{ $menuItemClass }}" role="menuitem">Dashboard</a>
                            <a href="{{ route('instructor.profile') }}" x-on:click="closeMenus()" class="{{ $menuItemClass }}" role="menuitem">Hồ sơ</a>
                        @else
                            <a href="{{ $user->dashboardUrl() }}" x-on:click="closeMenus()" class="{{ $menuItemClass }}" role="menuitem">Dashboard</a>
                            <a href="{{ route('admin.profile') }}" x-on:click="closeMenus()" class="{{ $menuItemClass }}" role="menuitem">Hồ sơ</a>
                        @endif

                        <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-slate-100 pt-1 dark:border-slate-800">
                            @csrf
                            <button type="submit" class="flex min-h-10 w-full items-center rounded-lg px-3 py-2 text-left text-sm font-semibold text-rose-600 transition hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rose-500 dark:text-rose-400 dark:hover:bg-rose-950/30">Đăng xuất</button>
                        </form>
                    </div>
                </div>
            @else
                <a href="{{ route('login') }}" class="hidden rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] sm:inline-flex dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Đăng nhập</a>
                <a href="{{ route('register') }}" class="inline-flex rounded-lg bg-[#0056D2] px-3 py-2 text-sm font-medium text-white transition hover:bg-[#0046B8] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] focus-visible:ring-offset-2">Đăng ký</a>
            @endauth
        </div>
    </div>

    <div id="header-mobile-search" x-cloak x-show="isOpen('search')" x-transition.opacity x-on:click.outside="if (isOpen('search')) closeMenus()"
         class="absolute inset-x-0 top-full border-b border-slate-200 bg-white p-3 shadow-lg lg:hidden dark:border-slate-800 dark:bg-slate-900">
        <form method="GET" action="{{ route('courses.index') }}" class="mx-auto max-w-2xl">
            <x-course-search-suggest :value="request('search')" placeholder="Tìm kiếm khóa học…" input-class="h-11 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-sm outline-none focus:border-[#0056D2] focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:focus:ring-blue-950" />
        </form>
    </div>

    <div x-show="mobileOpen" x-cloak class="fixed inset-0 z-[60]" role="dialog" aria-modal="true" aria-label="Menu chính">
        <div class="absolute inset-0 bg-slate-950/45" x-on:click="mobileOpen = false"></div>
        <aside x-show="mobileOpen"
               x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
               class="absolute inset-y-0 left-0 flex w-80 max-w-[88vw] flex-col overflow-y-auto bg-white shadow-2xl dark:bg-slate-900">
            <div class="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 px-5 dark:border-slate-800">
                <a href="{{ route('home') }}" x-on:click="mobileOpen = false" aria-label="FEA Learning - Trang chủ"><img src="{{ asset('images/fea-logo.png') }}" alt="FEA Learning" class="h-10 w-auto"></a>
                <button type="button" x-on:click="mobileOpen = false" class="rounded-lg p-2 text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:text-slate-200 dark:hover:bg-slate-800" aria-label="Đóng menu">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <nav class="flex flex-col gap-1 p-5 text-sm font-semibold text-slate-700 dark:text-slate-200" aria-label="Menu di động">
                @foreach($primaryNav as $item)
                    @php $active = request()->routeIs(...$item['active']); @endphp
                    <a href="{{ route($item['route']) }}" x-on:click="closeMobile()" @if($active) aria-current="page" @endif
                       class="rounded-lg px-3 py-3 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] dark:hover:bg-slate-800 {{ $active ? 'bg-blue-50 text-[#0056D2] dark:bg-slate-800 dark:text-blue-300' : '' }}">{{ $item['label'] }}</a>
                @endforeach
            </nav>
            @foreach($navigationMenus as $menuKey => $menu)
                @continue($menuKey === 'explore')
                <div class="mx-5 border-t border-slate-200 py-4 dark:border-slate-800">
                    <p class="mb-2 px-3 text-xs font-bold uppercase tracking-wider text-slate-400">{{ $menu['label'] }}</p>
                    @foreach($menu['links'] as [$label, $url])
                        <a href="{{ $url }}" x-on:click="closeMobile()" class="{{ $menuItemClass }}">{{ $label }}</a>
                    @endforeach
                </div>
            @endforeach
        </aside>
    </div>
</header>
