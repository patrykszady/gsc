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
 * Runs `seo:bing-coverage` from the queue for the hub's Bing card's Refresh
 * button — same shape as RunGscInspectBulkJob: a default 300-URL run
 * sleeps 250ms between each GetUrlInfo call (~75s minimum), longer than a
 * queue worker's default 60s timeout, so this carries its own; and the
 * site id is captured at dispatch time because the queue has no request to
 * read Site::current() from.
 */
class RunBingCoverageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 600;

    /** Never re-run a half-finished sync automatically. */
    public int $tries = 1;

    /** @param  int|null  $siteId  tenant to run as; defaults to whoever dispatched */
    public function __construct(public ?int $siteId = null)
    {
        $this->siteId ??= Site::current()->id;
    }

    public function handle(): void
    {
        $run = fn () => Artisan::call('seo:bing-coverage');
        $site = Site::find($this->siteId);

        $site ? Tenancy::for($site, $run) : $run();
    }
}
