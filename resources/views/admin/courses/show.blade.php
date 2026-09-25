<x-admin-layout :title="'Khóa học - '.$course->title" page-title="Chi tiết khóa học" :breadcrumb="$course->title">

@php
    $formatPrice = fn ($value) => (float) $value <= 0 ? 'Miễn phí' : number_format((float) $value, 0, ',', '.').'đ';
    $price = $course->discount_price ?? $course->sale_price ?? $course->price;
    $levelLabels = ['beginner' => 'Cơ bản', 'intermediate' => 'Trung cấp', 'advanced' => 'Nâng cao'];
    $typeLabels = ['video' => 'Video', 'text' => 'Bài đọc', 'document' => 'Tài liệu', 'quiz' => 'Quiz', 'assignment' => 'Bài tập'];
    $statusClass = $statusBadgeClasses[$course->status] ?? 'bg-slate-50 text-slate-700 ring-1 ring-slate-200';
    $previewIsYoutube = str_contains((string) $course->preview_video, 'youtube.com') || str_contains((string) $course->preview_video, 'youtu.be');
@endphp

<div class="space-y-6">
    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="grid gap-6 p-5 lg:grid-cols-[320px_minmax(0,1fr)_240px]">
            <div class="aspect-video overflow-hidden rounded-lg border border-slate-200 bg-slate-100">
                @if($course->thumbnail)
                    <img src="{{ asset('storage/'.$course->thumbnail) }}" alt="{{ $course->title }}" class="h-full w-full object-cover">
                @else
                    <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-slate-900 to-rose-700 text-sm font-bold text-white">EduPlatform</div>
                @endif
            </div>

            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold {{ $statusClass }}">{{ $statusLabels[$course->status] ?? $course->status }}</span>
                    <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-600">{{ $course->category?->name ?? 'Chưa chọn danh mục' }}</span>
                </div>
                <h2 class="mt-3 text-2xl font-bold text-slate-950">{{ $course->title }}</h2>
                <p class="mt-2 text-sm text-slate-500">Slug: {{ $course->slug }}</p>
                <p class="mt-4 text-sm leading-6 text-slate-600">{{ $course->short_description ?: 'Chưa có mô tả ngắn.' }}</p>

                @if($course->rejectionReasonText())
                    <div class="mt-4 rounded-lg border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <strong class="block text-rose-900">Lý do từ chối</strong>
                        <span class="mt-1 block">{{ $course->rejectionReasonText() }}</span>
                    </div>
                @endif
            </div>

            <div class="flex flex-col gap-2">
                <a href="{{ route('admin.courses.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition-colors duration-200 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 cursor-pointer">Quay lại danh sách</a>
                <a href="{{ route('admin.courses.students', $course) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-2 text-sm font-bold text-indigo-700 transition-colors duration-200 hover:bg-indigo-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-200 cursor-pointer">Xem học viên</a>
                <a href="{{ route('admin.courses.versions.index', $course) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition-colors duration-200 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 cursor-pointer">Lịch sử phiên bản</a>

                @if($course->status === \App\Models\Course::STATUS_PENDING)
                    <form method="POST" action="{{ route('admin.courses.approve', $course) }}" onsubmit="return confirm('Duyệt khóa học này?')">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition-colors duration-200 hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300 cursor-pointer">Duyệt khóa học</button>
                    </form>
                    <details>
                        <summary class="inline-flex min-h-10 w-full list-none items-center justify-center rounded-lg bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 transition-colors duration-200 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-200 cursor-pointer">Từ chối</summary>
                        <form method="POST" action="{{ route('admin.courses.reject', $course) }}" class="mt-2 space-y-2 rounded-lg border border-rose-100 bg-rose-50 p-3" onsubmit="return confirm('Từ chối khóa học này?')">
                            @csrf
                            <label for="reject-reason" class="block text-xs font-bold text-rose-900">Lý do từ chối</label>
                            <textarea id="reject-reason" name="reject_reason" rows="4" required maxlength="1000" class="w-full resize-none rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm outline-none focus:border-rose-400 focus:ring-4 focus:ring-rose-100" placeholder="Nêu rõ phần cần bổ sung hoặc chỉnh sửa..."></textarea>
                            <button type="submit" class="inline-flex h-9 w-full items-center justify-center rounded-lg bg-rose-600 px-3 text-xs font-bold text-white transition-colors duration-200 hover:bg-rose-700 cursor-pointer">Xác nhận từ chối</button>
                        </form>
                    </details>
                @endif

                @if($course->status === \App\Models\Course::STATUS_PUBLISHED)
                    <form method="POST" action="{{ route('admin.courses.archive', $course) }}" onsubmit="return confirm('Ẩn/lưu trữ khóa học này?')">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-lg border border-amber-100 bg-amber-50 px-4 py-2 text-sm font-bold text-amber-700 transition-colors duration-200 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-200 cursor-pointer">Ẩn/lưu trữ</button>
                    </form>
                @endif

                @if($course->status === \App\Models\Course::STATUS_ARCHIVED)
                    <form method="POST" action="{{ route('admin.courses.restore', $course) }}" onsubmit="return confirm('Khôi phục khóa học này?')">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 transition-colors duration-200 hover:bg-emerald-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-200 cursor-pointer">Khôi phục</button>
                    </form>
                @endif
            </div>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Học viên</span>
            <strong class="mt-2 block text-2xl font-bold text-slate-950">{{ number_format((int) $studentCount) }}</strong>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Chương</span>
            <strong class="mt-2 block text-2xl font-bold text-slate-950">{{ $curriculumSections->count() }}</strong>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Bài học</span>
            <strong class="mt-2 block text-2xl font-bold text-slate-950">{{ $totalLessons }}</strong>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Bài preview</span>
            <strong class="mt-2 block text-2xl font-bold text-slate-950">{{ $previewLessons }}</strong>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Doanh thu</span>
            <strong class="mt-2 block text-2xl font-bold text-slate-950">{{ number_format($courseRevenue, 0, ',', '.') }}đ</strong>
        </div>
    </section>

    <section class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,.65fr)]">
        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-bold text-slate-950">Thông tin khóa học</h3>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Trình độ</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $levelLabels[$course->level] ?? 'Chưa chọn' }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ngôn ngữ</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $course->language ?: 'Chưa khai báo' }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Giá gốc</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $formatPrice($course->price) }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Giá sau giảm</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $formatPrice($price) }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ngày tạo</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $course->created_at?->format('d/m/Y H:i') }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ngày cập nhật</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $course->updated_at?->format('d/m/Y H:i') }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ngày xuất bản</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $course->published_at?->format('d/m/Y H:i') ?? 'Chưa xuất bản' }}</dd>
                </div>
            </dl>

            <div class="mt-5">
                <h4 class="text-sm font-bold text-slate-950">Mô tả chi tiết</h4>
                <div class="mt-2 whitespace-pre-line text-sm leading-7 text-slate-600">{{ $course->description ?: 'Chưa có mô tả chi tiết.' }}</div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-bold text-slate-950">Giảng viên sở hữu</h3>
            <div class="mt-4 flex items-start gap-3">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-rose-100 text-sm font-bold text-rose-700">
                    @if($course->instructor && method_exists($course->instructor, 'avatarUrl'))
                        <img src="{{ $course->instructor->avatarUrl() }}" alt="{{ $course->instructor->name }}" class="h-full w-full object-cover">
                    @else
                        {{ strtoupper(substr($course->instructor?->name ?? 'G', 0, 1)) }}
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="truncate font-bold text-slate-950">{{ $course->instructor?->name ?? 'Chưa gán giảng viên' }}</div>
                    <div class="truncate text-sm text-slate-500">{{ $course->instructor?->email }}</div>
                </div>
            </div>
            <dl class="mt-5 grid gap-3">
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Số khóa học</dt>
                    <dd class="mt-1 font-semibold text-slate-950">{{ number_format($instructorCourseCount) }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Tổng học viên</dt>
                    <dd class="mt-1 font-semibold text-slate-950">{{ number_format($instructorStudentCount) }}</dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-bold uppercase tracking-wide text-rose-600">Nội dung kiểm duyệt</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Chương và bài học</h3>
            </div>
            @if($previewVideoUrl)
                <a href="{{ $previewVideoUrl }}" target="_blank" class="inline-flex h-9 items-center rounded-lg border border-indigo-100 bg-indigo-50 px-3 text-xs font-bold text-indigo-700 transition-colors duration-200 hover:bg-indigo-100 cursor-pointer">Mở video giới thiệu</a>
            @endif
        </div>

        @if($previewVideoUrl)
            <div class="mt-4 aspect-video overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                @if($previewIsYoutube)
                    <iframe src="{{ $previewVideoUrl }}" title="Video giới thiệu khóa học" class="h-full w-full border-0" allowfullscreen></iframe>
                @else
                    <video src="{{ $previewVideoUrl }}" controls playsinline preload="metadata" class="h-full w-full object-contain"></video>
                @endif
            </div>
        @endif

        <div class="mt-5 space-y-4">
            @forelse($curriculumSections as $section)
                <article class="overflow-hidden rounded-lg border border-slate-200">
                    <div class="flex flex-col gap-1 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h4 class="font-bold text-slate-950">{{ $section->title }}</h4>
                            @if($section->description)
                                <p class="mt-1 text-sm text-slate-500">{{ $section->description }}</p>
                            @endif
                        </div>
                        <span class="text-xs font-bold text-slate-500">{{ $section->lessons->count() }} bài học</span>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @forelse($section->lessons as $lesson)
                            @php
                                $hasVideo = filled($lesson->original_video_key) || filled($lesson->hls_manifest_key) || filled($lesson->video_path) || filled($lesson->video_url);
                                $lessonDuration = (int) ($lesson->duration_seconds ?: $lesson->duration ?: 0);
                            @endphp
                            <div class="p-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-700">{{ $typeLabels[$lesson->type] ?? $lesson->type }}</span>
                                            <span class="rounded-full border px-2.5 py-1 text-xs font-bold {{ $hasVideo ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-500' }}">{{ $hasVideo ? 'Có video' : 'Chưa có video' }}</span>
                                            @if($lesson->is_preview)
                                                <span class="rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">Preview</span>
                                            @endif
                                        </div>
                                        <h5 class="mt-2 font-bold text-slate-950">{{ $lesson->title }}</h5>
                                        @if($lesson->content)
                                            <p class="mt-2 line-clamp-3 whitespace-pre-line text-sm leading-6 text-slate-600">{{ $lesson->content }}</p>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        @if($lesson->video_url)
                                            <a href="{{ $lesson->video_url }}" target="_blank" class="rounded-lg border border-indigo-200 px-3 py-2 text-xs font-bold text-indigo-700 transition-colors duration-200 hover:bg-indigo-50 cursor-pointer">Xem video URL</a>
                                        @endif
                                        @if($lesson->video_path)
                                            <span class="rounded-lg border border-emerald-200 px-3 py-2 text-xs font-bold text-emerald-700">Video file ({{ \Illuminate\Support\Str::endsWith($lesson->video_path, '.mp4') ? 'Chưa bảo mật HLS' : 'Đã bảo mật' }})</span>
                                        @endif
                                        @if($lesson->document_file)
                                            <a href="{{ asset('storage/'.$lesson->document_file) }}" target="_blank" class="rounded-lg border border-sky-200 px-3 py-2 text-xs font-bold text-sky-700 transition-colors duration-200 hover:bg-sky-50 cursor-pointer">Xem tài liệu</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-5 text-sm text-slate-500">Chương này chưa có bài học.</div>
                        @endforelse
                    </div>
                </article>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                    <h4 class="font-bold text-slate-900">Khóa học chưa có curriculum</h4>
                    <p class="mt-1 text-sm text-slate-500">Admin chỉ xem nội dung kiểm duyệt, không chỉnh sửa chương/bài học tại đây.</p>
                </div>
            @endforelse
        </div>
    </section>
</div>

</x-admin-layout>
