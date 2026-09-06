<?php

namespace App\Jobs;

use App\Models\Citation;
use App\Models\Site;
use App\Services\Citations\CitationBatchRunner;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The "run all" batch, one directory per job: runs it in automatic mode,
 * then re-dispatches itself with the rest of the list. Each job therefore
 * stays under the media-sync supervisor's timeout, and a crash loses one
 * directory, not the batch.
 */
class RunCitationsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 350;

    /** @param  list<string>  $slugs */
    public function __construct(public array $slugs, public int $done = 0, public ?int $siteId = null)
    {
        $this->siteId = $siteId ?? Site::current()?->id;
        $this->onQueue('media-sync');
    }

    public function handle(CitationBatchRunner $runner): void
    {
        $run = function () use ($runner): void {
            $slug = array_shift($this->slugs);
            if ($slug === null) {
                CitationBatchRunner::progress([], $this->done, null, false);

                return;
            }
            CitationBatchRunner::progress($this->slugs, $this->done, $slug);
            $citation = Citation::query()->where('site_id', $this->siteId)->where('slug', $slug)->first();
            if ($citation && in_array($citation->status, CitationBatchRunner::ELIGIBLE, true)) {
                $result = $runner->runOne($citation);
                Log::info('citations: batch step', $result);
            }
            $this->done++;
            if ($this->slugs === []) {
                CitationBatchRunner::progress([], $this->done, null, false);

                return;
            }
            CitationBatchRunner::progress($this->slugs, $this->done, null);
            self::dispatch($this->slugs, $this->done, $this->siteId)->delay(now()->addSeconds(5));
        };

        $site = $this->siteId ? Site::find($this->siteId) : null;
        $site ? Tenancy::for($site, $run) : $run();
    }
}
