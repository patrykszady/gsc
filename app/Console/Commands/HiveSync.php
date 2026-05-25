<?php

namespace App\Console\Commands;

use App\Models\HiveProjectZipCount;
use App\Services\HiveProjectsClient;
use Illuminate\Console\Command;
use Throwable;

class HiveSync extends Command
{
    protected $signature = 'hive:sync
                            {--show : Only show the current local state, do not fetch}';

    protected $description = 'Pull project zip counts from hive.contractors and persist locally. Use --show to inspect what is stored.';

    public function handle(HiveProjectsClient $hive): int
    {
        if ($this->option('show')) {
            return $this->showStatus($hive);
        }

        $this->info('Syncing zip counts from hive.contractors ...');

        try {
            $count = $hive->sync();
        } catch (Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info("OK — persisted {$count} zip rows.");
        $this->newLine();
        $this->showStatus($hive);

        return self::SUCCESS;
    }

    protected function showStatus(HiveProjectsClient $hive): int
    {
        $total = HiveProjectZipCount::query()->count();
        $sum = (int) HiveProjectZipCount::query()->sum('count');
        $last = $hive->lastSyncedAt();

        $this->line('Stored rows:   ' . $total);
        $this->line('Total projects: ' . $sum);
        $this->line('Last synced:   ' . ($last ? $last->diffForHumans() . ' (' . $last->toDateTimeString() . ')' : 'never'));

        if ($total === 0) {
            $this->warn('No zip data stored yet. Run `php artisan hive:sync` to fetch.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Top 10 zips:');
        $top = HiveProjectZipCount::query()
            ->orderByDesc('count')
            ->limit(10)
            ->get(['zip', 'count'])
            ->map(fn ($r) => ['zip' => $r->zip, 'count' => $r->count])
            ->all();

        $this->table(['ZIP', 'Projects'], $top);

        return self::SUCCESS;
    }
}
