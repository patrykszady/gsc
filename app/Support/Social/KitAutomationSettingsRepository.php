<?php

namespace App\Support\Social;

use App\Models\SocialAutomationSetting;
use Illuminate\Support\Carbon;
use SsSystems\Platform\Social\Contracts\AutomationSettingsRepository;

/**
 * Wraps App\Models\SocialAutomationSetting for the kit's
 * Social\AutomationTickRunner — resolved fresh INSIDE App\Support\Tenancy's
 * per-site closure (see App\Console\Commands\SocialAutomationTick), so every
 * query below rides the ambient Site::current() scope BelongsToSite already
 * applies to this model. See the kit contract's own docblock for the row
 * shape and why a missing platform reads as enabled=false.
 */
class KitAutomationSettingsRepository implements AutomationSettingsRepository
{
    public function all(): array
    {
        $rows = SocialAutomationSetting::query()->get()->keyBy('platform');

        $out = [];
        foreach (SocialAutomationSetting::PLATFORMS as $platform) {
            $setting = $rows->get($platform);
            $defaults = SocialAutomationSetting::defaultsFor($platform);

            $out[$platform] = [
                'enabled' => (bool) ($setting->enabled ?? false),
                'cadence' => $setting->cadence ?? $defaults['cadence'],
                'options' => $setting->options ?? $defaults['options'],
                'last_dispatched_slot' => $setting->last_dispatched_slot ?? null,
                'last_dispatched_at' => $setting->last_dispatched_at ?? null,
            ];
        }

        return $out;
    }

    public function markDispatched(string $platform, string $slot, Carbon $at): void
    {
        SocialAutomationSetting::where('platform', $platform)->firstOrFail()->forceFill([
            'last_dispatched_slot' => $slot,
            'last_dispatched_at' => $at,
        ])->save();
    }
}
