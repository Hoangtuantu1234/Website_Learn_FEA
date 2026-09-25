<x-instructor-layout :title="'Chi tiết khóa học - '.$course->title" page-title="Chi tiết khóa học" :breadcrumb="$course->title">

@php
    $formatPrice = fn ($value) => (float) $value <= 0 ? 'Miễn phí' : number_format((float) $value, 0, ',', '.').'đ';
    $discountPrice = $course->discount_price ?? $course->sale_price;
    $effectivePrice = $discountPrice ?? $course->price;
    $levelLabels = ['beginner' => 'Cơ bản', 'intermediate' => 'Trung cấp', 'advanced' => 'Nâng cao'];
    $typeLabels = ['video' => 'Video', 'text' => 'Bài đọc', 'document' => 'Tài liệu', 'quiz' => 'Quiz', 'assignment' => 'Bài tập'];
    $statusStyles = [
        'draft' => 'bg-slate-100 text-slate-700 border-slate-200',
        'submitted' => 'bg-amber-50 text-amber-700 border-amber-200',
        'pending_review' => 'bg-amber-50 text-amber-700 border-amber-200',
        'need_revision' => 'bg-orange-50 text-orange-700 border-orange-200',
        'approved' => 'bg-sky-50 text-sky-700 border-sky-200',
        'published' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'pending_update' => 'bg-amber-50 text-amber-800 border-amber-300 font-bold',
        'rejected_update' => 'bg-rose-50 text-rose-800 border-rose-300 font-bold',
        'rejected' => 'bg-rose-50 text-rose-700 border-rose-200',
        'archived' => 'bg-zinc-100 text-zinc-700 border-zinc-200',
    ];
    $statusLabels = [
        'draft' => 'Bản nháp',
        'submitted' => 'Chờ duyệt',
        'pending_review' => 'Chờ duyệt',
        'need_revision' => 'Cần sửa đổi',
        'approved' => 'Đã duyệt',
        'published' => 'Đã xuất bản',
        'pending_update' => 'Chờ duyệt cập nhật',
        'rejected_update' => 'Từ chối cập nhật',
        'rejected' => 'Bị từ chối',
        'archived' => 'Lưu trữ',
    ];
    $statusClass = $statusStyles[$course->status] ?? 'bg-slate-100 text-slate-700 border-slate-200';
    $formatDuration = function ($seconds) {
        if (! $seconds) return null;
        $seconds = (int) $seconds;
        return $seconds >= 3600 ? gmdate('H:i:s', $seconds) : gmdate('i:s', $seconds);
    };
@endphp

<div class="space-y-6">
    {{-- Header Section --}}
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="grid gap-6 p-6 lg:grid-cols-[300px_minmax(0,1fr)_220px]">
            {{-- Thumbnail --}}
            <div class="aspect-video overflow-hidden rounded-lg border border-slate-200 bg-slate-100">
                @if($course->thumbnail)
                    <img src="{{ asset('storage/'.$course->thumbnail) }}" alt="{{ $course->title }}" class="h-full w-full object-cover">
                @else
                    <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-slate-900 to-emerald-700 text-base font-bold text-white">OnlineFEA</div>
                @endif
            </div>

            {{-- Course Overview --}}
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-3 py-0.5 text-xs font-bold {{ $statusClass }}">
                        {{ $statusLabels[$course->status] ?? $course->status }}
                    </span>
                    @if($course->category)
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-0.5 text-xs font-semibold text-slate-700">
                            {{ $course->category->name }}
                        </span>
                    @endif
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-0.5 text-xs font-semibold text-slate-600">
                        {{ $levelLabels[$course->level] ?? 'Mọi trình độ' }}
                    </span>
                </div>

                <h1 class="mt-3 text-2xl font-extrabold text-slate-950">{{ $course->title }}</h1>
                <p class="mt-1 text-xs text-slate-400">Slug: <code class="text-slate-600">{{ $course->slug }}</code></p>
                <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $course->short_description ?: 'Chưa có mô tả ngắn.' }}</p>

                @if($course->rejectionReasonText())
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                        <strong class="flex items-center gap-1.5 font-bold text-rose-900">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            Lý do từ chối:
                        </strong>
                        <p class="mt-1 leading-relaxed">{{ $course->rejectionReasonText() }}</p>
                    </div>
                @endif
            </div>

            {{-- Quick Actions --}}
            <div class="flex flex-col gap-2.5">
                <a href="{{ route('instructor.courses.index') }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    ← Danh sách khóa học
                </a>
                <a href="{{ route('instructor.courses.curriculum', $course) }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-indigo-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    Quản lý giáo trình
                </a>
                <a href="{{ route('instructor.courses.edit', $course) }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Chỉnh sửa khóa học
                </a>
                <a href="{{ route('instructor.courses.students', $course) }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-xs font-bold text-blue-700 transition hover:bg-blue-100">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    Danh sách học viên ({{ number_format($studentCount) }})
                </a>
                @if($course->isPublished())
                    <a href="{{ route('courses.show', $course->slug) }}" target="_blank" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        Xem trang công khai
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- Stats Cards --}}
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Học viên</span>
            <strong class="mt-2 block text-2xl font-extrabold text-slate-950">{{ number_format($studentCount) }}</strong>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Thu nhập</span>
            <strong class="mt-2 block text-2xl font-extrabold text-emerald-600">{{ number_format($courseIncome, 0, ',', '.') }}đ</strong>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Số chương</span>
            <strong class="mt-2 block text-2xl font-extrabold text-slate-950">{{ $curriculumSections->count() }}</strong>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Tổng bài học</span>
            <strong class="mt-2 block text-2xl font-extrabold text-slate-950">{{ $totalLessons }}</strong>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Giá gốc</span>
            <strong class="mt-2 block text-2xl font-extrabold text-slate-950">{{ $formatPrice($course->price) }}</strong>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Giá bán hiệu lực</span>
            <strong class="mt-2 block text-2xl font-extrabold text-slate-950">{{ $formatPrice($effectivePrice) }}</strong>
        </div>
    </section>

    {{-- Course Details Grid --}}
    <section class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(300px,0.6fr)]">
        {{-- Left: Details & Description --}}
        <div class="space-y-6">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-bold text-slate-950">Thông tin chi tiết</h3>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Trình độ</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $levelLabels[$course->level] ?? 'Chưa chọn' }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ngôn ngữ</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $course->language === 'vi' ? 'Tiếng Việt' : ($course->language ?: 'Chưa khai báo') }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ngày tạo</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $course->created_at?->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ngày cập nhật</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $course->updated_at?->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ngày xuất bản</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $course->published_at?->format('d/m/Y H:i') ?? 'Chưa xuất bản' }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Cấp chứng chỉ</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $course->certificate_enabled ? 'Có kích hoạt' : 'Không kích hoạt' }}</dd>
                    </div>
                </dl>

                <div class="mt-6">
                    <h4 class="text-sm font-bold text-slate-950">Mô tả đầy đủ</h4>
                    <div class="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-600 bg-slate-50 p-4 rounded-lg border border-slate-100">
                        {{ $course->description ?: 'Chưa có mô tả chi tiết.' }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Right: Ownership & Status Info --}}
        <div class="space-y-6">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-bold text-slate-950">Giảng viên sở hữu</h3>
                <div class="mt-4 flex items-center gap-3">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-indigo-100 text-sm font-bold text-indigo-700">
                        @if($course->instructor && method_exists($course->instructor, 'avatarUrl'))
                            <img src="{{ $course->instructor->avatarUrl() }}" alt="{{ $course->instructor->name }}" class="h-full w-full object-cover">
                        @else
                            {{ strtoupper(substr($course->instructor?->name ?? 'G', 0, 1)) }}
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="font-bold text-slate-950">{{ $course->instructor?->name ?? 'Bạn' }}</div>
                        <div class="truncate text-xs text-slate-500">{{ $course->instructor?->email }}</div>
                    </div>
                </div>
                <div class="mt-4 rounded-lg bg-slate-50 p-3 border border-slate-100">
                    <div class="text-xs text-slate-500">Trạng thái tài khoản:</div>
                    <span class="mt-1 inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        Đã xác minh giảng viên
                    </span>
                </div>
            </div>

            @if($course->courseReviews && $course->courseReviews->isNotEmpty())
                @php
                    $reviewBadges = [
                        \App\Enums\CourseReviewStatus::Approved->value => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        \App\Enums\CourseReviewStatus::Rejected->value => 'bg-rose-50 text-rose-700 border-rose-200',
                        \App\Enums\CourseReviewStatus::Pending->value => 'bg-amber-50 text-amber-700 border-amber-200',
                    ];
                @endphp
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-base font-bold text-slate-950">Lịch sử kiểm duyệt</h3>
                    <div class="mt-3 divide-y divide-slate-100">
                        @foreach($course->courseReviews->take(3) as $review)
                            @php
                                $statusVal = $review->status instanceof \App\Enums\CourseReviewStatus ? $review->status->value : (string) $review->status;
                                $badgeClass = $reviewBadges[$statusVal] ?? 'bg-slate-50 text-slate-700 border-slate-200';
                            @endphp
                            <div class="py-3 text-xs">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-slate-800">Lần {{ $review->submission_number ?? 1 }}:</span>
                                        <span class="rounded-full border px-2 py-0.5 text-[11px] font-bold {{ $badgeClass }}">{{ $review->statusLabel() }}</span>
                                    </div>
                                    <span class="text-slate-400">{{ ($review->reviewed_at ?? $review->submitted_at ?? $review->created_at)?->format('d/m/Y') }}</span>
                                </div>
                                @if($review->comment ?? $review->feedback)
                                    <p class="mt-1 text-slate-600 italic">"{{ $review->comment ?? $review->feedback }}"</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- Curriculum Section --}}
    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-slate-950">Giáo trình & Bài học</h3>
                <p class="text-xs text-slate-500">{{ $curriculumSections->count() }} chương • {{ $totalLessons }} bài học</p>
            </div>
            <a href="{{ route('instructor.courses.curriculum', $course) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                Chỉnh sửa bài học
            </a>
        </div>

        <div class="space-y-4">
            @forelse($curriculumSections as $sectionIndex => $section)
                <div class="overflow-hidden rounded-lg border border-slate-200">
                    <div class="flex items-center justify-between bg-slate-50 px-4 py-3">
                        <h4 class="text-sm font-bold text-slate-950">
                            Chương {{ $sectionIndex + 1 }}: {{ $section->title }}
                        </h4>
                        <span class="text-xs text-slate-500 font-semibold">{{ $section->lessons->count() }} bài học</span>
                    </div>

                    <div class="divide-y divide-slate-100">
                        @forelse($section->lessons as $lessonIndex => $lesson)
                            <div class="flex items-center justify-between px-4 py-3 hover:bg-slate-50/50 transition">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="text-xs font-bold text-slate-400 shrink-0">{{ $sectionIndex + 1 }}.{{ $lessonIndex + 1 }}</span>
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold text-slate-900 truncate">{{ $lesson->title }}</div>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-[11px] font-bold text-slate-500 uppercase">{{ $typeLabels[$lesson->type] ?? $lesson->type }}</span>
                                            @if($lesson->is_preview)
                                                <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 border border-emerald-200">Xem thử</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="shrink-0 text-xs text-slate-500">
                                    @if($lesson->duration_seconds || $lesson->duration)
                                        <span>{{ $formatDuration($lesson->duration_seconds ?: $lesson->duration) }}</span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="p-4 text-center text-xs text-slate-400 italic">Chưa có bài học trong chương này</div>
                        @endforelse
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                    Khóa học chưa có chương hoặc bài học nào.
                </div>
            @endforelse
        </div>
    </section>
</div>

</x-instructor-layout>
