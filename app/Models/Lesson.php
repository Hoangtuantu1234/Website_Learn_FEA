<?php

namespace App\Models;

use App\Services\QuizContentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lesson extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('not_archived', fn ($query) => $query->whereNull('archived_at'));

        static::deleting(function (Lesson $lesson) {
            $quiz = $lesson->quiz()->first();
            if ($quiz) {
                app(QuizContentService::class)->purgeQuiz($quiz);
            }
        });
    }

    public const TYPE_VIDEO = 'video';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_QUIZ = 'quiz';

    public const TYPE_ASSIGNMENT = 'assignment';

    public const TYPES = [
        self::TYPE_VIDEO,
        self::TYPE_DOCUMENT,
        self::TYPE_QUIZ,
        self::TYPE_ASSIGNMENT,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'course_id', 'section_id', 'chapter_id', 'title', 'type',
        'video_url', 'video_path', 'original_video_key', 'hls_manifest_key',
        'upload_status', 'processing_status',
        'video_original_name', 'video_mime',
        'video_size', 'content', 'document_file', 'duration',
        'duration_seconds', 'is_preview', 'is_required', 'sort_order', 'status',
        'attachments', 'subtitles', 'ai_summary', 'content_version',
    ];

    protected $attributes = [
        'video_size' => 0,
        'duration_seconds' => 0,
        'is_preview' => false,
        'is_required' => true,
        'sort_order' => 0,
        'status' => 'draft',
    ];

    public function setVideoSizeAttribute($value): void
    {
        $this->attributes['video_size'] = $value !== null ? (int) $value : 0;
    }

    protected function casts(): array
    {
        return [
            'is_preview' => 'boolean',
            'is_required' => 'boolean',
            'video_size' => 'integer',
            'content_version' => 'integer',
            'attachments' => 'array',
            'subtitles' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    public function belongsToCourse(Course $course): bool
    {
        if ((int) $this->course_id === (int) $course->id) {
            return true;
        }

        if ($this->section_id && $this->section()->where('course_id', $course->id)->exists()) {
            return true;
        }

        return $this->chapter_id && $this->chapter()->where('course_id', $course->id)->exists();
    }

    public function owningCourse(): ?Course
    {
        $this->loadMissing(['section.course', 'chapter.course', 'course']);

        return $this->section?->course
            ?? $this->course
            ?? $this->chapter?->course;
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(Assignment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LessonVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(LessonVersion::class, 'published_version_id');
    }

    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(LessonVersion::class, 'draft_version_id');
    }

    public function discussions(): HasMany
    {
        return $this->hasMany(Discussion::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LessonComment::class);
    }

    public function videoNotes(): HasMany
    {
        return $this->hasMany(VideoNote::class);
    }

    public function lessonNotes(): HasMany
    {
        return $this->hasMany(LessonNote::class);
    }

    public function videoModeration(): HasOne
    {
        return $this->hasOne(VideoModeration::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function aiSummaries(): HasMany
    {
        return $this->hasMany(AiSummary::class);
    }

    public function aiSummary(): HasOne
    {
        return $this->hasOne(AiSummary::class)->where('language', 'vi');
    }

    public function lessonAiSummary(): HasOne
    {
        return $this->hasOne(LessonAiSummary::class);
    }

    public function aiChatMessages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class);
    }

    /**
     * Kiểm tra video đã sẵn sàng phát HLS hay chưa (S3 hoặc local)
     */
    public function isHlsReady(): bool
    {
        if ($this->processing_status === 'completed') {
            return true;
        }

        if (filled($this->hls_manifest_key)) {
            return true;
        }

        if (filled($this->video_path) && str_ends_with($this->video_path, '.m3u8')) {
            return true;
        }

        if (\Illuminate\Support\Facades\Storage::disk('local')->exists('lesson-hls/'.$this->id.'/playlist.m3u8')) {
            return true;
        }

        return false;
    }

    public function hasVideoSource(): bool
    {
        return $this->type === self::TYPE_VIDEO
            && (
                filled($this->original_video_key)
                || filled($this->hls_manifest_key)
                || filled($this->video_path)
                || filled($this->video_url)
                || filled($this->hls_playlist)
                || filled($this->hls_path)
            );
    }

    public function effectiveDurationSeconds(): int
    {
        return max(0, (int) ($this->duration_seconds ?: $this->duration ?: 0));
    }

    /**
     * Kiểm tra video có đang trong quá trình chuyển đổi HLS hay không
     */
    public function isProcessing(): bool
    {
        if ($this->isHlsReady()) {
            return false;
        }

        if ($this->upload_status === 'pending') {
            return true;
        }

        if (in_array($this->processing_status, ['processing', 'pending'], true)) {
            return true;
        }

        return (filled($this->original_video_key) || filled($this->video_path))
            && $this->type === self::TYPE_VIDEO
            && empty($this->video_url)
            && $this->processing_status !== 'failed';
    }

    /**
     * Kiểm tra quá trình xử lý video có bị thất bại hay không
     */
    public function hasFailedProcessing(): bool
    {
        if ($this->upload_status === 'pending') {
            return false;
        }

        return $this->processing_status === 'failed' && ! $this->isHlsReady();
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }
}
