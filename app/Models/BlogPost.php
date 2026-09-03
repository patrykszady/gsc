<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BlogPost extends Model
{
    use BelongsToSite;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'project_id', 'slug', 'title', 'excerpt', 'body', 'meta_title', 'meta_description',
        'status', 'writer', 'published_at', 'dated_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'dated_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (BlogPost $post): void {
            if (empty($post->slug)) {
                $post->slug = static::uniqueSlug($post->title);
            }
        });
    }

    /**
     * The date a story is shown with: the month the project was completed,
     * on a day picked deterministically from the project id — so every
     * regeneration keeps the same date. Never in the future.
     */
    public static function dateFor(Project $project): \Illuminate\Support\Carbon
    {
        $anchor = $project->completed_at ?? $project->created_at ?? now();
        $month = $anchor->copy()->startOfMonth();

        mt_srand((int) $project->id * 7919 + 17);
        $day = mt_rand(1, $month->daysInMonth);
        mt_srand();

        $date = $month->copy()->day($day)->startOfDay();

        return $date->isFuture() ? now()->startOfDay() : $date;
    }

    /**
     * A signed link that shows the post even while unpublished. Seven days,
     * then it 404s like any other draft. This is the only way to see a draft.
     */
    public function previewUrl(): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('blog.show', now()->addDays(7), ['post' => $this->slug, 'preview' => 1]);
    }

    /** The date shown on the post and the index. */
    public function displayDate(): ?\Illuminate\Support\Carbon
    {
        return $this->dated_at ?? $this->published_at ?? $this->created_at;
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $n = 1;
        while (static::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->published_at && $this->published_at->lte(now());
    }

    public function url(): string
    {
        return url('/blog/' . $this->slug);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_title' => $this->project?->title,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'status' => $this->status,
            'writer' => $this->writer,
            'published_at' => $this->published_at?->toIso8601String(),
            'dated_at' => $this->dated_at?->toDateString(),
            'url' => $this->url(),
            'preview_url' => $this->previewUrl(),
            'cover_url' => $this->project?->cover()?->getWebpThumbnailUrl('medium'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
