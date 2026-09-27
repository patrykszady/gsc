<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use App\Services\Social\AutomationPlanner;
use App\Support\Social\KitAutomationSettingsRepository;
use App\Support\Social\KitPlatformAvailability;
use App\Support\Social\KitSocialPublisher;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Social\AutomationTickRunner;

/**
 * One 5-minute tick that replaces the hard-coded, single-tenant Schedule
 * blocks that used to live in routes/console.php. This app's own thin
 * wrapper (2026-09-27) around the kit's shared
 * SsSystems\Platform\Social\AutomationTickRunner, which owns the actual
 * decide/hold/dispatch/catch-up algorithm (moved from gsc's and
 * jpeterson-design's near-identical copies — see the kit class's own
 * docblock, including the approved jpeterson catch-up behaviour change).
 *
 * What stays here, and why: Tenancy::each and the per-tenant, per-tick
 * resolution of AutomationPlanner — "resolved INSIDE the tenant bind... a
 * site's own timezone... governs its clock" — plus this app's own dispatch
 * mechanics (App\Support\Social\KitSocialPublisher shells out to social:post)
 * and its own Log::channel('social') lines, none of which the kit ever sees.
 */
class SocialAutomationTick extends Command
{
    protected $signature = 'social:automation-tick';

    protected $description = "Dispatch every site's due automatic social posts for this 5-minute tick";

    public function handle(MetaSocialService $meta, GoogleBusinessProfileService $gbp): int
    {
        Tenancy::each(function (Site $site) use ($meta, $gbp) {
            // Resolved INSIDE the tenant bind, so a site's own timezone
            // (config/sites/{slug}/social-automation.php via SiteConfig)
            // governs its clock. Resolving one planner up front would judge
            // every site against whichever timezone happened to be live
            // first — slots firing at the wrong local hour, or skipped.
            $planner = app()->make(AutomationPlanner::class);

            $runner = new AutomationTickRunner(
                $planner,
                new KitAutomationSettingsRepository,
                new KitPlatformAvailability($meta, $gbp),
                new KitSocialPublisher,
            );

            $now = Carbon::now($planner->timezone());

            $runner->run($site->slug, $now, ['google_business'], function (string $platform, string $slot, ?Carbon $lastDispatchedAt, Carbon $now) {
                // Never back-to-back: a slot the day after a post is held when
                // the plan itself moved under it (a cadence edit mid-week, or
                // the planner's 2026-09-23 spacing rules replacing the old
                // shuffle). A planned slot is never that close, so this only
                // ever stops a stray — see AutomationPlanner::tooSoonAfter().
                Log::channel('social')->info('Social automation: slot held — the last post was the same or previous day', [
                    'platform' => $platform,
                    'slot' => $slot,
                    'last_dispatched_at' => $lastDispatchedAt?->toIso8601String(),
                ]);
                $this->line("{$platform}: slot {$slot} held — the last post went out ".$lastDispatchedAt?->diffForHumans($now).'.');
            });
        });

        return self::SUCCESS;
    }
}
