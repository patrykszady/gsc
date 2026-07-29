<?php

namespace App\Support\SEO;

use Illuminate\Support\Facades\Cache;

/**
 * Debounce gate for content-change recrawl nudges. Model observers call
 * nudge() on every save/delete of public content; the first call in a
 * 10-minute window schedules one delayed RegenSitemapsAndNotifyJob, so a
 * batch of 67 town updates produces one sitemap regeneration, not 67.
 */
class RecrawlNudger
{
    public const GATE_KEY = 'seo:recrawl-nudge:pending';

    /** Delay before the regen runs — batches rapid successive edits. */
    private const DEBOUNCE_SECONDS = 600;

    public static function nudge(): void
    {
        if (! Cache::add(self::GATE_KEY, 1, self::DEBOUNCE_SECONDS + 120)) {
            return; // a regen is already scheduled for this window
        }

        \App\Jobs\RegenSitemapsAndNotifyJob::dispatch()
            ->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
    }
}
