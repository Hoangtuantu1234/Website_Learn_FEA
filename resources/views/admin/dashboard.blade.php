<x-admin-layout title="Phân tích hệ thống" page-title="Dashboard phân tích" breadcrumb="Dữ liệu vận hành và hiệu suất của OnlineFEA">

@php
    $statusOptions = collect(\App\Enums\CourseStatus::cases());
    $chartLabels = $monthlyAnalytics->pluck('label');
    $chartRevenue = $monthlyAnalytics->pluck('revenue');
    $chartEnrollments = $monthlyAnalytics->pluck('enrollments');
    $statusLabels = $courseStatuses->pluck('label');
    $statusValues = $courseStatuses->pluck('value');
    $statusColors = $courseStatuses->pluck('color');
    $topCourseLabels = $topCourses->pluck('title')->map(fn ($title) => \Illuminate\Support\Str::limit($title, 22));
    $topCourseValues = $topCourses->pluck('period_enrollments_count');
@endphp

<div class="bi-dashboard space-y-4">
    <form method="GET" action="{{ route('admin.dashboard') }}" class="rounded-2xl border border-blue-100 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col gap-4 2xl:flex-row 2xl:items-end">
            <div class="min-w-[220px] 2xl:mr-auto">
                <p class="text-lg font-black text-slate-950 dark:text-white">Phân tích hoạt động OnlineFEA</p>
                <p class="mt-1 text-xs text-slate-400">Sử dụng bộ lọc để phân tích dữ liệu theo từng nhóm</p>
            </div>
            <div class="grid flex-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Năm</span>
                    <select name="year" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
                        @foreach($availableYears as $y)<option value="{{ $y }}" @selected($filters['year'] === $y)>Năm {{ $y }}</option>@endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Danh mục</span>
                    <select name="category_id" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
                        <option value="">Tất cả danh mục</option>
                        @foreach($categories as $category)<option value="{{ $category->id }}" @selected($filters['categoryId'] === $category->id)>{{ $category->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Giảng viên</span>
                    <select name="instructor_id" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
                        <option value="">Tất cả giảng viên</option>
                        @foreach($instructors as $instructor)<option value="{{ $instructor->id }}" @selected($filters['instructorId'] === $instructor->id)>{{ $instructor->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Trạng thái khóa học</span>
                    <select name="status" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
                        <option value="">Tất cả trạng thái</option>
                        @foreach($statusOptions as $courseStatus)<option value="{{ $courseStatus->value }}" @selected($filters['status'] === $courseStatus->value)>{{ $courseStatus->label() }}</option>@endforeach
                    </select>
                </label>
            </div>
            <div class="flex gap-2">
                <button class="inline-flex h-10 items-center justify-center rounded-xl bg-blue-600 px-4 text-sm font-bold text-white transition hover:bg-blue-700">Áp dụng</button>
                <a href="{{ route('admin.dashboard') }}" class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">Xóa lọc</a>
            </div>
        </div>
    </form>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        @php
            $kpis = [
                ['Doanh thu', number_format($stats['revenue'], 0, ',', '.').'đ', '₫', 'Năm '.$filters['year']],
                ['Đơn hàng', number_format($stats['orders']), '#', 'Đã thanh toán'],
                ['Khóa học', number_format($stats['courses']), '▤', 'Theo bộ lọc'],
                ['Học viên', number_format($stats['students']), '◉', number_format($stats['enrollments']).' lượt ghi danh'],
                ['Tổng đánh giá', number_format($stats['reviews']), '✦', number_format($stats['helpful_reviews']).' lượt hữu ích'],
                ['Điểm trung bình', number_format($stats['average_rating'], 1), '★', 'Trên thang 5 sao'],
            ];
        @endphp
        @foreach($kpis as $index => $kpi)
            <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-[0_4px_12px_rgba(37,99,235,0.07)] dark:border-blue-900/50 dark:bg-slate-900">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $index === 5 ? 'bg-amber-50 text-amber-500 dark:bg-amber-500/10' : 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300' }} text-sm font-black">{{ $kpi[2] }}</span>
                    <div class="min-w-0"><p class="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $kpi[0] }}</p><p class="mt-1 truncate text-xl font-black text-slate-950 dark:text-white">{{ $kpi[1] }}</p><p class="mt-0.5 truncate text-[10px] text-slate-400">{{ $kpi[3] }}</p></div>
                </div>
            </article>
        @endforeach
    </section>

    <!-- Hàng biểu đồ 1: Biểu đồ Doanh thu / Ghi danh theo thời gian -->
    <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3"><h3 class="text-sm font-black text-slate-900 dark:text-white">Doanh thu và ghi danh theo tháng</h3><p class="mt-1 text-xs text-slate-400">Xu hướng doanh thu và ghi danh theo 12 tháng năm {{ $filters['year'] }}</p></div>
        <div class="h-[320px]"><canvas id="adminTrendChart" role="img" aria-label="Biểu đồ doanh thu và ghi danh theo tháng"></canvas></div>
    </article>

    <!-- Hàng biểu đồ 2: Biểu đồ Cơ cấu trạng thái -->
    <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3"><h3 class="text-sm font-black text-slate-900 dark:text-white">Cơ cấu trạng thái</h3><p class="mt-1 text-xs text-slate-400">Phân bổ khóa học theo trạng thái</p></div>
        <div class="h-[300px]"><canvas id="adminStatusChart" role="img" aria-label="Biểu đồ trạng thái khóa học"></canvas></div>
    </article>

    <!-- Hàng biểu đồ 3: Biểu đồ Top khóa học -->
    <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white">Top khóa học nổi bật</h3>
                <p class="mt-1 text-xs text-slate-400">Xếp hạng theo lượt ghi danh trong kỳ</p>
            </div>
            <span class="inline-flex self-start sm:self-auto items-center rounded-md bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">Đơn vị: lượt ghi danh</span>
        </div>
        <div class="h-[320px]"><canvas id="adminTopCoursesChart" role="img" aria-label="Biểu đồ top khóa học"></canvas></div>
    </article>

    <section class="grid gap-4 xl:grid-cols-12">
        <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 xl:col-span-4">
            <div class="mb-3"><h3 class="text-sm font-black text-slate-900 dark:text-white">Phân bố điểm đánh giá</h3><p class="mt-1 text-xs text-slate-400">Số lượng đánh giá từ 1 đến 5 sao</p></div>
            <div class="h-[310px]"><canvas id="adminRatingChart" role="img" aria-label="Biểu đồ phân bố đánh giá"></canvas></div>
        </article>

        <article class="overflow-hidden rounded-2xl border border-blue-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 xl:col-span-8">
            <header class="flex items-center justify-between border-b border-blue-100 px-4 py-3 dark:border-slate-800">
                <div><h3 class="text-sm font-black text-slate-900 dark:text-white">Phân tích chi tiết khóa học</h3><p class="mt-1 text-xs text-slate-400">Dữ liệu xếp hạng và hiệu suất theo bộ lọc</p></div>
                <a href="{{ route('admin.courses.index') }}" class="text-xs font-bold text-blue-600 hover:text-blue-700 dark:text-blue-300">Xem toàn bộ</a>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] text-left text-xs">
                    <thead class="bg-blue-600 text-white"><tr><th class="px-4 py-3 font-bold">Khóa học</th><th class="px-3 py-3 font-bold">Giảng viên</th><th class="px-3 py-3 font-bold">Danh mục</th><th class="px-3 py-3 text-center font-bold">Ghi danh</th><th class="px-3 py-3 text-center font-bold">Đánh giá</th><th class="px-3 py-3 text-center font-bold">Điểm</th><th class="px-4 py-3 font-bold">Trạng thái</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse($topCourses as $course)
                            <tr class="hover:bg-blue-50/50 dark:hover:bg-slate-800/60">
                                <td class="max-w-[230px] px-4 py-3 font-bold text-slate-800 dark:text-slate-100"><span class="block truncate">{{ $course->title }}</span></td>
                                <td class="px-3 py-3 text-slate-500 dark:text-slate-400">{{ $course->instructor?->name ?? '—' }}</td>
                                <td class="px-3 py-3 text-slate-500 dark:text-slate-400">{{ $course->category?->name ?? '—' }}</td>
                                <td class="px-3 py-3 text-center font-bold text-slate-700 dark:text-slate-200">{{ number_format($course->period_enrollments_count) }}</td>
                                <td class="px-3 py-3 text-center text-slate-500">{{ number_format($course->reviews_count) }}</td>
                                <td class="px-3 py-3 text-center"><span class="font-black text-amber-500">★</span> <strong class="text-slate-700 dark:text-slate-200">{{ number_format((float) ($course->reviews_avg_rating ?? 0), 1) }}</strong></td>
                                <td class="px-4 py-3"><span class="rounded-lg bg-blue-50 px-2 py-1 font-bold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">{{ $course->statusEnum()->label() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-12 text-center text-slate-400">Không có dữ liệu phù hợp với bộ lọc.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.Chart) return;
    const dark = document.documentElement.classList.contains('dark');
    const text = dark ? '#cbd5e1' : '#64748b';
    const grid = dark ? 'rgba(148,163,184,.12)' : 'rgba(148,163,184,.18)';
    const common = { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: text, boxWidth: 10, usePointStyle: true, font: { size: 10 } } } } };

    new Chart(document.getElementById('adminTrendChart'), {
        type: 'line',
        data: { labels: @json($chartLabels), datasets: [
            { label: 'Doanh thu', data: @json($chartRevenue), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', fill: true, tension: .35, pointRadius: 3, yAxisID: 'y' },
            { label: 'Ghi danh', data: @json($chartEnrollments), borderColor: '#06b6d4', backgroundColor: '#06b6d4', tension: .35, pointRadius: 3, yAxisID: 'y1' }
        ]},
        options: {
            ...common,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { color: text }, grid: { display: false } },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Doanh thu (VNĐ)', color: text, font: { size: 10, weight: 'bold' } },
                    ticks: { color: text, callback: value => new Intl.NumberFormat('vi-VN', { notation: 'compact' }).format(value) + 'đ' },
                    grid: { color: grid }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    title: { display: true, text: 'Lượt ghi danh', color: text, font: { size: 10, weight: 'bold' } },
                    ticks: { color: text, precision: 0, callback: value => new Intl.NumberFormat('vi-VN').format(value) + ' lượt' },
                    grid: { display: false }
                }
            }
        }
    });
    new Chart(document.getElementById('adminStatusChart'), { type: 'doughnut', data: { labels: @json($statusLabels), datasets: [{ data: @json($statusValues), backgroundColor: @json($statusColors), borderWidth: 3, borderColor: dark ? '#0f172a' : '#fff' }] }, options: { ...common, cutout: '62%', plugins: { ...common.plugins, legend: { position: 'bottom', labels: { color: text, boxWidth: 9, usePointStyle: true, font: { size: 9 } } } } } });
    new Chart(document.getElementById('adminTopCoursesChart'), { type: 'bar', data: { labels: @json($topCourseLabels->take(5)), datasets: [{ label: 'Ghi danh', data: @json($topCourseValues->take(5)), backgroundColor: '#3b82f6', borderRadius: 5 }] }, options: { ...common, indexAxis: 'y', plugins: { ...common.plugins, legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { color: text, precision: 0 }, grid: { color: grid } }, y: { ticks: { color: text, font: { size: 10 } }, grid: { display: false } } } } });
    new Chart(document.getElementById('adminRatingChart'), { type: 'bar', data: { labels: @json($ratingDistribution->pluck('label')), datasets: [{ label: 'Đánh giá', data: @json($ratingDistribution->pluck('value')), backgroundColor: ['#bfdbfe','#93c5fd','#60a5fa','#3b82f6','#1d4ed8'], borderRadius: 6 }] }, options: { ...common, plugins: { ...common.plugins, legend: { display: false } }, scales: { x: { ticks: { color: text }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: text, precision: 0 }, grid: { color: grid } } } } });
});
</script>

</x-admin-layout>
