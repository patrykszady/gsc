<?php

namespace App\Console\Commands;

use App\Jobs\RunCitationsBatch;
use App\Services\Citations\CitationBatchRunner;
use Illuminate\Console\Command;

/**
 * Run every open directory automatically, one after another.
 *
 *   php artisan citations:batch                 inline, all tiers, prints each result
 *   php artisan citations:batch --tier=2        just the vetted directories
 *   php artisan citations:batch --only=manta --only=hotfrog
 *   php artisan citations:batch --queue         hand the list to the queue (one job per directory)
 *   php artisan citations:batch --dry-run       list what would run
 */
class CitationsBatch extends Command
{
    protected $signature = 'citations:batch {--tier=* : Only these tiers} {--only=* : Only these slugs} {--limit=100} {--queue : Dispatch to the queue instead of running inline} {--dry-run}';

    protected $description = 'Automatically submit every open directory listing; park the ones that need a person';

    public function handle(CitationBatchRunner $runner): int
    {
        $this->callSilently('citations:sync');
        $rows = $runner->eligible((array) $this->option('tier'), (array) $this->option('only'), (int) $this->option('limit'));
        if ($rows->isEmpty()) {
            $this->info('Nothing to run: every directory is live, declined, parked for a person, or waiting for verification.');

            return self::SUCCESS;
        }
        $this->line(sprintf('%d director%s: %s', $rows->count(), $rows->count() === 1 ? 'y' : 'ies', $rows->pluck('slug')->implode(', ')));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if ($this->option('queue')) {
            if (CitationBatchRunner::isActive()) {
                $this->error('An automatic run is already going (see citations:sync --list). Let it finish first.');

                return self::FAILURE;
            }
            CitationBatchRunner::progress($rows->pluck('slug')->all(), 0);
            RunCitationsBatch::dispatch($rows->pluck('slug')->all());
            $this->info('Queued. Follow it on the admin Citations board or with citations:sync --list.');

            return self::SUCCESS;
        }

        $counts = [];
        foreach ($rows as $i => $citation) {
            CitationBatchRunner::progress($rows->slice($i + 1)->pluck('slug')->values()->all(), $i, $citation->slug);
            $r = $runner->runOne($citation);
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            $this->line(sprintf('  %-26s %-20s %3ds  %s', $citation->name, $r['status'], $r['seconds'], \Illuminate\Support\Str::limit((string) ($r['reason'] ?: $r['note']), 110)));
        }
        CitationBatchRunner::progress([], $rows->count(), null, false);
        $this->info('Done: ' . collect($counts)->map(fn ($n, $s) => "{$n} {$s}")->implode(', ') . '.');

        return self::SUCCESS;
    }
}
