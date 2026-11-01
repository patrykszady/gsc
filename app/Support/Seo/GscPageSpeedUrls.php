<?php

namespace App\Support\Seo;

use SsSystems\Platform\Seo\PageSpeed\Contracts\PageSpeedUrlSource;
use SsSystems\Platform\Seo\PageSpeed\SitemapUrlSource;

/**
 * gsc's own PageSpeedUrlSource for the kit's seo:psi-sync: config('seo.
 * psi_urls')'s pinned pages first (labelled by SitemapUrlSource's own
 * path-segment classifier — the same 'home'/'services'/'areas-served'/…
 * labels --only and the CWV report group by, so a pinned entry and a
 * sitemap-discovered one classify identically), topped up from this site's
 * own sitemap.xml (the injected kit SitemapUrlSource) up to
 * config('seo.psi_max_urls').
 *
 * The retired App\Console\Commands\SyncPageSpeedInsights also expanded the
 * pool with gsc's top-28-day Search-Console pages and carried a dead-URL
 * probe/circuit-breaker/failed-url retry cache (see that class's own
 * docblock, since deleted) — none of those have a kit capability contract,
 * so they are deliberately NOT ported here: a 404 or 5xx page simply fails
 * that one PSI call for the day, the same way the kit's own
 * PageSpeedClient reports any other failure (2026-11-01).
 */
final class GscPageSpeedUrls implements PageSpeedUrlSource
{
    public function __construct(private readonly SitemapUrlSource $sitemap) {}

    /** @return list<array{url: string, template: string}> */
    public function urls(): array
    {
        $max = max(1, (int) config('seo.psi_max_urls', 60));

        $urls = [];
        $seen = [];

        foreach ((array) config('seo.psi_urls', []) as $url) {
            $url = trim((string) $url);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $urls[] = ['url' => $url, 'template' => SitemapUrlSource::defaultClassifier($url)];
        }

        if (count($urls) >= $max) {
            return array_slice($urls, 0, $max);
        }

        foreach ($this->sitemap->urls() as $row) {
            if (count($urls) >= $max) {
                break;
            }

            $url = (string) $row['url'];
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $urls[] = $row;
        }

        return $urls;
    }
}
