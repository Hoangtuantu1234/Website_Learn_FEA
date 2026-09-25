<x-instructor-layout title="Phân tích giảng dạy" page-title="Dashboard Giảng viên" breadcrumb="Theo dõi hiệu suất khóa học và học viên">

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
    <form method="GET" action="{{ route('instructor.dashboard') }}" class="rounded-2xl border border-blue-100 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end">
            <div class="min-w-[240px] xl:mr-auto">
                <p class="text-lg font-black text-slate-950 dark:text-white">Phân tích hoạt động giảng dạy</p>
                <p class="mt-1 text-xs text-slate-400">Theo dõi dữ liệu thực tế của từng khóa học</p>
            </div>
            <div class="grid flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block"><span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Năm</span><select name="year" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">@foreach($availableYears as $y)<option value="{{ $y }}" @selected($filters['year'] === $y)>Năm {{ $y }}</option>@endforeach</select></label>
                <label class="block"><span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Khoảng thời gian</span><select name="period" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"><option value="6" @selected($filters['period'] === 6)>6 tháng gần nhất</option><option value="12" @selected($filters['period'] === 12)>12 tháng gần nhất</option><option value="all" @selected($filters['period'] === 'all')>Tất cả thời gian</option></select></label>
                <label class="block"><span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Khóa học</span><select name="course_id" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"><option value="">Tất cả khóa học</option>@foreach($courseOptions as $course)<option value="{{ $course->id }}" @selected($filters['courseId'] === $course->id)>{{ $course->title }}</option>@endforeach</select></label>
                <label class="block"><span class="mb-1.5 block text-xs font-bold text-slate-600 dark:text-slate-300">Trạng thái</span><select name="status" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"><option value="">Tất cả trạng thái</option>@foreach($statusOptions as $courseStatus)<option value="{{ $courseStatus->value }}" @selected($filters['status'] === $courseStatus->value)>{{ $courseStatus->label() }}</option>@endforeach</select></label>
            </div>
            <div class="flex gap-2"><button class="inline-flex h-10 items-center justify-center rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700">Áp dụng</button><a href="{{ route('instructor.dashboard') }}" class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">Xóa lọc</a></div>
        </div>
    </form>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        @php
            $kpis = [
                ['Thu nhập', number_format($stats['revenue'], 0, ',', '.').'đ', '₫', 'Năm '.$filters['year']],
                ['Khóa học', number_format($stats['courses']), '▤', 'Theo bộ lọc'],
                ['Đã xuất bản', number_format($stats['published']), '✓', 'Đang hoạt động'],
                ['Học viên', number_format($stats['students']), '◉', number_format($stats['enrollments']).' lượt ghi danh'],
                ['Tổng đánh giá', number_format($stats['reviews']), '✦', number_format($stats['helpful_reviews']).' lượt hữu ích'],
                ['Điểm trung bình', number_format($stats['average_rating'], 1), '★', 'Trên thang 5 sao'],
            ];
        @endphp
        @foreach($kpis as $index => $kpi)
            <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-[0_4px_12px_rgba(37,99,235,0.07)] dark:border-blue-900/50 dark:bg-slate-900">
                <div class="flex items-center gap-3"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $index === 5 ? 'bg-amber-50 text-amber-500 dark:bg-amber-500/10' : 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300' }} text-sm font-black">{{ $kpi[2] }}</span><div class="min-w-0"><p class="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $kpi[0] }}</p><p class="mt-1 truncate text-xl font-black text-slate-950 dark:text-white">{{ $kpi[1] }}</p><p class="mt-0.5 truncate text-[10px] text-slate-400">{{ $kpi[3] }}</p></div></div>
            </article>
        @endforeach
    </section>

    <!-- Hàng biểu đồ 1: Biểu đồ Thu nhập / Ghi danh theo thời gian -->
    <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3">
            <h3 class="text-sm font-black text-slate-900 dark:text-white">Thu nhập và ghi danh theo tháng</h3>
            <p class="mt-1 text-xs text-slate-400">Xu hướng thu nhập và ghi danh theo 12 tháng năm {{ $filters['year'] }}</p>
        </div>
        <div class="h-[320px]"><canvas id="instructorTrendChart" role="img" aria-label="Biểu đồ thu nhập và ghi danh"></canvas></div>
    </article>

    <!-- Hàng biểu đồ 2: Biểu đồ Cơ cấu khóa học -->
    <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3"><h3 class="text-sm font-black text-slate-900 dark:text-white">Cơ cấu khóa học</h3><p class="mt-1 text-xs text-slate-400">Phân bổ theo trạng thái xuất bản</p></div>
        <div class="h-[300px]"><canvas id="instructorStatusChart" role="img" aria-label="Biểu đồ trạng thái khóa học"></canvas></div>
    </article>

    <!-- Hàng biểu đồ 3: Biểu đồ Top khóa học -->
    <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white">Top khóa học</h3>
                <p class="mt-1 text-xs text-slate-400">Xếp hạng theo lượt ghi danh trong kỳ</p>
            </div>
            <span class="inline-flex self-start sm:self-auto items-center rounded-md bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">Đơn vị: lượt ghi danh</span>
        </div>
        <div class="h-[320px]"><canvas id="instructorTopCoursesChart" role="img" aria-label="Biểu đồ top khóa học"></canvas></div>
    </article>

    <!-- Hàng biểu đồ 4: Biểu đồ Phân bố điểm đánh giá -->
    <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3"><h3 class="text-sm font-black text-slate-900 dark:text-white">Phân bố điểm đánh giá</h3><p class="mt-1 text-xs text-slate-400">Phản hồi của học viên từ 1 đến 5 sao</p></div>
        <div class="h-[310px]"><canvas id="instructorRatingChart" role="img" aria-label="Biểu đồ phân bố đánh giá"></canvas></div>
    </article>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.Chart) return;
    const dark = document.documentElement.classList.contains('dark');
    const text = dark ? '#cbd5e1' : '#64748b';
    const grid = dark ? 'rgba(148,163,184,.12)' : 'rgba(148,163,184,.18)';
    const common = { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: text, boxWidth: 10, usePointStyle: true, font: { size: 10 } } } } };

    const revData = @json($chartRevenue);
    const maxRev = Array.isArray(revData) && revData.length ? Math.max(...revData.map(Number)) : 0;

    new Chart(document.getElementById('instructorTrendChart'), {
        type: 'line',
        data: { labels: @json($chartLabels), datasets: [
            { label: 'Thu nhập', data: @json($chartRevenue), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', fill: true, tension: .35, pointRadius: 3, yAxisID: 'y' },
            { label: 'Ghi danh', data: @json($chartEnrollments), borderColor: '#06b6d4', backgroundColor: '#06b6d4', tension: .35, pointRadius: 3, yAxisID: 'y1' }
        ]},
        options: {
            ...common,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                ...common.plugins,
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.dataset.label || '';
                            const val = context.parsed.y !== null ? context.parsed.y : context.raw;
                            if (label === 'Thu nhập') {
                                return `${label}: ${new Intl.NumberFormat('vi-VN').format(val)}đ`;
                            } else if (label === 'Ghi danh') {
                                return `${label}: ${new Intl.NumberFormat('vi-VN').format(val)} lượt`;
                            }
                            return `${label}: ${val}`;
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { color: text }, grid: { display: false } },
                y: {
                    beginAtZero: true,
                    suggestedMax: maxRev > 0 ? undefined : 100000000,
                    title: { display: true, text: 'Thu nhập (VNĐ)', color: text, font: { size: 10, weight: 'bold' } },
                    ticks: {
                        color: text,
                        precision: 0,
                        callback: function(value) {
                            if (value === 0) return '0đ';
                            if (Math.abs(value) >= 1000000) {
                                const formatted = (value / 1000000).toLocaleString('vi-VN', { maximumFractionDigits: 1 });
                                return `${formatted} Trđ`;
                            }
                            return `${value.toLocaleString('vi-VN')}đ`;
                        }
                    },
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
    new Chart(document.getElementById('instructorStatusChart'), { type: 'doughnut', data: { labels: @json($statusLabels), datasets: [{ data: @json($statusValues), backgroundColor: @json($statusColors), borderWidth: 3, borderColor: dark ? '#0f172a' : '#fff' }] }, options: { ...common, cutout: '62%', plugins: { ...common.plugins, legend: { position: 'bottom', labels: { color: text, boxWidth: 9, usePointStyle: true, font: { size: 9 } } } } } });
    new Chart(document.getElementById('instructorTopCoursesChart'), { type: 'bar', data: { labels: @json($topCourseLabels->take(5)), datasets: [{ label: 'Ghi danh', data: @json($topCourseValues->take(5)), backgroundColor: '#3b82f6', borderRadius: 5 }] }, options: { ...common, indexAxis: 'y', plugins: { ...common.plugins, legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { color: text, precision: 0 }, grid: { color: grid } }, y: { ticks: { color: text, font: { size: 10 } }, grid: { display: false } } } } });
    new Chart(document.getElementById('instructorRatingChart'), { type: 'bar', data: { labels: @json($ratingDistribution->pluck('label')), datasets: [{ label: 'Đánh giá', data: @json($ratingDistribution->pluck('value')), backgroundColor: ['#bfdbfe','#93c5fd','#60a5fa','#3b82f6','#1d4ed8'], borderRadius: 6 }] }, options: { ...common, plugins: { ...common.plugins, legend: { display: false } }, scales: { x: { ticks: { color: text }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: text, precision: 0 }, grid: { color: grid } } } } });
});
</script>

</x-instructor-layout>
