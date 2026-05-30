<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImagePlatformUpload extends Model
{
    use HasFactory;

    public const PLATFORM_GOOGLE_PLACES = 'google_places';
    public const PLATFORM_YELP_PORTFOLIO = 'yelp_portfolio';
    public const PLATFORM_YELP_BIZ = 'yelp_biz';

    protected $fillable = [
        'project_image_id',
        'platform',
        'remote_id',
        'remote_url',
        'caption',
        'uploaded_at',
        'metadata',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function projectImage(): BelongsTo
    {
        return $this->belongsTo(ProjectImage::class);
    }

    /**
     * Convenience upsert keyed by (project_image_id, platform).
     */
    public static function record(int $projectImageId, string $platform, array $attrs): self
    {
        return static::updateOrCreate(
            ['project_image_id' => $projectImageId, 'platform' => $platform],
            $attrs + ['uploaded_at' => now()],
        );
    }
}
