<?php

namespace App\Console\Commands;

use App\Models\GscQueryMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Weekly Search Console regression monitor.
 *
 * Compares the most recent window (default last 7 days, ending --lag-days ago)
 * against the immediately preceding window of the same length, and surfaces:
 *
 *   - Query position drops   : queries that lost >= --min-position-drop slots
 *                              and previously ranked <= 20.
 *   - Query click drops      : queries whose clicks fell by >= --min-click-drop %
 *                              and previously had >= --min-prior-clicks clicks.
 *   - Page impression drops  : pages whose impressions fell by >= --min-impr-drop %
 *                              and previously had >= --min-prior-impr impressions.
 *   - New top-10 entrants    : queries that newly reached avg position <= 10.
 *   - Lost top-20            : queries previously <= 20, now > 20 or absent.
 *
 * Read-only against gsc_query_metrics. Writes a markdown digest with
 * `--markdown` to storage/app/reports/gsc-monitor.md.
 */
class SeoGscMonitor extends Command
{
    protected $signature = 'seo:gsc-monitor
        {--window=7 : Days in each comparison window}
        {--lag-days=2 : Skip the most recent N days (GSC data lags)}
        {--min-position-drop=3 : Minimum avg-position drop to flag}
        {--min-click-drop=50 : Minimum % click drop to flag}
        {--min-impr-drop=50 : Minimum % impression drop to flag}
        {--min-prior-clicks=10 : Skip queries with fewer than N clicks in the prior window}
        {--min-prior-impr=100 : Skip pages with fewer than N impressions in the prior window}
        {--limit=25 : Max rows per section}
        {--markdown : Save report to storage/app/reports/gsc-monitor.md}';

    protected $description = 'Detect week-over-week Search Console regressions: position drops, click drops, page impression collapse, lost top-20, new top-10.';

    public function handle(): int
    {
        $window = max(1, (int) $this->option('window'));
        $lag = max(0, (int) $this->option('lag-days'));
        $minPosDrop = (float) $this->option('min-position-drop');
        $minClickDrop = (float) $this->option('min-click-drop');
        $minImprDrop = (float) $this->option('min-impr-drop');
        $minPriorClicks = (int) $this->option('min-prior-clicks');
        $minPriorImpr = (int) $this->option('min-prior-impr');
        $limit = max(1, (int) $this->option('limit'));

        $currentEnd = Carbon::today()->subDays($lag);
        $currentStart = $currentEnd->copy()->subDays($window - 1);
        $priorEnd = $currentStart->copy()->subDay();
        $priorStart = $priorEnd->copy()->subDays($window - 1);

        $this->info(sprintf(
            'Current: %s → %s   Prior: %s → %s',
            $currentStart->toDateString(),
            $currentEnd->toDateString(),
            $priorStart->toDateString(),
            $priorEnd->toDateString(),
        ));

        if (! GscQueryMetric::query()->whereBetween('date', [$priorStart->toDateString(), $currentEnd->toDateString()])->exists()) {
            $this->warn('No GSC data in the comparison range. Run: php artisan seo:gsc-sync --days=' . ($window * 2 + $lag));
            return self::FAILURE;
        }

        $currentByQuery = $this->aggregate('query', $currentStart, $currentEnd);
        $priorByQuery = $this->aggregate('query', $priorStart, $priorEnd);
        $currentByPage = $this->aggregate('page', $currentStart, $currentEnd);
        $priorByPage = $this->aggregate('page', $priorStart, $priorEnd);

        $positionDrops = $this->positionDrops($currentByQuery, $priorByQuery, $minPosDrop, $limit);
        $clickDrops = $this->clickDrops($currentByQuery, $priorByQuery, $minClickDrop, $minPriorClicks, $limit);
        $imprDrops = $this->imprDrops($currentByPage, $priorByPage, $minImprDrop, $minPriorImpr, $limit);
        $newTop10 = $this->newTop10($currentByQuery, $priorByQuery, $limit);
        $lostTop20 = $this->lostTop20($currentByQuery, $priorByQuery, $limit);

        $this->renderSection('Position drops (≥ ' . $minPosDrop . ' slots, prior pos ≤ 20)', $positionDrops, [
            'Query', 'Prior pos', 'Current pos', 'Δ', 'Prior clicks', 'Current clicks',
        ]);
        $this->renderSection('Click drops (≥ ' . $minClickDrop . '%, prior clicks ≥ ' . $minPriorClicks . ')', $clickDrops, [
            'Query', 'Prior clicks', 'Current clicks', 'Δ %',
        ]);
        $this->renderSection('Page impression drops (≥ ' . $minImprDrop . '%, prior impr ≥ ' . $minPriorImpr . ')', $imprDrops, [
            'Page', 'Prior impr', 'Current impr', 'Δ %',
        ]);
        $this->renderSection('New top-10 queries (positive)', $newTop10, [
            'Query', 'Prior pos', 'Current pos', 'Current clicks',
        ]);
        $this->renderSection('Lost top-20 queries', $lostTop20, [
            'Query', 'Prior pos', 'Current pos', 'Prior clicks',
        ]);

        if ($this->option('markdown')) {
            $this->saveMarkdown(
                $currentStart, $currentEnd, $priorStart, $priorEnd,
                $positionDrops, $clickDrops, $imprDrops, $newTop10, $lostTop20,
            );
        }

        $hasRegressions = ! empty($positionDrops) || ! empty($clickDrops) || ! empty($imprDrops) || ! empty($lostTop20);
        if ($hasRegressions) {
            logger()->warning('seo:gsc-monitor: regressions detected', [
                'position_drops' => count($positionDrops),
                'click_drops' => count($clickDrops),
                'impr_drops' => count($imprDrops),
                'lost_top20' => count($lostTop20),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Aggregate impressions/clicks and impression-weighted avg position.
     *
     * @return array<string, array{impr:int,clicks:int,pos:float}>
     */
    protected function aggregate(string $dim, Carbon $start, Carbon $end): array
    {
        $rows = GscQueryMetric::query()
            ->select($dim . ' as key')
            ->selectRaw('SUM(impressions) as impr')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(position * impressions) as pos_w')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy($dim)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $impr = (int) $r->impr;
            $out[(string) $r->key] = [
                'impr' => $impr,
                'clicks' => (int) $r->clicks,
                'pos' => $impr > 0 ? round(((float) $r->pos_w) / $impr, 2) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array{impr:int,clicks:int,pos:float}> $cur
     * @param array<string, array{impr:int,clicks:int,pos:float}> $prior
     * @return array<int, array<string, mixed>>
     */
    protected function positionDrops(array $cur, array $prior, float $minDrop, int $limit): array
    {
        $out = [];
        foreach ($prior as $key => $p) {
            if ($p['pos'] <= 0 || $p['pos'] > 20) {
                continue;
            }
            $c = $cur[$key] ?? ['impr' => 0, 'clicks' => 0, 'pos' => 0.0];
            // Treat "not present" as position 100.
            $curPos = $c['pos'] > 0 ? $c['pos'] : 100.0;
            $delta = $curPos - $p['pos'];
            if ($delta < $minDrop) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'prior_pos' => $p['pos'],
                'cur_pos' => $curPos,
                'delta' => round($delta, 2),
                'prior_clicks' => $p['clicks'],
                'cur_clicks' => $c['clicks'],
            ];
        }
        usort($out, fn ($a, $b) => $b['delta'] <=> $a['delta']);
        return array_slice($out, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function clickDrops(array $cur, array $prior, float $minDropPct, int $minPriorClicks, int $limit): array
    {
        $out = [];
        foreach ($prior as $key => $p) {
            if ($p['clicks'] < $minPriorClicks || $p['clicks'] <= 0) {
                continue;
            }
            $c = $cur[$key] ?? ['clicks' => 0];
            $dropPct = (($p['clicks'] - $c['clicks']) / $p['clicks']) * 100;
            if ($dropPct < $minDropPct) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'prior_clicks' => $p['clicks'],
                'cur_clicks' => $c['clicks'],
                'drop_pct' => round($dropPct, 1),
            ];
        }
        usort($out, fn ($a, $b) => $b['drop_pct'] <=> $a['drop_pct']);
        return array_slice($out, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function imprDrops(array $cur, array $prior, float $minDropPct, int $minPriorImpr, int $limit): array
    {
        $out = [];
        foreach ($prior as $key => $p) {
            if ($p['impr'] < $minPriorImpr || $p['impr'] <= 0) {
                continue;
            }
            $c = $cur[$key] ?? ['impr' => 0];
            $dropPct = (($p['impr'] - $c['impr']) / $p['impr']) * 100;
            if ($dropPct < $minDropPct) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'prior_impr' => $p['impr'],
                'cur_impr' => $c['impr'],
                'drop_pct' => round($dropPct, 1),
            ];
        }
        usort($out, fn ($a, $b) => $b['drop_pct'] <=> $a['drop_pct']);
        return array_slice($out, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function newTop10(array $cur, array $prior, int $limit): array
    {
        $out = [];
        foreach ($cur as $key => $c) {
            if ($c['pos'] <= 0 || $c['pos'] > 10) {
                continue;
            }
            $p = $prior[$key] ?? null;
            $priorPos = $p && $p['pos'] > 0 ? $p['pos'] : null;
            if ($priorPos !== null && $priorPos <= 10) {
                continue; // already in top 10
            }
            $out[] = [
                'key' => $key,
                'prior_pos' => $priorPos ?? '—',
                'cur_pos' => $c['pos'],
                'cur_clicks' => $c['clicks'],
            ];
        }
        usort($out, fn ($a, $b) => $b['cur_clicks'] <=> $a['cur_clicks']);
        return array_slice($out, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function lostTop20(array $cur, array $prior, int $limit): array
    {
        $out = [];
        foreach ($prior as $key => $p) {
            if ($p['pos'] <= 0 || $p['pos'] > 20) {
                continue;
            }
            $c = $cur[$key] ?? null;
            $curPos = $c && $c['pos'] > 0 ? $c['pos'] : null;
            if ($curPos !== null && $curPos <= 20) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'prior_pos' => $p['pos'],
                'cur_pos' => $curPos ?? 'lost',
                'prior_clicks' => $p['clicks'],
            ];
        }
        usort($out, fn ($a, $b) => $b['prior_clicks'] <=> $a['prior_clicks']);
        return array_slice($out, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $headers
     */
    protected function renderSection(string $heading, array $rows, array $headers): void
    {
        $this->newLine();
        $this->line('<fg=cyan>--- ' . $heading . ' ---</>');
        if (empty($rows)) {
            $this->line('  (none)');
            return;
        }
        $tableRows = array_map(function (array $r) {
            $vals = array_values($r);
            return array_map(fn ($v) => is_string($v) ? Str::limit($v, 60) : $v, $vals);
        }, $rows);
        $this->table($headers, $tableRows);
    }

    protected function saveMarkdown(
        Carbon $cs, Carbon $ce, Carbon $ps, Carbon $pe,
        array $posDrops, array $clickDrops, array $imprDrops, array $newTop10, array $lostTop20,
    ): void {
        $md = "# GSC monitor — week-over-week\n\n";
        $md .= sprintf("Current window: **%s → %s**  \n", $cs->toDateString(), $ce->toDateString());
        $md .= sprintf("Prior window:   **%s → %s**\n\n", $ps->toDateString(), $pe->toDateString());

        $md .= $this->mdSection('Position drops', $posDrops, ['Query', 'Prior pos', 'Current pos', 'Δ', 'Prior clicks', 'Current clicks']);
        $md .= $this->mdSection('Click drops', $clickDrops, ['Query', 'Prior clicks', 'Current clicks', 'Δ %']);
        $md .= $this->mdSection('Page impression drops', $imprDrops, ['Page', 'Prior impr', 'Current impr', 'Δ %']);
        $md .= $this->mdSection('New top-10 entrants', $newTop10, ['Query', 'Prior pos', 'Current pos', 'Current clicks']);
        $md .= $this->mdSection('Lost top-20', $lostTop20, ['Query', 'Prior pos', 'Current pos', 'Prior clicks']);

        Storage::disk('local')->put('reports/gsc-monitor.md', $md);
        $this->info('Saved: storage/app/reports/gsc-monitor.md');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $headers
     */
    protected function mdSection(string $title, array $rows, array $headers): string
    {
        $out = "## {$title}\n\n";
        if (empty($rows)) {
            return $out . "_None._\n\n";
        }
        $out .= '| ' . implode(' | ', $headers) . " |\n";
        $out .= '|' . str_repeat('---|', count($headers)) . "\n";
        foreach ($rows as $r) {
            $cells = array_values($r);
            $out .= '| ' . implode(' | ', array_map(
                fn ($v) => str_replace('|', '\\|', (string) $v),
                $cells
            )) . " |\n";
        }
        return $out . "\n";
    }
}
