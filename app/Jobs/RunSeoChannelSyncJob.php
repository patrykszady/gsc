<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Runs a metrics-sync artisan command (seo:gsc-sync, seo:bing-sync) on the
 * queue with a realistic timeout. Artisan::queue()'s generic QueuedCommand
 * inherits the worker's 60s timeout and 5 tries — a full paginated GSC pull
 * takes minutes, so it would be killed and retried into MaxAttemptsExceeded
 * (the same failure RunGscInspectBulkJob exists to avoid).
 */
class RunSeoChannelSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $options
     * @param  int|null  $siteId  tenant to run as; defaults to whoever dispatched
     */
    public function __construct(
        public string $command,
        public array $options = [],
        public ?int $siteId = null,
    ) {
        // Capture the dispatching tenant. The queue has no request, so
        // Site::current() there falls back to the DEFAULT site — a job queued
        // from J. Peterson Design's admin would otherwise generate content as
        // gs.construction.
        $this->siteId ??= \App\Models\Site::current()->id;
    }

    public function handle(): void
    {
        $site = \App\Models\Site::find($this->siteId);

        $run = function (): void {
            $exit = Artisan::call($this->command, $this->options);
            if ($exit !== 0) {
                Log::warning('RunSeoChannelSyncJob command exited non-zero', [
                    'command' => $this->command,
                    'exit' => $exit,
                    'site_id' => $this->siteId,
                ]);
            }
        };

        $site ? \App\Support\Tenancy::for($site, $run) : $run();
    }
}
