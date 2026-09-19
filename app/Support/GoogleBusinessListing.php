<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;

/**
 * Which Google Business Profile listing this site publishes to, and whether it
 * publishes at all — stored in the database and chosen from the admin, exactly
 * like the OAuth client in App\Support\GoogleOAuthApp.
 *
 * Before this, `account_id`, `location_id` and `enabled` were env-only
 * (GOOGLE_BUSINESS_PROFILE_*). That is unusable here: each site signs in to its
 * own Google account, the ids are only discoverable AFTER the OAuth grant, and
 * nobody can edit a production .env from the admin — so the three checks on the
 * Platforms card ("Publishing turned on", "Business account linked", "Location
 * linked") were permanently red on a site that was otherwise connected.
 *
 * The env values remain a fallback, so an existing deployment that already sets
 * them keeps working until someone picks a listing in the admin.
 */
class GoogleBusinessListing
{
    public const SETTING_ACCOUNT_ID = 'gbp.account_id';

    public const SETTING_LOCATION_ID = 'gbp.location_id';

    public const SETTING_ENABLED = 'gbp.enabled';

    /** Config path the stored values overlay. */
    public const CONFIG_PATH = 'services.google.business_profile';

    /** Overlay the stored listing onto config — once per request, from boot. */
    public static function apply(): void
    {
        if (! static::hasTable()) {
            return;
        }

        $accountId = PlatformSetting::get(self::SETTING_ACCOUNT_ID);
        $locationId = PlatformSetting::get(self::SETTING_LOCATION_ID);
        $enabled = PlatformSetting::get(self::SETTING_ENABLED);

        if ($accountId) {
            config([self::CONFIG_PATH.'.account_id' => $accountId]);
        }

        if ($locationId) {
            config([self::CONFIG_PATH.'.location_id' => $locationId]);
        }

        if ($enabled !== null) {
            config([self::CONFIG_PATH.'.enabled' => $enabled === '1']);
        }
    }

    /**
     * Save the chosen listing. Ids are stored bare ("123"), never as the
     * "accounts/123" / "locations/456" resource names Google returns, because
     * locationBaseUrl() builds those paths itself.
     */
    public static function link(string $accountId, string $locationId): void
    {
        PlatformSetting::put(self::SETTING_ACCOUNT_ID, static::bareId($accountId));
        PlatformSetting::put(self::SETTING_LOCATION_ID, static::bareId($locationId));
    }

    /** Forget the chosen listing; publishing falls back to env, or to nothing. */
    public static function unlink(): void
    {
        PlatformSetting::put(self::SETTING_ACCOUNT_ID, null);
        PlatformSetting::put(self::SETTING_LOCATION_ID, null);
    }

    public static function setEnabled(bool $enabled): void
    {
        PlatformSetting::put(self::SETTING_ENABLED, $enabled ? '1' : '0');
    }

    /** "accounts/123" and "accounts/123/locations/456" both reduce to the last segment. */
    public const SETTING_PLACE_ID = 'gbp.place_id';

    /**
     * The listing's public address on Google Maps, or null when this site has
     * no listing of its own yet.
     *
     * config/social-platforms.php already documents this exact shape as the
     * Google field's placeholder, and every site that has linked a listing can
     * produce it without anyone typing a place id.
     */
    public static function mapsUrl(?Site $site = null): ?string
    {
        $placeId = static::placeId($site);

        return $placeId === null ? null : 'https://www.google.com/maps/place/?q=place_id:'.$placeId;
    }

    /**
     * This site's Google place id.
     *
     * Stored per site when a listing is linked. The env fallback
     * (GOOGLE_BUSINESS_PROFILE_PLACE_ID) is the DEFAULT SITE'S — it predates
     * multi-tenancy — so another tenant must never inherit it: J. Peterson
     * Design reading that value would publish GS Construction's Maps link as
     * her own.
     */
    public static function placeId(?Site $site = null): ?string
    {
        $site ??= Site::current();

        $stored = static::hasTable() ? PlatformSetting::get(self::SETTING_PLACE_ID) : null;

        if ($stored) {
            return $stored;
        }

        if ($site->slug !== (string) config('sites.default', 'gsc')) {
            return null;
        }

        return config(self::CONFIG_PATH.'.place_id') ?: null;
    }

    /** Remember the place id Google gave for the linked listing. */
    public static function rememberPlaceId(?string $placeId): void
    {
        PlatformSetting::put(self::SETTING_PLACE_ID, $placeId ?: null);
    }

    /** Where the Social Media page keeps this site's Google profile link. */
    public const SOCIAL_URL_SETTING = 'socials.url.google';

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

        $url = static::mapsUrl();

        if ($url === null) {
            return false;
        }

        PlatformSetting::put(self::SOCIAL_URL_SETTING, $url);

        return true;
    }

    /**
     * Does this Google listing belong to the site asking about it?
     *
     * One Google account can manage several businesses — Patryk's manages both
     * GS Construction and J. Peterson Design — and the API returns all of them
     * to whichever site holds the grant. The picker on gs.construction's
     * Platforms page therefore listed a client's business by name, which is
     * that client's relationship to disclose, not ours.
     *
     * The listing's own website is the link back to the tenant: GS's points at
     * gs.construction, the studio's at jpeterson-design.com. A listing with no
     * website, or one pointing somewhere else entirely, matches nothing and is
     * only reachable by explicitly asking to see the whole account.
     *
     * @param  array<string, mixed>  $location  a location as Google returns it
     */
    public static function belongsToSite(array $location, ?Site $site = null): bool
    {
        $site ??= Site::current();

        $host = static::host((string) ($location['websiteUri'] ?? ''));

        if ($host === '') {
            return false;
        }

        return in_array($host, static::siteHosts($site), true);
    }

    /** Every host this site answers to, without www. */
    /** @return list<string> */
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

    public static function bareId(string $value): string
    {
        $parts = array_values(array_filter(explode('/', trim($value))));

        return $parts === [] ? '' : (string) end($parts);
    }

    protected static function hasTable(): bool
    {
        if (app()->bound('platform_settings.table')) {
            return true;
        }

        try {
            $exists = Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return false; // mid-install / migrate: env values only
        }

        if ($exists) {
            app()->instance('platform_settings.table', true);
        }

        return $exists;
    }
}
