<?php

namespace App\Services\Social;

use App\Models\ImageSocialPost;
use Illuminate\Support\Carbon;
use SsSystems\Platform\Social\Contracts\PublishedPostStats;
use SsSystems\Platform\Social\PostStatsRow;

/**
 * The kit's PublishedPostStats contract, wrapping this site's own
 * App\Models\ImageSocialPost (site-scoped via its own BelongsToSite).
 */
class ImageSocialPostStats implements PublishedPostStats
{
    public function latestFor(string $platform): ?PostStatsRow
    {
        $latest = ImageSocialPost::where('platform', $platform)->latest('id')->first();

        if ($latest === null) {
            return null;
        }

        return new PostStatsRow($latest->published_at ?? $latest->created_at, $latest->status);
    }

    public function countPublished30d(string $platform): int
    {
        return ImageSocialPost::where('platform', $platform)
            ->where('status', 'published')
            ->where('published_at', '>=', Carbon::now()->subDays(30))
            ->count();
    }
}
