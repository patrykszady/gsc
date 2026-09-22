<?php

namespace App\Support\Seo\Reports;

use App\Models\AreaServed;
use SsSystems\Platform\Reports\Contracts\AreaCatalog;

/**
 * Every service-area landing page this site has — the kit's `area_catalog`
 * capability, read by HealthReport's on-page pillar and AreaPagesAuditReport.
 * content_complete mirrors the original SeoHealth::scoreOnPage()'s exact
 * check: intro, local_intro and landmarks all non-null and non-empty.
 */
final class EloquentAreaCatalog implements AreaCatalog
{
    public function areas(): array
    {
        return AreaServed::query()
            ->orderBy('slug')
            ->get(['id', 'slug', 'city', 'intro', 'local_intro', 'landmarks'])
            ->map(fn (AreaServed $area): array => [
                'id' => $area->id,
                'slug' => (string) $area->slug,
                'city' => $area->city,
                'content_complete' => filled($area->intro) && filled($area->local_intro) && filled($area->landmarks),
            ])
            ->all();
    }
}
