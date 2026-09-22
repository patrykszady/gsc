<?php

namespace App\Support\Seo\Inspection;

use App\Models\Tracked404;
use SsSystems\Platform\Seo\Inspection\Contracts\TrackedPaths;

/**
 * The kit's TrackedPaths, over this site's own 404 tracker — the exact query
 * the original command's offSitemapUrls() ran for the 'tracked' pool.
 */
final class TrackedPathsFromModel implements TrackedPaths
{
    public function recent404Paths(): array
    {
        return Tracked404::query()
            ->where('user_agent', 'like', '%Googlebot%')
            ->orderByDesc('hit_count')
            ->pluck('path')
            ->all();
    }
}
