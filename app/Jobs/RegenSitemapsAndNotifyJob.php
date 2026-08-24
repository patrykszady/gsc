<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Debounced recrawl nudge, dispatched (delayed) by RecrawlNudger whenever
 * public content changes: regenerates both sitemaps so honest lastmod values
 * are visible to Google within minutes instead of the nightly cycle, then
 * pings the WebSub hub so subscribed crawlers fetch the updates feed
 * immediately.
 */
class RegenSitemapsAndNotifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(): void
    {
        // Release the debounce gate FIRST: changes landing while we regenerate
        // queue a fresh cycle instead of being silently absorbed half-done.
        Cache::forget(\App\Support\SEO\RecrawlNudger::GATE_KEY);

        Artisan::call('sitemap:generate');
        Artisan::call('seo:image-sitemap-build');

        // Re-submit both sitemaps to Google Search Console so the regenerated
        // lastmod values (and any new URLs, e.g. auto-published landing pages)
        // get a re-read request instead of waiting for Google's own cadence.
        // Idempotent and quota-cheap; exits cleanly pre-auth.
        Artisan::call('seo:gsc-submit-sitemaps');

        // WebSub publish ping — tells the hub the updates feed changed;
        // subscribed crawlers (Google among them) re-fetch it within minutes.
        try {
            Http::asForm()->timeout(15)->post('https://pubsubhubbub.appspot.com/', [
                'hub.mode' => 'publish',
                'hub.url' => url('/feed/updates.atom'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('WebSub publish ping failed', ['error' => $e->getMessage()]);
        }
    }
}
