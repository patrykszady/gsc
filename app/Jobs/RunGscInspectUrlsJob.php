<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * Inspect an explicit list of URLs (a Search Console Page-indexing export,
 * the URLs Googlebot 404s on) through the URL Inspection API and persist
 * them as coverage rows tagged with their source — the same command the
 * nightly sitemap sweep runs, pointed at these URLs instead.
 *
 * @see RunGscInspectBulkJob for the sweep's timeout reasoning.
 */
class RunGscInspectUrlsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /** @param  list<string>  $urls */
    public function __construct(public array $urls, public string $source = 'console', public ?string $reason = null) {}

    public function handle(): void
    {
        if ($this->urls === []) {
            return;
        }

        Artisan::call('seo:gsc-inspect-bulk', array_filter([
            '--urls' => $this->urls,
            '--source' => $this->source,
            '--reason' => $this->reason,
            '--limit' => 0,
        ], fn ($v) => $v !== null));
    }
}
