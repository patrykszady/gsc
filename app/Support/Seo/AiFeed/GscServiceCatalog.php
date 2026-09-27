<?php

namespace App\Support\Seo\AiFeed;

use App\Models\Service;
use SsSystems\Platform\Seo\AiFeed\Contracts\ServiceCatalogFeed;

/**
 * BUG FIX (2026-09-27): the old `AiFeedController` served a hardcoded
 * 5-entry `$services` array typed directly into the controller. The real
 * `App\Models\Service` catalog — the admin's Services screen, the same
 * table `Project::projectTypes()`/the project form's "Project Type"
 * vocabulary reads — had grown past those 5 rows and been renamed/reordered
 * independently; the feed had silently stopped matching what the site (and
 * Google Business Profile) actually offers. There is no "published" flag
 * on `services` (every row IS the live catalog, same as jpeterson-design's
 * table), so every row in `Service::ordered()` is served, in the admin's
 * own sort order.
 */
final class GscServiceCatalog implements ServiceCatalogFeed
{
    public function services(): array
    {
        return Service::ordered()->get()->map(fn (Service $s): array => array_filter([
            'slug' => $s->slug,
            'name' => $s->name,
            'url' => $s->url(),
            'description' => $s->blurb,
        ]))->values()->all();
    }
}
