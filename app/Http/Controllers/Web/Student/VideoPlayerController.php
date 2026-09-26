<?php

namespace App\Http\Controllers\Web\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\VideoAccessLog;
use App\Models\VideoWatchHistory;
use App\Services\LearningProgressService;
use App\Services\LessonVideoSourceService;
use App\Services\VideoTokenService;
use hisorange\BrowserDetect\Parser as Browser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// If using hisorange/browser-detect, otherwise basic parsing

class VideoPlayerController extends Controller
{
    public function __construct(
        private VideoTokenService $tokenService,
        private LessonVideoSourceService $videoSourceService
    ) {}

    /**
     * Tạo token để xem video
     */
    public function getToken(Request $request, Lesson $lesson)
    {
        $user = $request->user();

        // Kiểm tra quyền truy cập (đã mua khóa học)
        $course = $this->courseForLesson($lesson);
        $lesson = $this->videoSourceService->forViewer($lesson, $user->id);
        $hasAccess = $course && Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->withLearningAccess()
            ->exists();

        if (! $hasAccess && ! $lesson->is_preview && ! $user->isAdmin() && (! $course || (int) $course->instructor_id !== (int) $user->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $token = $this->tokenService->generateToken($user->id, $lesson->id);

        // Ghi log truy cập video (tạo log mới nếu chưa có trong session xem hiện tại)
        VideoAccessLog::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'browser' => $this->getBrowserName($request->userAgent()),
            'platform' => $this->getPlatformName($request->userAgent()),
            'device' => $this->getDeviceType($request->userAgent()),
            'watch_started_at' => now(),
        ]);

        return response()->json(['token' => $token]);
    }

    /**
     * Lấy playlist m3u8 (master.m3u8 hoặc playlist.m3u8)
     */
    public function playlist(Request $request, Lesson $lesson)
    {
        $token = $request->query('token');
        $lesson = $this->resolveAuthorizedLesson($request, $lesson, is_string($token) ? $token : null);
        if (! $lesson) {
            return response('Not found', 404, $this->protectedMediaHeaders());
        }

        $directories = $this->videoSourceService->directories($lesson);
        $content = null;

        if ($this->usesS3() && $directories['s3']) {
            // Cache raw content by media location. Signed URLs and viewer tokens
            // must never be shared between lesson versions or viewing sessions.
            foreach (['playlist.m3u8', basename($lesson->hls_manifest_key ?: 'master.m3u8')] as $name) {
                $key = $directories['s3'].'/'.$name;
                try {
                    $content = Cache::remember('hls_source_v2_'.hash('sha256', config('filesystems.disks.s3.bucket').'|'.$key),
                        now()->addMinutes(5), fn () => Storage::disk('s3')->get($key));
                    if ($content !== null) {
                        break;
                    }
                } catch (\Throwable $e) {
                    Log::debug('HLS manifest unavailable', ['key' => $key]);
                }
            }
        }

        if ($content === null && $directories['local']) {
            $names = str_ends_with($request->path(), 'playlist.m3u8')
                ? ['playlist.m3u8', 'master.m3u8'] : ['master.m3u8', 'playlist.m3u8'];
            if (filled($lesson->video_path)) {
                $names[] = basename($lesson->video_path);
            }
            foreach (array_unique($names) as $name) {
                $key = $directories['local'].'/'.$name;
                if (Storage::disk('local')->exists($key)) {
                    $content = Storage::disk('local')->get($key);
                    break;
                }
            }
        }

        if ($content === null) {
            return response('Not found', 404, $this->protectedMediaHeaders());
        }

        $withToken = fn (string $uri) => $uri.(str_contains($uri, '?') ? '&' : '?').'token='.urlencode($token);
        $lines = explode("\n", $content);
        foreach ($lines as &$line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $path = parse_url($line, PHP_URL_PATH) ?: $line;
                $basename = basename($path);
                if (preg_match('/^[a-zA-Z0-9_-]+\.ts$/D', $basename)) {
                    $line = $withToken($basename);

                    continue;
                }
                $line = $withToken($line);
            } elseif (str_contains($line, 'URI=')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', function (array $match) use ($withToken) {
                    $uriPath = parse_url($match[1], PHP_URL_PATH) ?: $match[1];

                    return 'URI="'.$withToken(basename($uriPath)).'"';
                }, $line);
            }
        }

        return response(implode("\n", $lines), 200, $this->protectedMediaHeaders([
            'Content-Type' => 'application/vnd.apple.mpegurl; charset=utf-8',
        ]));
    }

    public function key(Request $request, Lesson $lesson)
    {
        $token = $request->query('token');
        $lesson = $this->resolveAuthorizedLesson($request, $lesson, is_string($token) ? $token : null);
        if (! $lesson) {
            return response('Not found', 404, $this->protectedMediaHeaders());
        }

        $directories = $this->videoSourceService->directories($lesson);
        $content = null;
        if ($this->usesS3() && $directories['s3']) {
            try {
                $content = Storage::disk('s3')->get($directories['s3'].'/enc.key');
            } catch (\Throwable) {
                // A local mirror may be available for the same version.
            }
        }
        if ($content === null && $directories['local'] && Storage::disk('local')->exists($directories['local'].'/enc.key')) {
            $content = Storage::disk('local')->get($directories['local'].'/enc.key');
        }
        if ($content === null) {
            return response('Not found', 404, $this->protectedMediaHeaders());
        }

        return response($content, 200, $this->protectedMediaHeaders([
            'Content-Type' => 'application/octet-stream',
        ]));
    }

    public function segment(Request $request, Lesson $lesson, $segment)
    {
        $token = $request->query('token');
        if (! preg_match('/^[a-zA-Z0-9_-]+\.ts$/D', $segment)) {
            return response('Not found', 404, $this->protectedMediaHeaders());
        }

        $lesson = $this->resolveAuthorizedLesson($request, $lesson, is_string($token) ? $token : null);
        if (! $lesson) {
            return response('Not found', 404, $this->protectedMediaHeaders());
        }

        $directories = $this->videoSourceService->directories($lesson);
        $headers = $this->protectedMediaHeaders([
            'Content-Type' => 'video/mp2t',
        ]);

        if ($this->usesS3() && $directories['s3']) {
            try {
                $key = $directories['s3'].'/'.$segment;
                $stream = Storage::disk('s3')->readStream($key);
                if (is_resource($stream)) {
                    return response()->stream(function () use ($stream) {
                        fpassthru($stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }, 200, $headers);
                }
            } catch (\Throwable $e) {
                Log::warning('S3 segment read error: '.$e->getMessage());
            }
        }

        if ($directories['local']) {
            $key = $directories['local'].'/'.$segment;
            if (Storage::disk('local')->exists($key)) {
                return response()->file(Storage::disk('local')->path($key), $headers);
            }
        }

        return response('Not found', 404, $this->protectedMediaHeaders());
    }

    private function usesS3(): bool
    {
        return ! empty(config('filesystems.disks.s3.key')) && ! empty(config('filesystems.disks.s3.bucket'));
    }

    private function resolveAuthorizedLesson(Request $request, Lesson $lesson, ?string $token): ?Lesson
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $sessionUserId = $request->user()?->id;
        if (! $this->tokenService->verifyToken($token, $lesson->id, $sessionUserId)) {
            return null;
        }

        $tokenUserId = $this->tokenService->getUserIdFromToken($token);
        if (! $tokenUserId) {
            return null;
        }

        $video = $this->videoSourceService->forViewer($lesson, $tokenUserId);
        $course = $this->courseForLesson($video);

        return $this->viewerCanAccess($tokenUserId, $video, $course) ? $video : null;
    }

    private function viewerCanAccess(int $userId, Lesson $lesson, ?Course $course): bool
    {
        if ($lesson->is_preview) {
            return true;
        }

        $user = User::query()->find($userId);
        if (! $user || ! $course) {
            return false;
        }

        if ($user->isAdmin() || (int) $course->instructor_id === (int) $user->id) {
            return true;
        }

        return Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->withLearningAccess()
            ->exists();
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function protectedMediaHeaders(array $extra = []): array
    {
        return array_merge([
            'Access-Control-Allow-Origin' => rtrim((string) config('app.url'), '/'),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], $extra);
    }

    /** Update watch progress. */
    public function updateProgress(Request $request, Lesson $lesson, LearningProgressService $progressService)
    {
        $user = $request->user();
        $course = $this->courseForLesson($lesson);
        abort_unless($course, 404);

        $validated = $request->validate([
            'current_time' => ['nullable', 'integer', 'min:0'],
            'last_position_seconds' => ['nullable', 'integer', 'min:0'],
            'furthest_position_seconds' => ['nullable', 'integer', 'min:0'],
            'played_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'video_duration_seconds' => ['nullable', 'numeric', 'min:0'],
            'client_updated_at' => ['nullable', 'date'],
        ]);

        $currentTime = (int) ($validated['last_position_seconds'] ?? $validated['current_time'] ?? 0);
        $progress = $progressService->recordVideoProgress($user->id, $course, $lesson, [
            'last_position_seconds' => $currentTime,
            'furthest_position_seconds' => $validated['furthest_position_seconds'] ?? $currentTime,
            'played_seconds' => $validated['played_seconds'] ?? 0,
            'video_duration_seconds' => $validated['video_duration_seconds'] ?? null,
            'client_updated_at' => $validated['client_updated_at'] ?? null,
        ]);

        if ($progress['stale'] ?? false) {
            return response()->json($progress, 409);
        }

        // Cập nhật hoặc tạo mới history
        VideoWatchHistory::updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            [
                'course_id' => $course->id,
                'current_time' => $currentTime,
            ]
        );

        // Update video access log watch_ended_at and duration
        $log = VideoAccessLog::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->orderByDesc('id')
            ->first();

        if ($log && $log->watch_started_at) {
            $log->update([
                'watch_ended_at' => now(),
                'watch_duration' => max(0, now()->timestamp - $log->watch_started_at->timestamp),
            ]);
        }

        return response()->json($progress);
    }

    /**
     * Lấy tiến trình đang xem (current_time)
     */
    public function getProgress(Request $request, Lesson $lesson)
    {
        $user = $request->user();
        $progress = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        $history = VideoWatchHistory::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        return response()->json([
            'current_time' => (int) ($progress?->last_position_seconds ?? $history?->current_time ?? 0),
            'last_position_seconds' => (int) ($progress?->last_position_seconds ?? $history?->current_time ?? 0),
            'furthest_position_seconds' => (int) ($progress?->furthest_position_seconds ?? 0),
            'watched_seconds' => (int) ($progress?->watched_seconds ?? 0),
            'progress_percent' => (float) ($progress?->progress_percent ?? 0),
            'is_completed' => (bool) ($progress?->is_completed ?? false),
        ]);
    }

    private function courseForLesson(Lesson $lesson): ?Course
    {
        return $lesson->course
            ?? $lesson->section?->course
            ?? $lesson->chapter?->course;
    }

    private function getBrowserName($userAgent)
    {
        if (strpos($userAgent, 'Firefox') !== false) {
            return 'Firefox';
        }
        if (strpos($userAgent, 'Chrome') !== false) {
            return 'Chrome';
        }
        if (strpos($userAgent, 'Safari') !== false) {
            return 'Safari';
        }
        if (strpos($userAgent, 'Edge') !== false) {
            return 'Edge';
        }

        return 'Unknown';
    }

    private function getPlatformName($userAgent)
    {
        if (strpos($userAgent, 'Windows') !== false) {
            return 'Windows';
        }
        if (strpos($userAgent, 'Mac') !== false) {
            return 'macOS';
        }
        if (strpos($userAgent, 'Linux') !== false) {
            return 'Linux';
        }
        if (strpos($userAgent, 'Android') !== false) {
            return 'Android';
        }
        if (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) {
            return 'iOS';
        }

        return 'Unknown';
    }

    private function getDeviceType($userAgent)
    {
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', strtolower($userAgent))) {
            return 'Tablet';
        }
        if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i', strtolower($userAgent))) {
            return 'Mobile';
        }

        return 'Desktop';
    }
}
