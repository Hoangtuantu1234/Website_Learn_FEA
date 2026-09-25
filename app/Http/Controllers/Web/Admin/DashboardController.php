<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $currentYear = (int) now()->year;

        $categoryId = $request->integer('category_id') ?: null;
        $instructorId = $request->integer('instructor_id') ?: null;
        $status = in_array($request->string('status')->toString(), CourseStatus::values(), true)
            ? $request->string('status')->toString()
            : null;

        $applyCourseFilters = static function (Builder $query) use ($categoryId, $instructorId, $status): void {
            $query
                ->when($categoryId, fn (Builder $q) => $q->where('category_id', $categoryId))
                ->when($instructorId, fn (Builder $q) => $q->where('instructor_id', $instructorId))
                ->when($status, fn (Builder $q) => $q->where('status', $status));
        };

        $earliestItemYear = OrderItem::query()
            ->whereHas('order', fn (Builder $q) => $q->where('status', 'paid'))
            ->whereHas('course', $applyCourseFilters)
            ->min('order_items.created_at');

        $minYear = $earliestItemYear ? (int) Carbon::parse($earliestItemYear)->year : $currentYear;
        if ($minYear > $currentYear) {
            $minYear = $currentYear;
        }

        $availableYears = range($currentYear, $minYear);

        $rawYear = $request->input('year');
        if ($request->has('year') && is_numeric($rawYear)) {
            $year = (int) $rawYear;
            if ($year < 1970 || $year > $currentYear + 1) {
                $year = $currentYear;
            }
        } else {
            $year = $currentYear;
        }

        $courseQuery = Course::query();
        $applyCourseFilters($courseQuery);

        $enrollmentQuery = Enrollment::query()
            ->whereYear('enrollments.created_at', $year)
            ->whereHas('course', $applyCourseFilters);
        $reviewQuery = Review::query()
            ->whereNull('parent_id')
            ->whereHas('course', $applyCourseFilters);
        $paidItemQuery = OrderItem::query()
            ->whereYear('order_items.created_at', $year)
            ->whereHas('order', fn (Builder $q) => $q->where('status', 'paid'))
            ->whereHas('course', $applyCourseFilters);

        $stats = [
            'revenue' => (float) (clone $paidItemQuery)->sum('order_items.price'),
            'orders' => (clone $paidItemQuery)->distinct()->count('order_items.order_id'),
            'courses' => (clone $courseQuery)->count(),
            'enrollments' => (clone $enrollmentQuery)->count(),
            'reviews' => (clone $reviewQuery)->count(),
            'helpful_reviews' => (int) (clone $reviewQuery)->sum('helpful_count'),
            'average_rating' => round((float) ((clone $reviewQuery)->avg('rating') ?? 0), 1),
            'students' => (clone $enrollmentQuery)->distinct()->count('user_id'),
        ];

        $yearlyRevenueByMonth = OrderItem::query()
            ->selectRaw('MONTH(order_items.created_at) as month, SUM(order_items.price) as total_revenue')
            ->whereYear('order_items.created_at', $year)
            ->whereHas('order', fn (Builder $q) => $q->where('status', 'paid'))
            ->whereHas('course', $applyCourseFilters)
            ->groupByRaw('MONTH(order_items.created_at)')
            ->pluck('total_revenue', 'month');

        $yearlyEnrollmentsByMonth = Enrollment::query()
            ->selectRaw('MONTH(enrollments.created_at) as month, COUNT(*) as total_enrollments')
            ->whereYear('enrollments.created_at', $year)
            ->whereHas('course', $applyCourseFilters)
            ->groupByRaw('MONTH(enrollments.created_at)')
            ->pluck('total_enrollments', 'month');

        $monthlyAnalytics = collect(range(1, 12))->map(function (int $m) use ($year, $yearlyRevenueByMonth, $yearlyEnrollmentsByMonth): array {
            return [
                'label' => 'T'.$m,
                'full_label' => sprintf('Tháng %d/%d', $m, $year),
                'month' => $m,
                'revenue' => (float) ($yearlyRevenueByMonth->get($m) ?? 0),
                'enrollments' => (int) ($yearlyEnrollmentsByMonth->get($m) ?? 0),
            ];
        })->values();

        $courseStatuses = collect(CourseStatus::cases())->map(function (CourseStatus $courseStatus) use ($courseQuery): array {
            return [
                'label' => $courseStatus->label(),
                'value' => (clone $courseQuery)->where('status', $courseStatus->value)->count(),
                'color' => $courseStatus->chartColor(),
            ];
        })->filter(fn (array $item) => $item['value'] > 0)->values();

        $ratingDistribution = collect(range(1, 5))->map(fn (int $rating): array => [
            'label' => $rating.' sao',
            'value' => (clone $reviewQuery)->where('rating', $rating)->count(),
        ]);

        $topCourses = (clone $courseQuery)
            ->with(['category:id,name', 'instructor:id,name'])
            ->withCount([
                'enrollments as period_enrollments_count' => fn (Builder $q) => $q->whereYear('enrollments.created_at', $year),
                'reviews',
            ])
            ->withAvg('reviews', 'rating')
            ->orderByDesc('period_enrollments_count')
            ->limit(8)
            ->get();

        return view('admin.dashboard', [
            'stats' => $stats,
            'monthlyAnalytics' => $monthlyAnalytics,
            'courseStatuses' => $courseStatuses,
            'ratingDistribution' => $ratingDistribution,
            'topCourses' => $topCourses,
            'categories' => Category::query()->where('status', true)->orderBy('name')->get(['id', 'name']),
            'instructors' => User::query()->where('role', 'instructor')->orderBy('name')->get(['id', 'name']),
            'availableYears' => $availableYears,
            'filters' => compact('year', 'categoryId', 'instructorId', 'status'),
        ]);
    }
}
