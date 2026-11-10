<?php

namespace App\Support\Seo;

use SsSystems\Platform\Seo\Bing\Contracts\BingCoverageUrls as BingCoverageUrlsContract;

/**
 * The kit's SsSystems\Platform\Seo\Bing\Contracts\BingCoverageUrls — the
 * same absolute URL list this site's own GscErrorController::
 * sitemapUrlSet() tracks, so the Bing and Search Console coverage screens
 * count the same pages. Same sitemap.xml parse, verbatim, rather than a
 * fetch over HTTP: kept as a per-site hook rather than a shared helper for
 * the same reason ServesGscErrors' own docblock gives for sitemapUrlSet()
 * — every site builds this list its own way.
 */
final class BingCoverageUrls implements BingCoverageUrlsContract
{
    public function urls(): array
    {
        $path = CrawlFiles::sitemapPath();

        if (! is_file($path)) {
            return [];
        }

        $xml = @simplexml_load_string((string) file_get_contents($path));

        if (! $xml || ! isset($xml->url)) {
            return [];
        }

        $urls = [];

        foreach ($xml->url as $u) {
            $urls[(string) $u->loc] = true;
        }

        return array_keys($urls);
    }
}
