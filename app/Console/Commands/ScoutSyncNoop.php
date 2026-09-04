<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** See ScoutNoop. */
class ScoutSyncNoop extends Command
{
    protected $signature = 'scout:sync-index-settings';

    protected $description = 'No-op: this app has no search index (kept so the shared fetch sequence works)';

    public function handle(): int
    {
        $this->line('No search index in this app — nothing to sync.');

        return self::SUCCESS;
    }
}
