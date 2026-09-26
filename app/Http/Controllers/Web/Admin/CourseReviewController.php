<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Course\ApproveCourseRequest;
use App\Http\Requests\Course\RejectCourseRequest;
use App\Models\Course;
use App\Services\CourseReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CourseReviewController extends Controller
{
    public function __construct(
        private readonly CourseReviewService $reviewService,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', 'all_pending');
        $instructorStatus = $request->query('instructor_status', 'all');

        $query = Course::query()
            ->with([
                'instructor:id,name,username,email,avatar,role,instructor_status,account_status,locked_at,locked_reason',
                'instructor.instructorCertificates',
                'category:id,name',
                'courseSections.lessons',
                'chapters.lessons',
            ])
            ->withCount('courseReviews')
            ->when($status === 'all_pending', fn ($q) => $q->whereIn('status', [CourseStatus::PendingReview->value, CourseStatus::PendingUpdate->value]))
            ->when($status === 'approved_waiting_instructor', function ($q) {
                $q->where('status', CourseStatus::Approved->value)
                    ->whereHas('instructor', fn ($iq) => $iq->where('instructor_status', '!=', 'approved')->orWhereIn('account_status', ['locked', 'suspended']));
            })
            ->when($status && ! in_array($status, ['all_pending', 'approved_waiting_instructor', 'all'], true), fn ($q) => $q->where('status', $status));

        // Filter by Instructor status
        if ($instructorStatus === 'approved') {
            $query->whereHas('instructor', fn ($iq) => $iq->where('instructor_status', 'approved'));
        } elseif ($instructorStatus === 'pending') {
            $query->whereHas('instructor', fn ($iq) => $iq->where('instructor_status', 'pending'));
        } elseif ($instructorStatus === 'rejected') {
            $query->whereHas('instructor', fn ($iq) => $iq->where('instructor_status', 'rejected'));
        } elseif ($instructorStatus === 'locked') {
            $query->whereHas('instructor', fn ($iq) => $iq->whereIn('account_status', ['locked', 'suspended']));
        }

        $courses = $query
            ->orderByDesc('submitted_at')
            ->paginate(12)
            ->withQueryString();

        $statusOptions = collect([
            'all_pending' => 'Tất cả đang chờ duyệt (Mới & Cập nhật)',
            'approved_waiting_instructor' => ' Đã duyệt nội dung (Chờ duyệt hồ sơ GV)',
            'all' => 'Tất cả trạng thái',
        ])->merge(collect(CourseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]));

        // Base query for pending courses to calculate filter tab counts
        $basePendingQuery = Course::query()->whereIn('status', [CourseStatus::PendingReview->value, CourseStatus::PendingUpdate->value]);

        $instructorCounts = [
            'all' => (clone $basePendingQuery)->count(),
            'approved' => (clone $basePendingQuery)->whereHas('instructor', fn ($q) => $q->where('instructor_status', 'approved'))->count(),
            'pending' => (clone $basePendingQuery)->whereHas('instructor', fn ($q) => $q->where('instructor_status', 'pending'))->count(),
            'rejected' => (clone $basePendingQuery)->whereHas('instructor', fn ($q) => $q->where('instructor_status', 'rejected'))->count(),
            'locked' => (clone $basePendingQuery)->whereHas('instructor', fn ($q) => $q->whereIn('account_status', ['locked', 'suspended']))->count(),
        ];

        return view('admin.course-reviews.index', [
            'courses' => $courses,
            'status' => $status,
            'statusOptions' => $statusOptions,
            'instructorStatus' => $instructorStatus,
            'instructorCounts' => $instructorCounts,
        ]);
    }

    public function show(Course $course): RedirectResponse
    {
        return redirect()->route('admin.courses.review', $course);
    }

    public function approve(ApproveCourseRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('approve', $course);

        $this->reviewService->approve(
            $course,
            $request->user(),
            $request->validated('checklist', []),
            $request->boolean('publish_immediately', true),
        );

        return redirect()
            ->route('admin.courses.index')
            ->with('success', "Đã duyệt khóa học \"{$course->title}\".");
    }

    public function reject(RejectCourseRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('reject', $course);

        $this->reviewService->reject(
            $course,
            $request->user(),
            $request->validated('comment'),
            $request->validated('checklist', []),
        );

        return back()->with('success', 'Đã từ chối khóa học.');
    }
}
