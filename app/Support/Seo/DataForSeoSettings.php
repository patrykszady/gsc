<?php

namespace App\Support\Seo;

/**
 * DataForSEO's per-site credential (a login + password) — but, unlike
 * Bing/Clarity/PageSpeed, this site never collects it from its OWN /admin.
 * DataForSEO is ss.systems' single metered account, covered by every
 * tenant's plan (Patryk's call, 2026-09-22): ss.systems provisions it into
 * this site's encrypted platform_settings through
 * POST platforms/dataforseo/credentials the moment the tenant is switched
 * on, using the exact same admin-API shape Bing/Clarity/PageSpeed use so
 * this site never needs to know where the key came from. The
 * DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD env values stay as the transition
 * fallback — see seo:credentials-import-from-env.
 */
final class DataForSeoSettings
{
    public const SETTING_LOGIN = 'seo.dataforseo.login';

    public const SETTING_PASSWORD = 'seo.dataforseo.password';

    public function login(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_LOGIN, config('services.dataforseo.login'));
    }

    public function password(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_PASSWORD, config('services.dataforseo.password'));
    }

    public function isConfigured(): bool
    {
        return filled($this->login()) && filled($this->password());
    }

    /**
     * 'platform' | 'env' | null. A value stored in platform_settings here
     * can only be the key ss.systems provisioned — this site has no admin
     * field of its own for it — so it is reported as 'platform' rather
     * than the generic 'admin' PlatformSettingCredential::configured()
     * would otherwise imply, which would misname who set it.
     */
    public function source(): ?string
    {
        if (PlatformSettingCredential::configured(self::SETTING_LOGIN, self::SETTING_PASSWORD)) {
            return 'platform';
        }

        $envConfigured = filled(config('services.dataforseo.login')) && filled(config('services.dataforseo.password'));

        return $envConfigured ? 'env' : null;
    }
}
