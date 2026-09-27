<?php

namespace App\Services\Social;

use App\Models\SocialAutomationSetting;
use SsSystems\Platform\Social\Contracts\SettingsStore;

/**
 * The kit's SettingsStore contract, wrapping this site's own
 * App\Models\SocialAutomationSetting — including its `Concerns\BelongsToSite`
 * global scope, so `find()`/`save()` only ever see the CURRENT tenant's row
 * without anything here naming a site_id. Replaces the direct
 * `SocialAutomationSetting::` static/query calls the app-level
 * AutomationSettingsService made before it became a thin subclass of the
 * kit's (2026-09-27).
 */
class SocialAutomationSettingsStore implements SettingsStore
{
    public function platforms(): array
    {
        return SocialAutomationSetting::PLATFORMS;
    }

    public function label(string $platform): string
    {
        return SocialAutomationSetting::LABELS[$platform] ?? ucfirst($platform);
    }

    public function defaultsFor(string $platform): array
    {
        return SocialAutomationSetting::defaultsFor($platform);
    }

    public function find(string $platform): ?array
    {
        $setting = SocialAutomationSetting::where('platform', $platform)->first();

        if ($setting === null) {
            return null;
        }

        return [
            'enabled' => (bool) $setting->enabled,
            'cadence' => $setting->cadence,
            'options' => $setting->options,
            'updated_at' => $setting->updated_at,
        ];
    }

    public function save(string $platform, array $data): void
    {
        SocialAutomationSetting::updateOrCreate(
            ['platform' => $platform],
            ['enabled' => $data['enabled'], 'cadence' => $data['cadence'], 'options' => $data['options']],
        );
    }
}
