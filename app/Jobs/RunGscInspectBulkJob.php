<?php

namespace App\Jobs;

use App\Models\Site;
use App\Support\Tenancy;
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

    /** @param  int|null  $siteId  tenant to run as; defaults to whoever dispatched */
    public function __construct(public ?int $siteId = null)
    {
        // The queue has no request, so Site::current() there is the DEFAULT
        // site: a refresh queued from another tenant's admin would sweep
        // gs.construction's sitemap against gs.construction's property.
        $this->siteId ??= Site::current()->id;
    }

    public function handle(): void
    {
        $run = fn () => Artisan::call('seo:gsc-inspect-bulk', ['--limit' => 0, '--markdown' => true]);
        $site = Site::find($this->siteId);

        $site ? Tenancy::for($site, $run) : $run();
    }
}
