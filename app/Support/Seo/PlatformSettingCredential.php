<?php

namespace App\Support\Seo;

use App\Models\PlatformSetting;

/**
 * The one place every SEO-source Settings class (Bing, Clarity, PageSpeed,
 * DataForSEO) reads a stored credential through, so "every command reads a
 * key one way" is true by construction instead of by convention. Not a kit
 * interface — the kit's own SearchConsoleWriter docblock argues against one
 * ("how a row is stored is exactly the part that cannot be shared"), and
 * YelpBusinessService/GoogleOAuthApp already prove the plain
 * PlatformSetting::get(key, config(...)) fallback works without one.
 *
 * A site's own /admin never talks to this class directly — it goes through
 * a Settings class's SETTING_* constants, exactly like YelpBusinessService's
 * SETTING_EMAIL/SETTING_PASSWORD already do.
 */
final class PlatformSettingCredential
{
    /** The stored value, or $default (typically a config()/env() read) when nothing is stored. */
    public static function get(string $key, ?string $default = null): ?string
    {
        return PlatformSetting::get($key, $default);
    }

    /**
     * True only when EVERY key given has a value actually stored in
     * platform_settings — an env-only fallback does not count. Used to
     * decide whether a multi-field source (Clarity's project id + token,
     * DataForSEO's login + password) counts as admin-configured as a
     * whole, the same way GoogleOAuthApp::source() checks both of its
     * stored fields before calling itself 'admin'.
     */
    public static function configured(string ...$keys): bool
    {
        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (! filled(PlatformSetting::get($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 'admin' when the key is stored (encrypted, per site) in
     * platform_settings, 'env' when only $configDefault (the server's
     * config()/env() fallback) provides a value, null when neither does.
     * Mirrors GoogleOAuthApp::source() exactly, generalised to one key.
     */
    public static function source(string $key, mixed $configDefault): ?string
    {
        if (filled(PlatformSetting::get($key))) {
            return 'admin';
        }

        return filled($configDefault) ? 'env' : null;
    }
}
