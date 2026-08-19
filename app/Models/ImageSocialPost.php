<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageSocialPost extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'project_image_id',
        'platform',
        'status',
        'caption',
        'hashtags',
        'link_url',
        'platform_post_id',
        'platform_permalink',
        'error_message',
        'published_at',
        'scheduled_for',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'scheduled_for' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function projectImage(): BelongsTo
    {
        return $this->belongsTo(ProjectImage::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    public function markPublished(string $platformPostId, ?string $permalink = null): void
    {
        $this->update([
            'status' => 'published',
            'platform_post_id' => $platformPostId,
            'platform_permalink' => $permalink,
            'published_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }

    /**
     * Get project images that have never been posted to a given platform.
     */
    public static function unpostedImagesQuery(string $platform)
    {
        $query = ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->where(function ($q) {
                // Must have AI content (alt_text) so we know the image is processed
                $q->whereNotNull('alt_text')->where('alt_text', '!=', '');
            })
            ->whereDoesntHave('imageSocialPosts', function ($q) use ($platform) {
                $q->where('platform', $platform)
                    ->whereIn('status', ['published', 'pending']);
            });

        // Keep Facebook media distinct from Instagram media.
        if ($platform === 'facebook') {
            $query->whereDoesntHave('imageSocialPosts', function ($q) {
                $q->where('platform', 'instagram')
                    ->whereIn('status', ['published', 'pending']);
            });
        }

        return $query;
    }

    /**
     * Pick a random image that has never been posted to the given platform.
     */
    public static function pickRandomUnposted(string $platform): ?ProjectImage
    {
        return static::unpostedImagesQuery($platform)->inRandomOrder()->first();
    }

    /**
     * Full caption including hashtags and link.
     *
     * Instagram does not make caption URLs clickable, so we omit the URL
     * line for IG to keep captions clean. Facebook (and others) keep it
     * because those URLs are clickable and crawlable.
     */
    public function getFullCaptionAttribute(): string
    {
        $parts = [];

        if ($this->caption) {
            $parts[] = $this->caption;
        }

        if ($this->link_url && $this->platform !== 'instagram') {
            $parts[] = "\n🔗 {$this->link_url}";
        }

        if ($this->hashtags) {
            $parts[] = "\n{$this->hashtags}";
        }

        return implode("\n", $parts);
    }

    /** Management-API shape — see Project::toApiArray(). */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'status' => $this->status,
            'caption' => $this->caption,
            'hashtags' => $this->hashtags,
            'link_url' => $this->link_url,
            'platform_post_id' => $this->platform_post_id,
            'platform_permalink' => $this->platform_permalink,
            'error_message' => $this->error_message,
            'published_at' => optional($this->published_at)->toIso8601String(),
            'scheduled_for' => optional($this->scheduled_for)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'project_image' => $this->projectImage?->toSocialApiArray(),
        ];
    }
}
