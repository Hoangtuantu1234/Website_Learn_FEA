@props([
    'videoSource' => null,
    'lesson',
    'progressUrl' => null,
    'lessonProgress' => null,
    'requiredVideoPercent' => 80,
    'isEnrolled' => false,
])

@php
    $watchedSeconds = (int) ($lessonProgress['watched_seconds'] ?? 0);
    $lastPositionSeconds = (int) ($lessonProgress['last_position_seconds'] ?? $watchedSeconds);
    $furthestPositionSeconds = (int) ($lessonProgress['furthest_position_seconds'] ?? $watchedSeconds);
    $progressPercent = (float) ($lessonProgress['progress_percent'] ?? 0);
    $lessonCompleted = (bool) ($lessonProgress['is_completed'] ?? false);
    $durationSeconds = (int) ($lesson->duration_seconds ?: $lesson->duration ?: 0);

    // Kiểm tra video đã được convert sang HLS chưa qua Model Helper (không block network S3)
    $hasHls = app(\App\Services\LessonVideoSourceService::class)->hasHls($lesson);
    $isProcessing = $lesson->isProcessing();
    $hasFailed = $lesson->hasFailedProcessing();

    // Khởi tạo token trực tiếp từ server để video nạp ngay tức thì (0ms chờ AJAX)
    $initialToken = auth()->check()
        ? app(\App\Services\VideoTokenService::class)->generateToken(auth()->id(), $lesson->id)
        : null;
@endphp

<div class="learning-video-stage relative flex min-h-[220px] w-full items-center justify-center bg-[#1c1d1f] sm:min-h-[320px] lg:min-h-[calc(100vh-14rem)] overflow-hidden" id="video-container-{{ $lesson->id }}">
    @if($hasHls)
        <video
            id="learning-video-{{ $lesson->id }}"
            controls
            controlsList="nodownload noremoteplayback"
            disablePictureInPicture
            preload="metadata"
            playsinline
            oncontextmenu="return false"
            class="aspect-video max-h-[calc(100vh-14rem)] w-full max-w-full bg-black"
            @if($isEnrolled && $progressUrl)
                data-lesson-progress-video
                data-progress-url="{{ $progressUrl }}"
                data-lesson-id="{{ $lesson->id }}"
                data-initial-watched="{{ $watchedSeconds }}"
                data-initial-last-position="{{ $lastPositionSeconds }}"
                data-initial-furthest-position="{{ $furthestPositionSeconds }}"
                data-initial-progress-percent="{{ $progressPercent }}"
                data-initial-completed="{{ $lessonCompleted ? '1' : '0' }}"
                data-duration-seconds="{{ $durationSeconds }}"
                data-required-percent="{{ $requiredVideoPercent }}"
            @endif
        >
            Trình duyệt không hỗ trợ phát video HTML5.
        </video>

        {{-- Dynamic Watermark --}}
        @auth
            <div id="dynamic-watermark-{{ $lesson->id }}" class="absolute pointer-events-none select-none transition-all duration-[2000ms] ease-in-out" style="z-index: 50; font-size: 13px; color: rgba(255,255,255,0.08); letter-spacing: 1px;">
                {{ auth()->user()->email }} · ID: {{ auth()->id() }}
            </div>
        @endauth

        <script>
            (function () {
                const videoId      = 'learning-video-{{ $lesson->id }}';
                const lessonId     = '{{ $lesson->id }}';
                const watermark    = document.getElementById('dynamic-watermark-' + lessonId);
                const container    = document.getElementById('video-container-' + lessonId);
                let currentToken   = @json($initialToken);
                let hls            = null;
                let networkRetryCount = 0;

                function showPlayerNotice(msg) {
                    if (!container) return;
                    container.innerHTML = `
                        <div class="px-6 py-12 text-center text-sm text-white">
                            <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-indigo-500/20 text-indigo-400 mb-3">
                                <svg class="h-6 w-6 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <p class="font-semibold text-lg">${msg}</p>
                            <p class="mt-2 text-white/70">Vui lòng tải lại trang sau ít phút.</p>
                        </div>
                    `;
                }

                // ─── Watermark di chuyển ngẫu nhiên mỗi 5 giây ───
                if (watermark && container) {
                    function moveWatermark() {
                        const maxX = container.clientWidth  - watermark.clientWidth;
                        const maxY = container.clientHeight - watermark.clientHeight;
                        watermark.style.left = Math.max(0, Math.floor(Math.random() * maxX)) + 'px';
                        watermark.style.top  = Math.max(0, Math.floor(Math.random() * maxY)) + 'px';
                    }
                    moveWatermark();
                    setInterval(moveWatermark, 5000);
                }

                async function fetchToken() {
                    if (currentToken) return currentToken;
                    try {
                        const resp = await fetch(`/api/video/${lessonId}/token`, {
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                            },
                            credentials: 'same-origin'
                        });
                        if (resp.ok) {
                            const data = await resp.json();
                            currentToken = data.token || null;
                            return currentToken;
                        }
                    } catch (e) {
                        console.error('fetchToken error', e);
                    }
                    return null;
                }

                async function initHlsPlayer() {
                    const videoElement = document.getElementById(videoId);
                    if (!videoElement) return;

                    const token = await fetchToken();
                    if (!token) {
                        console.warn('Chưa lấy được video token hoặc bài học đang xử lý');
                        return;
                    }

                    const playlistUrl = `/api/video/hls/${lessonId}/master.m3u8?token=${token}`;
                    const requestedTime = Number(new URLSearchParams(window.location.search).get('t') || 0);
                    const initialTime = requestedTime > 0 ? requestedTime : 0;

                    function attachHls() {
                        if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                            if (hls) { hls.destroy(); }
                            hls = new Hls({
                                enableWorker: true,
                                lowLatencyMode: false,
                                startFragPrefetch: true,
                                maxBufferLength: 10,
                                maxMaxBufferLength: 30,
                                maxBufferSize: 30 * 1000 * 1000,
                                maxBufferHole: 0.5,
                                highBufferWatchdogPeriod: 2,
                                startLevel: -1,
                            });
                            hls.loadSource(playlistUrl);
                            hls.attachMedia(videoElement);

                            hls.on(Hls.Events.MANIFEST_PARSED, function () {
                                if (initialTime > 0) {
                                    videoElement.currentTime = initialTime;
                                }
                                videoElement.play().catch(function () {
                                    // Autoplay blocked by browser until user gesture
                                });
                            });

                            hls.on(Hls.Events.ERROR, function (_, data) {
                                if (data.fatal) {
                                    switch (data.type) {
                                        case Hls.ErrorTypes.NETWORK_ERROR:
                                            networkRetryCount++;
                                            if (networkRetryCount <= 3) {
                                                console.warn(`HLS Network error, retrying (${networkRetryCount}/3)...`, data);
                                                setTimeout(() => hls.startLoad(), 1000);
                                            } else {
                                                console.error('HLS Network error: Exceeded retry limits', data);
                                                hls.destroy();
                                                showPlayerNotice('Video đang được chuẩn bị. Vui lòng tải lại trang.');
                                            }
                                            break;
                                        case Hls.ErrorTypes.MEDIA_ERROR:
                                            console.warn('HLS Media error, recovering...', data);
                                            hls.recoverMediaError();
                                            break;
                                        default:
                                            console.error('HLS Unrecoverable error', data);
                                            hls.destroy();
                                            showPlayerNotice('Không thể phát video lúc này. Vui lòng tải lại trang.');
                                            break;
                                    }
                                }
                            });
                        } else if (videoElement.canPlayType('application/vnd.apple.mpegurl')) {
                            videoElement.src = playlistUrl;
                            videoElement.addEventListener('loadedmetadata', function () {
                                if (initialTime > 0) { videoElement.currentTime = initialTime; }
                                videoElement.play().catch(function () {});
                            });
                            videoElement.addEventListener('error', function () {
                                showPlayerNotice('Video đang được xử lý, vui lòng quay lại sau ít phút.');
                            });
                        }
                    }

                    if (typeof Hls !== 'undefined') {
                        attachHls();
                    } else {
                        const hlsScript = document.createElement('script');
                        hlsScript.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js';
                        hlsScript.onload = attachHls;
                        document.head.appendChild(hlsScript);
                    }

                    // Chống tua video: Chỉ áp dụng với Học viên CHƯA hoàn thành bài học (Admin, Giảng viên và học viên đã hoàn thành được tua tự do)
                    const isStudent = {{ (auth()->check() && auth()->user()->isStudent()) ? 'true' : 'false' }};
                    const lessonCompleted = {{ $lessonCompleted ? 'true' : 'false' }};
                    const shouldRestrictSeeking = isStudent && !lessonCompleted;

                    if (shouldRestrictSeeking) {
                        let maxAllowedTime = Number(videoElement.dataset.initialFurthestPosition || videoElement.dataset.initialWatched || 0);
                        let previousPlayhead = maxAllowedTime;

                        videoElement.addEventListener('timeupdate', () => {
                            // Chỉ mở rộng mốc thời lượng tối đa khi video đang phát tuần tự liên tục
                            if (!videoElement.seeking && !videoElement.paused) {
                                const delta = videoElement.currentTime - previousPlayhead;
                                if (delta > 0 && delta <= 1.5) {
                                    if (videoElement.currentTime > maxAllowedTime) {
                                        maxAllowedTime = videoElement.currentTime;
                                    }
                                }
                            }
                            previousPlayhead = videoElement.currentTime;
                        });

                        videoElement.addEventListener('seeking', () => {
                            // Cho phép tua lùi hoặc tua lại trong phạm vi đã học (dung sai 1 giây)
                            if (videoElement.currentTime > maxAllowedTime + 1) {
                                videoElement.currentTime = maxAllowedTime;
                            }
                        });

                        videoElement.addEventListener('seeked', () => {
                            if (videoElement.currentTime > maxAllowedTime + 1) {
                                videoElement.currentTime = maxAllowedTime;
                            }
                            previousPlayhead = videoElement.currentTime;
                        });
                    }

                    // Token tự refresh mỗi 8 phút để HLS không giữ URL quá hạn.
                    setInterval(async () => {
                        currentToken = null;
                        const t = await fetchToken();
                        if (t && hls) {
                            currentToken = t;
                            hls.loadSource(`/api/video/hls/${lessonId}/master.m3u8?token=${t}`);
                        }
                    }, 8 * 60 * 1000);

                    videoElement.dataset.progressManagedBy = 'learning-player.js';
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initHlsPlayer);
                } else {
                    initHlsPlayer();
                }
            })();
        </script>
    @elseif($lesson->video_url)
        @if(str_contains($lesson->video_url, 'youtube.com') || str_contains($lesson->video_url, 'youtu.be'))
            @php
                preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/', $lesson->video_url, $matches);
                $youtubeId = $matches[1] ?? null;
            @endphp
            @if($youtubeId)
                <iframe
                    id="youtube-player-{{ $lesson->id }}"
                    src="https://www.youtube.com/embed/{{ $youtubeId }}?enablejsapi=1"
                    class="aspect-video max-h-[calc(100vh-14rem)] w-full max-w-full bg-black border-0"
                    allowfullscreen
                    @if($isEnrolled && $progressUrl)
                        data-lesson-progress-youtube
                        data-progress-url="{{ $progressUrl }}"
                        data-lesson-id="{{ $lesson->id }}"
                        data-initial-completed="{{ $lessonCompleted ? '1' : '0' }}"
                    @endif
                ></iframe>
            @else
                <div class="px-6 py-12 text-center text-sm text-white">
                    <p class="font-semibold text-lg">Liên kết video bài học</p>
                    <a href="{{ $lesson->video_url }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">
                        Mở video trên YouTube ↗
                    </a>
                </div>
            @endif
        @elseif(str_contains($lesson->video_url, 'vimeo.com'))
            @php
                preg_match('/vimeo\.com\/(?:video\/)?([0-9]+)/', $lesson->video_url, $matches);
                $vimeoId = $matches[1] ?? null;
            @endphp
            @if($vimeoId)
                <iframe src="https://player.vimeo.com/video/{{ $vimeoId }}" class="aspect-video max-h-[calc(100vh-14rem)] w-full max-w-full bg-black border-0" allowfullscreen></iframe>
            @else
                <div class="px-6 py-12 text-center text-sm text-white">
                    <p class="font-semibold text-lg">Liên kết video Vimeo</p>
                    <a href="{{ $lesson->video_url }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">
                        Mở video trên Vimeo ↗
                    </a>
                </div>
            @endif
        @else
            <video src="{{ $lesson->video_url }}" controls controlsList="nodownload noremoteplayback" disablePictureInPicture playsinline oncontextmenu="return false" class="aspect-video max-h-[calc(100vh-14rem)] w-full max-w-full bg-black">
                Trình duyệt không hỗ trợ phát video HTML5.
            </video>
        @endif
    @elseif($isProcessing)
        <div class="px-6 py-12 text-center text-sm text-white">
            <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-indigo-500/20 text-indigo-400 mb-3">
                <svg class="h-6 w-6 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            <p class="font-semibold text-lg">Video đang được xử lý, vui lòng quay lại sau ít phút.</p>
            <p class="mt-2 text-white/70">Hệ thống đang tự động tối ưu hóa và chuyển đổi định dạng HLS đa thiết bị.</p>
        </div>
    @elseif($hasFailed)
        <div class="px-6 py-12 text-center text-sm text-white">
            <svg class="mx-auto h-12 w-12 text-rose-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <p class="font-semibold text-lg text-white">Xử lý video không thành công</p>
            <p class="mt-1 text-white/60">Tệp video chưa được chuyển đổi thành công. Giảng viên vui lòng tải lại video.</p>
        </div>
    @elseif($lesson->video_path || $lesson->original_video_key)
        <div class="px-6 py-12 text-center text-sm text-white/70">
            <svg class="mx-auto h-12 w-12 text-rose-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <p class="font-semibold text-lg text-white">Video không tồn tại</p>
            <p class="mt-1 text-white/60">Tệp video bài học này không tồn tại trên hệ thống hoặc đã bị xóa.</p>
        </div>
    @else
        <div class="px-6 py-12 text-center text-sm text-white/70">
            <svg class="mx-auto h-12 w-12 text-slate-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 002-2H5a2 2 0 002 2v8a2 2 0 002 2z" />
            </svg>
            <p class="font-semibold text-base text-white">Bài học này chưa có video</p>
            <p class="mt-1 text-xs text-white/60">Giảng viên chưa tải video lên cho bài học này.</p>
        </div>
    @endif
</div>
