<?php

namespace App\Support\Seo;

/**
 * Bing Webmaster Tools' per-site credential, stored from /admin
 * (POST platforms/bing/credentials) and falling back to the server's own
 * BING_WEBMASTER_API_KEY env value until every tenant's key has been
 * imported and the env var retired — see seo:credentials-import-from-env.
 */
final class BingSettings
{
    public const SETTING_API_KEY = 'seo.bing.api_key';

    public function apiKey(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_API_KEY, config('services.bing.webmaster_api_key'));
    }

    /**
     * Bing's own site identifier — not a credential, so it stays
     * config()-only rather than moving into platform_settings.
     */
    public function siteUrl(): string
    {
        return (string) config('services.bing.site_url');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    /** 'admin' | 'env' | null — see PlatformSettingCredential::source(). */
    public function source(): ?string
    {
        return PlatformSettingCredential::source(self::SETTING_API_KEY, config('services.bing.webmaster_api_key'));
    }
}
