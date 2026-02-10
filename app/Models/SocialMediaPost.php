<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMediaPost extends Model
{
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
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function projectImage(): BelongsTo
    {
        return $this->belongsTo(ProjectImage::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                            */
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
    /*  Helpers                                                           */
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
        return ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->where(function ($q) {
                // Must have AI content (alt_text) so we know the image is processed
                $q->whereNotNull('alt_text')->where('alt_text', '!=', '');
            })
            ->whereDoesntHave('socialMediaPosts', function ($q) use ($platform) {
                $q->where('platform', $platform)
                    ->whereIn('status', ['published', 'pending']);
            });
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
     */
    public function getFullCaptionAttribute(): string
    {
        $parts = [];

        if ($this->caption) {
            $parts[] = $this->caption;
        }

        if ($this->link_url) {
            $parts[] = "\n🔗 {$this->link_url}";
        }

        if ($this->hashtags) {
            $parts[] = "\n{$this->hashtags}";
        }

        return implode("\n", $parts);
    }
}
