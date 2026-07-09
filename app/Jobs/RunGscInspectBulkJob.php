<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the full-sitemap URL Inspection sweep from the queue.
 *
 * Artisan::queue() wrapped the command in Laravel's generic QueuedCommand,
 * which inherits the worker's 60s timeout (see horizon supervisor-1) — a
 * ~15-minute sweep was killed and retried 5 times, ending in
 * "seo:gsc-inspect-bulk has been attempted too many times". A job-level
 * timeout overrides the worker default, and one attempt is correct for a
 * sweep that appends to shared state.
 */
class RunGscInspectBulkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Full sweep is ~2s per URL across the whole sitemap. */
    public int $timeout = 3600;

    /** Never re-run a half-finished sweep automatically. */
    public int $tries = 1;

    public function handle(): void
    {
        Artisan::call('seo:gsc-inspect-bulk', [
            '--limit' => 0,
            '--markdown' => true,
        ]);
    }
}
