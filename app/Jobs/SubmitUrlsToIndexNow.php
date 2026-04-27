<?php

namespace App\Jobs;

use App\Services\IndexNowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Submits URLs to IndexNow asynchronously so model saves stay fast.
 * The IndexNowService HTTP call has a 30s timeout that we never want to block on.
 */
class SubmitUrlsToIndexNow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 35;

    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(public array $urls) {}

    public function handle(IndexNowService $indexNow): void
    {
        if (empty($this->urls)) {
            return;
        }
        $indexNow->submitBatch($this->urls);
    }

    public function uniqueId(): string
    {
        sort($this->urls);
        return 'indexnow:' . md5(implode('|', $this->urls));
    }
}
