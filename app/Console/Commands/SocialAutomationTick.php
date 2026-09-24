<?php

namespace App\Console\Commands;

use App\Models\ImageSocialPost;
use App\Models\Site;
use App\Models\SocialAutomationSetting;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use App\Services\Social\AutomationPlanner;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * One 5-minute tick that replaces the hard-coded, single-tenant Schedule
 * blocks that used to live in routes/console.php. For every active site
 * (Tenancy::each) and every platform that site has enabled automatic
 * posting for, dispatches the post exactly once per weekly slot
 * (App\Services\Social\AutomationPlanner) plus the Google Business
 * "catch-up" safety net.
 *
 * Double-post guard: the due slot is recorded on the setting row (and
 * saved) BEFORE social:post is invoked. A crash between those two lines can
 * only cost this one slot — it can never cause a later run to fire it
 * again, because last_dispatched_slot already matches.
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

            $this->tickSite($site, $planner, $meta, $gbp, Carbon::now($planner->timezone()));
        });

        return self::SUCCESS;
    }

    protected function tickSite(Site $site, AutomationPlanner $planner, MetaSocialService $meta, GoogleBusinessProfileService $gbp, Carbon $now): void
    {
        // Fetched once per site so instagram/facebook can hand each other
        // their sibling's cadence below (AutomationPlanner's day-collision
        // guarantee needs both), instead of one query per platform.
        $settingsByPlatform = SocialAutomationSetting::query()->get()->keyBy('platform');

        foreach (SocialAutomationSetting::PLATFORMS as $platform) {
            $setting = $settingsByPlatform->get($platform);

            if (! $setting || ! $setting->enabled) {
                continue;
            }

            if (! $this->isConfigured($platform, $meta, $gbp)) {
                continue;
            }

            $defaults = SocialAutomationSetting::defaultsFor($platform);
            $cadence = $setting->cadence ?? $defaults['cadence'];
            $options = $setting->options ?? $defaults['options'];

            $slot = $planner->due($site->slug, $platform, $cadence, $now, $this->metaSiblingCadence($platform, $settingsByPlatform));
            if ($slot !== null && $setting->last_dispatched_slot !== $slot) {
                // Never back-to-back: a slot the day after a post is held when
                // the plan itself moved under it (a cadence edit mid-week, or
                // the planner's 2026-09-23 spacing rules replacing the old
                // shuffle). A planned slot is never that close, so this only
                // ever stops a stray — see AutomationPlanner::tooSoonAfter().
                if ($planner->tooSoonAfter($cadence, $setting->last_dispatched_at, $now)) {
                    // Held, not moved: this week has one post fewer. Only a plan
                    // that changed under the week can do this, and moving the
                    // slot could land Instagram and Facebook on the same day —
                    // one missing post is the smaller harm. Logged, so it shows.
                    Log::channel('social')->info('Social automation: slot held — the last post was the same or previous day', [
                        'platform' => $platform,
                        'slot' => $slot,
                        'last_dispatched_at' => $setting->last_dispatched_at?->toIso8601String(),
                    ]);
                    $this->line("{$platform}: slot {$slot} held — the last post went out ".$setting->last_dispatched_at?->diffForHumans($now).'.');
                } else {
                    $this->dispatchSlot($site, $setting, $platform, $options, $slot);
                }
            }

            // Google Business: post anyway once nothing has gone out for longer
            // than the plan ever leaves between posts — worked out from the
            // rhythm, no setting (2026-09-23; the old catch_up_after_days
            // option is ignored).
            if ($platform === 'google_business') {
                $this->maybeCatchUp($site, $setting, $planner->catchUpAfterDays($cadence), $now);
            }
        }
    }

    /**
     * Instagram and Facebook must never land on the same day (see
     * AutomationPlanner's class docblock) — this hands the planner the
     * OTHER meta platform's cadence so it can offset its day draw from
     * their shared weekly shuffle. Null for any other platform.
     *
     * @param  Collection<string, SocialAutomationSetting>  $settingsByPlatform
     */
    protected function metaSiblingCadence(string $platform, Collection $settingsByPlatform): ?array
    {
        $sibling = match ($platform) {
            'instagram' => 'facebook',
            'facebook' => 'instagram',
            default => null,
        };

        if ($sibling === null) {
            return null;
        }

        $setting = $settingsByPlatform->get($sibling);

        return $setting->cadence ?? SocialAutomationSetting::defaultsFor($sibling)['cadence'];
    }

    protected function isConfigured(string $platform, MetaSocialService $meta, GoogleBusinessProfileService $gbp): bool
    {
        return match ($platform) {
            'instagram' => $meta->isInstagramConfigured(),
            'facebook' => $meta->isFacebookConfigured(),
            'google_business' => $gbp->isConfigured(),
            default => false,
        };
    }

    protected function dispatchSlot(Site $site, SocialAutomationSetting $setting, string $platform, array $options, string $slot): void
    {
        $setting->forceFill([
            'last_dispatched_slot' => $slot,
            'last_dispatched_at' => Carbon::now(),
        ])->save();

        $params = ['--platform' => $platform, '--yes' => true, '--random-delay' => 0];

        if ($platform === 'instagram' && ! empty($options['location_tag'])) {
            // Puppeteer transport is what lets the post carry a location tag
            // (the Graph API cannot add one without a Meta App Review).
            $params['--via'] = 'puppeteer';
        }

        if ($platform === 'google_business') {
            // Queued so AI caption generation runs on the social-media
            // worker rather than blocking this tick.
            $params['--queue'] = true;
            if (! empty($options['themed'])) {
                $params['--themed'] = true;
            }
        }

        Artisan::call('social:post', $params);

        Log::channel('social')->info('Social automation: dispatched scheduled post', [
            'site' => $site->slug,
            'platform' => $platform,
            'slot' => $slot,
        ]);
    }

    /**
     * Google Business safety-net: if cadence has slipped (no published post
     * in catch_up_after_days+), queue one catch-up post. Checked once a day
     * at 10:20 — the same clock minute the old routes/console.php
     * safety-net used — so it runs after the morning's regular slot (if
     * any) has already had its chance to fire.
     */
    protected function maybeCatchUp(Site $site, SocialAutomationSetting $setting, int $afterDays, Carbon $now): void
    {
        if ($now->format('H:i') !== '10:20') {
            return;
        }

        $catchUpId = $now->toDateString().' catch-up';
        if ($setting->last_dispatched_slot === $catchUpId) {
            return;
        }

        $lastPublishedAt = ImageSocialPost::query()
            ->where('platform', 'google_business')
            ->where('status', 'published')
            ->max('published_at');

        // Nothing published yet means automation was only just switched on:
        // the plan's first slot is coming, and a catch-up now would jump it.
        $isStale = $lastPublishedAt !== null
            && Carbon::parse($lastPublishedAt)->lessThan($now->copy()->subDays($afterDays));

        if (! $isStale) {
            return;
        }

        $postedToday = ImageSocialPost::query()
            ->where('platform', 'google_business')
            ->whereDate('created_at', $now->toDateString())
            ->exists();

        if ($postedToday) {
            return;
        }

        $setting->forceFill([
            'last_dispatched_slot' => $catchUpId,
            'last_dispatched_at' => Carbon::now(),
        ])->save();

        Artisan::call('social:post', ['--platform' => 'google_business', '--queue' => true]);

        Log::channel('social')->info('Social automation: catch-up dispatched', [
            'site' => $site->slug,
            'platform' => 'google_business',
            'slot' => $catchUpId,
        ]);
    }
}
