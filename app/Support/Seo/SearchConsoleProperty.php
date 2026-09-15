<?php

namespace App\Support\Seo;

use App\Models\Site;
use App\Support\SiteConfig;

/**
 * Which Search Console property a site is.
 *
 * The default site names its property in the environment
 * (GSC_SEARCH_CONSOLE_SITE_URL, "sc-domain:gs.construction"). Every other
 * site is its own domain property unless its config/sites/{slug}/seo.php
 * says otherwise — never the default site's, or one tenant's admin would
 * read and write another business's Search Console.
 */
final class SearchConsoleProperty
{
    public static function url(?Site $site = null): string
    {
        $site ??= Site::current();

        if ($site->slug === (string) config('sites.default', 'gsc') || SiteConfig::owns('seo.search_console.site_url', $site)) {
            $configured = trim((string) SiteConfig::forSite($site, 'seo.search_console.site_url', ''));
            if ($configured !== '') {
                return $configured;
            }
        }

        return 'sc-domain:'.$site->primary_host;
    }

    /** The site's public origin, for building the URLs it submits and inspects. */
    public static function baseUrl(?Site $site = null): string
    {
        $property = self::url($site);

        return str_starts_with($property, 'sc-domain:')
            ? 'https://'.substr($property, strlen('sc-domain:'))
            : rtrim($property, '/');
    }
}
