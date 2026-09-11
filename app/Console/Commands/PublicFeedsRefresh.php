<?php

namespace App\Console\Commands;

use App\Jobs\RefreshPublicFeedsJob;
use Illuminate\Console\Command;

/**
 * Regenerate every file a crawler reads — the sitemaps and both llms
 * files — the same way a content change does (RefreshPublicFeedsJob),
 * cache busted first. The deploy script runs this once per release, so
 * the one place that decides what "the public feeds" are is the job.
 */
class PublicFeedsRefresh extends Command
{
    protected $signature = 'public-feeds:refresh';

    protected $description = 'Regenerate the sitemaps and llms feeds (busting the cached llms render first)';

    public function handle(): int
    {
        app(RefreshPublicFeedsJob::class)->handle();

        $this->info('Public feeds regenerated: '.implode(', ', array_keys(RefreshPublicFeedsJob::COMMANDS)).'.');

        return self::SUCCESS;
    }
}
