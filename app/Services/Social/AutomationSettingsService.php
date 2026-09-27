<?php

namespace App\Services\Social;

use App\Models\Site;
use SsSystems\Platform\Social\AutomationSettingsService as KitAutomationSettingsService;
use SsSystems\Platform\Social\Contracts\PlatformAvailability;
use SsSystems\Platform\Social\Contracts\PublishedPostStats;
use SsSystems\Platform\Social\Contracts\SettingsStore;

/**
 * The shared automation-settings builder (ss-systems/platform-kit, since
 * 0.13.0 on 2026-09-27) for the GET/PUT /api/admin/v1/social-media
 * automation contract. gs.construction and jpeterson-design used to carry
 * near-identical copies of this class — the kit now holds the one
 * implementation, same "extend it, add the tenant" shape already proven by
 * this app's own Social\AutomationPlanner wrapper.
 *
 * `Site::current()->slug` is resolved HERE, in the constructor, not cached
 * anywhere above it: this class is never bound as a singleton (Laravel's
 * default, unchanged), so every `app(AutomationSettingsService::class)`
 * call constructs a fresh instance and re-reads the CURRENT tenant at that
 * moment — exactly what a multi-tenant site needs, since a stale seed would
 * reshuffle another site's live posting days onto this one's.
 */
class AutomationSettingsService extends KitAutomationSettingsService
{
    public function __construct(
        AutomationPlanner $planner,
        SettingsStore $store,
        PlatformAvailability $availability,
        PublishedPostStats $stats,
    ) {
        parent::__construct($planner, Site::current()->slug, $store, $availability, $stats);
    }
}
