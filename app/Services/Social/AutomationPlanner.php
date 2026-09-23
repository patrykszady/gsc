<?php

namespace App\Services\Social;

use SsSystems\Platform\Social\AutomationPlanner as KitAutomationPlanner;

/**
 * The shared posting planner (ss-systems/platform-kit, since 0.9.0 on
 * 2026-09-23) in this site's timezone. The planning itself — which days,
 * spread how, and Instagram and Facebook never on the same day — is the
 * kit's: gs.construction and jpeterson-design used to carry identical copies
 * of it, and that is exactly the kind of code the kit exists to hold once.
 */
class AutomationPlanner extends KitAutomationPlanner
{
    public function __construct(?string $timezone = null)
    {
        parent::__construct($timezone ?? (string) config('social-automation.timezone', 'America/Chicago'));
    }
}
