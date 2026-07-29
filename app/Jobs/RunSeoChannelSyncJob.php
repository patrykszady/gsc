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

    /** @param array<string, mixed> $options */
    public function __construct(
        public string $command,
        public array $options = [],
    ) {}

    public function handle(): void
    {
        $exit = Artisan::call($this->command, $this->options);
        if ($exit !== 0) {
            Log::warning('RunSeoChannelSyncJob command exited non-zero', [
                'command' => $this->command,
                'exit' => $exit,
            ]);
        }
    }
}
