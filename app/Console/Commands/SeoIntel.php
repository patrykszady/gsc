<?php

namespace App\Console\Commands;

use App\Services\DataForSeoService;
use App\Services\Seo\Intel\IntelRunner;
use App\Services\Seo\Intel\IntelStore;
use Illuminate\Console\Command;

/**
 * DataForSEO intelligence: every API family as a scheduled collector whose
 * results are stored per run and compared with the previous run.
 *
 *   php artisan seo:intel                 all families, within --budget
 *   php artisan seo:intel onpage backlinks
 *   php artisan seo:intel --list          families, estimated cost, last run
 *   php artisan seo:intel --dry-run       collect and print, store nothing
 *   php artisan seo:intel --findings      re-run only the comparison on stored data (no API calls)
 */
class SeoIntel extends Command
{
    protected $signature = 'seo:intel {family?* : Families to run (default: all registered)}
        {--budget=2 : Max USD to spend across this run}
        {--dry-run : Collect and print, store nothing}
        {--findings : Only recompute findings from stored snapshots}
        {--list : Show registered families and their last run}
        {--reset : Delete the named families\' snapshots, findings and runs before collecting (start over)}';

    protected $description = 'Collect DataForSEO intelligence (on-page, backlinks, Labs, SERP, business data, content, AI) into per-run snapshots and findings';

    public function handle(DataForSeoService $dfs, IntelRunner $runner, IntelStore $store): int
    {
        if (! $store->ready()) {
            $this->comment('seo_intel_* tables missing — run migrations first.');

            return self::SUCCESS;
        }
        $only = (array) $this->argument('family') ?: null;
        $sources = $runner->sources($only);
        if ($only && count($sources) !== count($only)) {
            $this->error('Unknown family: ' . implode(', ', array_diff($only, array_keys($sources))) . '. Registered: ' . implode(', ', array_keys($runner->sources())));

            return self::FAILURE;
        }
        if ($this->option('list')) {
            $this->table(['Family', 'Label', 'Est. $/run', 'Last run', 'Cost', 'Open findings'], collect($sources)->map(function ($s) use ($store) {
                $last = $store->runs($s->family(), 1)->first();

                return [$s->family(), $s->label(), number_format($s->estimateCost(), 3), $last->taken_on ?? '—', $last ? number_format((float) $last->cost, 3) : '—', $store->openFindings($s->family(), 500)->count()];
            })->all());

            return self::SUCCESS;
        }
        if (! $sources) {
            $this->comment('No intelligence sources registered.');

            return self::SUCCESS;
        }

        if ($this->option('reset')) {
            if (! $only) {
                $this->error('--reset needs explicit family names.');

                return self::FAILURE;
            }
            foreach (array_keys($sources) as $family) {
                $n = $store->reset($family);
                $this->warn("  {$family}: reset — removed {$n['snapshots']} snapshots, {$n['findings']} findings, {$n['runs']} runs.");
            }
        }
        $findingsOnly = (bool) $this->option('findings');
        $dryRun = (bool) $this->option('dry-run');
        $budget = (float) $this->option('budget');
        if (! $findingsOnly) {
            if (! $dfs->isConfigured()) {
                $this->comment('DataForSEO not configured — skipping.');

                return self::SUCCESS;
            }
            $estimate = array_sum(array_map(fn ($s) => $s->estimateCost(), $sources));
            $balance = $dfs->balance();
            if ($balance !== null && $balance < min($estimate, $budget)) {
                $this->error(sprintf('DataForSEO balance $%.2f cannot cover this run (~$%.2f).', $balance, min($estimate, $budget)));

                return self::FAILURE;
            }
            $this->line(sprintf('Balance $%s · budget $%.2f · estimated $%.2f for %s', $balance !== null ? number_format($balance, 2) : '?', $budget, $estimate, implode(', ', array_keys($sources))));
        }

        $failed = 0;
        foreach ($sources as $family => $source) {
            if (! $findingsOnly && $runner->spent() > 0 && $runner->spent() + $source->estimateCost() > $budget) {
                $this->warn(sprintf('  %s: skipped, budget reached ($%.3f spent).', $family, $runner->spent()));
                continue;
            }
            $r = $runner->run($source, $dryRun, $findingsOnly);
            if (! empty($r['skipped'])) {
                $this->line(sprintf('  %-18s —    nothing to collect right now', $family));
                continue;
            }
            $line = sprintf('  %-18s %s  %3d snapshots  $%.3f  findings +%d / %d open / -%d resolved  (%.1fs)',
                $family, $r['ok'] ? 'ok ' : 'ERR', $r['snapshots'], $r['cost'], $r['findings']['new'], $r['findings']['open'], $r['findings']['resolved'], $r['duration_ms'] / 1000);
            $r['ok'] ? $this->info($line) : $this->error($line . '  ' . $r['error']);
            $failed += $r['ok'] ? 0 : 1;
            if ($this->output->isVerbose() || $dryRun) {
                foreach ($store->openFindings($family, 12) as $f) {
                    $this->line(sprintf('     [%s] %s%s', $f->severity, $f->title, $f->key ? ' — ' . $f->key : ''));
                }
            }
        }
        $this->info(sprintf('Done. Spent $%.3f.', $runner->spent()));

        return $failed === count($sources) ? self::FAILURE : self::SUCCESS;
    }
}
