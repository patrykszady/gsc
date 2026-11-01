<?php

namespace App\Support\Seo;

use SsSystems\Platform\Seo\PageSpeed\Contracts\PageSpeedCredentials;

/**
 * PageSpeed Insights' per-site API key, stored from /admin
 * (POST platforms/pagespeed/credentials) and falling back to the server's
 * own GOOGLE_PAGESPEED_API_KEY env value until imported — see
 * seo:credentials-import-from-env.
 *
 * App\Services\PageSpeedInsightsService (the admin's own on-demand PSI
 * check) still runs keyless on purpose — Google's shared, lower-quota limit
 * — so usingOwnKey() stays a soft quality signal there, never a
 * connected/disconnected boolean. isConfigured() below is the kit's OWN hard
 * gate (SsSystems\Platform\Seo\PageSpeed\Contracts\PageSpeedCredentials): the
 * kit's seo:psi-sync runs across every tenant at once, which the shared
 * keyless quota is not sized for, so it stops cleanly until this site has its
 * own key (2026-11-01).
 */
final class PsiSettings implements PageSpeedCredentials
{
    public const SETTING_API_KEY = 'seo.pagespeed.api_key';

    public function apiKey(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_API_KEY, config('services.google.pagespeed.api_key'));
    }

    /** The kit's seo:psi-sync's hard gate — see this class's own docblock. */
    public function isConfigured(): bool
    {
        return filled($this->apiKey());
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
