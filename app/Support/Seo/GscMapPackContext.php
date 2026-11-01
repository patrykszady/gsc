<?php

namespace App\Support\Seo;

use App\Models\AreaServed;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Seo\MapPack\Contracts\MapPackContext;

/**
 * The two gsc-only facts the kit's MapPackSnapshot needs that no other site
 * is guaranteed to have — ported from the two inline closures
 * App\Http\Controllers\Api\Admin\V1\SeoReportController::mapPackSnapshot()
 * used to compute itself before the kit's MapPack module replaced it:
 *
 *   - areaPoints(): every served town with coordinates ($nearestTown's
 *     source list), so a grid point snaps to the nearest town we serve;
 *   - ourGoogleReviewCount(): how many (non-hidden) testimonials carry a
 *     Google review_urls entry, for the "pack leader has N reviews, we
 *     have M" gap — 0 when the review_urls table doesn't exist yet or we
 *     simply have none, never null (gsc always has a way to count this).
 */
final class GscMapPackContext implements MapPackContext
{
    public function areaPoints(): array
    {
        return AreaServed::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['city', 'slug', 'latitude', 'longitude'])
            ->map(fn (AreaServed $area): array => [
                'city' => (string) $area->city,
                'slug' => (string) $area->slug,
                'latitude' => (float) $area->latitude,
                'longitude' => (float) $area->longitude,
            ])
            ->values()
            ->all();
    }

    public function ourGoogleReviewCount(): ?int
    {
        if (! Schema::hasTable('review_urls')) {
            return 0;
        }

        return Testimonial::query()
            ->where('is_hidden', false)
            ->whereHas('reviewUrls', fn ($q) => $q->where('platform', 'google'))
            ->count();
    }
}
