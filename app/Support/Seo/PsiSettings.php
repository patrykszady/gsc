<?php

namespace App\Support\Seo;

/**
 * PageSpeed Insights' per-site API key, stored from /admin
 * (POST platforms/pagespeed/credentials) and falling back to the server's
 * own GOOGLE_PAGESPEED_API_KEY env value until imported — see
 * seo:credentials-import-from-env.
 *
 * Deliberately no isConfigured() hard gate: PageSpeedInsightsService has
 * never needed a key to run (Google's shared, lower-quota limit) and must
 * keep working keyless. usingOwnKey() is a soft quality signal only — never
 * treated as connected/disconnected.
 */
final class PsiSettings
{
    public const SETTING_API_KEY = 'seo.pagespeed.api_key';

    public function apiKey(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_API_KEY, config('services.google.pagespeed.api_key'));
    }

    public function usingOwnKey(): bool
    {
        return filled($this->apiKey());
    }

    /** 'admin' | 'env' | null — see PlatformSettingCredential::source(). */
    public function source(): ?string
    {
        return PlatformSettingCredential::source(self::SETTING_API_KEY, config('services.google.pagespeed.api_key'));
    }
}
