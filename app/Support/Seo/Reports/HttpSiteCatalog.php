<?php

namespace App\Support\Seo\Reports;

use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Reports\Contracts\SiteCatalog;

/**
 * The site's own public shape for the kit's `site_catalog` capability.
 * config('app.url') is per-tenant here (App\Support\Tenancy::bind() sets it
 * for every console/queue context, not just HTTP requests), exactly what
 * every original self-crawl command read directly. sitemapUrls() fetches
 * {baseUrl}/sitemap.xml and regexes out every <loc> UNFILTERED — each
 * report re-applies its OWN original extension filter/limit on top of this,
 * per docs/REPORTS-PORTING.md's filter table. A network failure or
 * unparseable response returns [], matching every original command's own
 * try/catch around the sitemap fetch.
 */
final class HttpSiteCatalog implements SiteCatalog
{
    public function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function canonicalHost(): string
    {
        return (string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?: '');
    }

    public function sitemapUrls(): array
    {
        $sitemap = $this->baseUrl().'/sitemap.xml';

        try {
            $body = Http::timeout(15)->get($sitemap)->throw()->body();
        } catch (\Throwable) {
            return [];
        }

        if (! preg_match_all('#<loc>(.*?)</loc>#i', $body, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('trim', $matches[1])));
    }
}
