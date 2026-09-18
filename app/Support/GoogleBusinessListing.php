<?php

namespace App\Support;

use App\Models\PlatformSetting;
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
