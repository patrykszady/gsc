<?php

namespace App\Support\Seo\Inspection;

use App\Support\Seo\CrawlFiles;
use App\Support\Seo\SearchConsoleProperty;
use SsSystems\Platform\Seo\Inspection\Contracts\SitemapSource;

/**
 * The kit's SitemapSource: urls() is the original command's own
 * loadSitemapUrls() (simplexml over the generated sitemap.xml, <loc> in
 * document order, [] on a missing/unparseable file) pointed at
 * CrawlFiles::sitemapPath() or the --sitemap= override, exactly as
 * seo:gsc-inspect-bulk read it before.
 *
 * Every URL is then rehosted onto the Search Console PROPERTY's own
 * scheme+host before it is handed back. A dev or staging checkout generates
 * its sitemap under that box's own APP_URL (e.g. http://127.0.0.1:8003/...),
 * but the URL Inspection API 403s any URL outside the verified property —
 * sweeping anywhere but production used to burn the day's allowance on
 * refusals and store nothing. baseUrl() (used to build the 'tracked' pool's
 * full URLs) returns that same property base, falling back to
 * rtrim(config('app.url'), '/') only when the property itself cannot be
 * parsed into a scheme+host — the original command's own fallback, and
 * exactly what the kit's SitemapSource contract docblock names.
 */
final class FileSitemapSource implements SitemapSource
{
    public function urls(?string $override = null): array
    {
        $path = $override !== null && $override !== '' ? $override : CrawlFiles::sitemapPath();

        if (! is_file($path)) {
            return [];
        }

        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml) {
            return [];
        }

        $base = $this->propertyBase();

        $urls = [];
        foreach ($xml->url ?? [] as $u) {
            $loc = (string) $u->loc;
            if ($loc === '') {
                continue;
            }

            $urls[] = $base !== null ? $this->rehost($loc, $base) : $loc;
        }

        return $urls;
    }

    public function baseUrl(): string
    {
        return $this->propertyBase() ?? rtrim((string) config('app.url'), '/');
    }

    /**
     * SearchConsoleProperty::url() for the current tenant, reduced to a bare
     * scheme+host: 'sc-domain:gs.construction' -> 'https://gs.construction',
     * a URL-prefix property -> its own scheme+host (its path, if it has one,
     * is dropped — a property is a host, not a page). Null when the property
     * string cannot be parsed either way.
     */
    private function propertyBase(): ?string
    {
        $property = SearchConsoleProperty::url();

        if (str_starts_with($property, 'sc-domain:')) {
            $host = substr($property, strlen('sc-domain:'));

            return $host !== '' ? 'https://'.$host : null;
        }

        $scheme = parse_url($property, PHP_URL_SCHEME);
        $host = parse_url($property, PHP_URL_HOST);

        return $scheme && $host ? $scheme.'://'.$host : null;
    }

    /** Swap $loc's scheme+host for $base's, keeping its path/query/fragment. Unparsable $loc is returned untouched. */
    private function rehost(string $loc, string $base): string
    {
        $parts = parse_url($loc);
        if ($parts === false) {
            return $loc;
        }

        $rest = ($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        return $base.$rest;
    }
}
