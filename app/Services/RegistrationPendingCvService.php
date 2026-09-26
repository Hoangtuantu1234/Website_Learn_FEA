<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RegistrationPendingCvService
{
    public const SESSION_KEY = 'registration.pending_cv';

    public const DISK = 'local';

    public const DIRECTORY = 'registration-pending-cvs';

    public const TTL_HOURS = 24;

    /**
     * @return array{disk: string, path: string, original_name: string, expires_at: string}|null
     */
    public function current(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || empty($pending['path']) || empty($pending['expires_at'])) {
            return null;
        }

        if (now()->greaterThan($pending['expires_at']) || ! $this->isSafePendingPath((string) $pending['path'])) {
            $this->forget($request);

            return null;
        }

        if (! Storage::disk(self::DISK)->exists($pending['path'])) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return [
            'disk' => self::DISK,
            'path' => (string) $pending['path'],
            'original_name' => (string) ($pending['original_name'] ?? 'cv.pdf'),
            'expires_at' => (string) $pending['expires_at'],
        ];
    }

    public function persistIfValid(UploadedFile $file, Request $request): void
    {
        $this->forget($request);

        $directory = self::DIRECTORY.'/'.hash('sha256', $request->session()->getId());
        $path = $file->storeAs($directory, Str::uuid()->toString().'.pdf', self::DISK);
        if (! is_string($path)) {
            throw new RuntimeException('Không thể lưu tạm CV đăng ký.');
        }

        $request->session()->put(self::SESSION_KEY, [
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName() ?: 'cv.pdf',
            'expires_at' => now()->addHours(self::TTL_HOURS)->toIso8601String(),
        ]);
    }

    public function promoteToPublic(Request $request): ?string
    {
        $pending = $this->current($request);
        if (! $pending) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get($pending['path']);
        if ($contents === null) {
            $this->forget($request);

            return null;
        }

        $publicPath = 'instructor_cvs/'.Str::uuid()->toString().'.pdf';
        if (! Storage::disk('public')->put($publicPath, $contents)) {
            throw new RuntimeException('Không thể lưu CV giảng viên.');
        }

        return $publicPath;
    }

    public function forget(Request $request): void
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $path = is_array($pending) ? (string) ($pending['path'] ?? '') : '';
        if ($path !== '' && $this->isSafePendingPath($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function pruneExpired(): int
    {
        $deleted = 0;
        $cutoff = now()->subHours(self::TTL_HOURS)->getTimestamp();

        foreach (Storage::disk(self::DISK)->allFiles(self::DIRECTORY) as $path) {
            if (! $this->isSafePendingPath($path)) {
                continue;
            }

            $lastModified = Storage::disk(self::DISK)->lastModified($path);
            if ($lastModified !== false && $lastModified <= $cutoff) {
                Storage::disk(self::DISK)->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function isSafePendingPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', ltrim($path, '/'));

        return Str::startsWith($normalized, self::DIRECTORY.'/')
            && ! str_contains($normalized, '..');
    }
}
