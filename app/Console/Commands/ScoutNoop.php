<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The production→local "fetch" sequence is shared with hive2025:
 *
 *   composer storage:pull
 *   php artisan db:pull-production
 *   php artisan scout:sync-index-settings
 *   php artisan scout:reindex --full
 *
 * hive2025 has a Scout search index; this app does not. Rather than have the
 * last two lines error here, they exist and do nothing, so one sequence
 * works in every repo. (`composer prod:pull` runs the same sync as one step.)
 */
class ScoutNoop extends Command
{
    protected $signature = 'scout:reindex {--full : Accepted for parity with hive2025; ignored}';

    protected $description = 'No-op: this app has no search index (kept so the shared fetch sequence works)';

    public function handle(): int
    {
        $this->line('No search index in this app — nothing to reindex.');

        return self::SUCCESS;
    }
}
