<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Site;
use SsSystems\Platform\Google\BusinessProfile\Contracts\ListingStore;

/**
 * gs.construction's own rules about its Google Business Profile listing —
 * what is left of this class since kit 0.14.0. The listing itself (which
 * account and location, its place id and public links, the env fallback for
 * the default site) is the kit's `ListingStore`, bound in
 * AppServiceProvider over the same `gbp.*` platform_settings keys this class
 * used to write; read it with `app(ListingStore::class)`.
 *
 * What stays is the site's own business on top of it:
 *
 * - `siteHosts()` — which listings belong to this tenant (the admin's
 *   listings card hides another client's business; the kit's
 *   `ServesGbpPlatform::gbpSiteHosts()` hook reads it);
 * - `adoptAsSocialUrl()` — the Social Media page's Google link, filled from
 *   a newly linked listing (`gbpListingSaved()`);
 * - the retired "publishing" switch (`gbp.enabled`), still reported and
 *   still accepted from an older admin build, read by nothing else.
 */
class GoogleBusinessListing
{
    /** The retired publishing switch (2026-09-23): stored and reported only. */
    public const SETTING_ENABLED = 'gbp.enabled';

    /** Where the Social Media page keeps this site's Google profile link. */
    public const SOCIAL_URL_SETTING = 'socials.url.google';

    public static function setEnabled(bool $enabled): void
    {
        PlatformSetting::put(self::SETTING_ENABLED, $enabled ? '1' : '0');
    }

    /** The retired switch as the status block has always reported it: stored, else the env value. */
    public static function publishingEnabled(): bool
    {
        $stored = PlatformSetting::get(self::SETTING_ENABLED);

        return $stored !== null
            ? $stored === '1'
            : (bool) config('services.google.business_profile.enabled', false);
    }

    /**
     * Fill in the site's Google link from the listing it just chose.
     *
     * Linking a listing is the moment we learn a site's place id, and the
     * public Maps address follows from it — so nobody should have to find and
     * paste that URL by hand. An address already on file wins: it may be the
     * short link the owner hands out, and this is not the place to overrule a
     * person's own choice.
     *
     * @return bool whether a link was written
     */
    public static function adoptAsSocialUrl(): bool
    {
        $existing = trim((string) (PlatformSetting::get(self::SOCIAL_URL_SETTING)
            ?: (SiteConfig::owns('socials.google.url') ? config('socials.google.url') : '')));

        if ($existing !== '') {
            return false;
        }

        $url = app(ListingStore::class)->mapsUrl();

        if ($url === null) {
            return false;
        }

        PlatformSetting::put(self::SOCIAL_URL_SETTING, $url);

        return true;
    }

    /**
     * Every host this site answers to, without www — the hosts a Google
     * listing's own website must point at to be offered to this site.
     *
     * One Google account can manage several businesses — Patryk's manages both
     * GS Construction and J. Peterson Design — and the API returns all of them
     * to whichever site holds the grant. The picker on gs.construction's
     * Platforms page therefore listed a client's business by name, which is
     * that client's relationship to disclose, not ours.
     *
     * @return list<string>
     */
    public static function siteHosts(?Site $site = null): array
    {
        $site ??= Site::current();

        $hosts = array_merge((array) $site->hosts, [$site->primary_host]);

        return array_values(array_unique(array_filter(array_map(
            fn ($host) => static::host((string) $host),
            $hosts,
        ))));
    }

    /** A bare, comparable host: no scheme, no www, no trailing slash, lowercase. */
    protected static function host(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $host = str_contains($value, '//') ? (string) parse_url($value, PHP_URL_HOST) : $value;
        $host = strtolower(trim($host, '/'));

        return (string) preg_replace('/^www\./', '', $host);
    }
}
