<?php

namespace App\Support\Social;

use App\Models\ImageSocialPost;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Social\Contracts\SocialPublisher;

/**
 * Shells out to this app's own social:post command — exactly what
 * App\Console\Commands\SocialAutomationTick did directly before the tick's
 * control flow moved into the kit. Resolved fresh INSIDE Tenancy::each's
 * per-site closure so Site::current() (used only for the log line here;
 * ImageSocialPost's own BelongsToSite scope handles the query) is the
 * dispatching tenant.
 */
class KitSocialPublisher implements SocialPublisher
{
    public function publish(string $platform, string $slot, array $options = []): void
    {
        // The settings row's own last_dispatched_slot already tells a
        // catch-up id apart from a regular one by this exact suffix (see
        // AutomationTickRunner::maybeCatchUp()) — reused here because gsc's
        // original catch-up call built a DELIBERATELY different (narrower)
        // Artisan param set than a regular dispatch: no --yes/--random-delay,
        // and never --via/--themed regardless of the platform's saved
        // options (its maybeCatchUp() never read $setting->options either).
        $isCatchUp = str_ends_with($slot, ' catch-up');

        $params = $isCatchUp
            ? ['--platform' => $platform, '--queue' => true]
            : ['--platform' => $platform, '--yes' => true, '--random-delay' => 0];

        if (! $isCatchUp) {
            if ($platform === 'instagram' && ! empty($options['location_tag'])) {
                // Puppeteer transport is what lets the post carry a location
                // tag (the Graph API cannot add one without a Meta App Review).
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
        }

        Artisan::call('social:post', $params);

        Log::channel('social')->info('Social automation: dispatched scheduled post', [
            'site' => Site::current()?->slug,
            'platform' => $platform,
            'slot' => $slot,
        ]);
    }

    public function lastPublishedAt(string $platform): ?Carbon
    {
        $max = ImageSocialPost::query()
            ->where('platform', $platform)
            ->where('status', 'published')
            ->max('published_at');

        return $max !== null ? Carbon::parse($max) : null;
    }
}
