<?php

namespace App\Console\Commands;

use App\Models\GscQueryMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Content-decay detector.
 *
 * Compares the most recent `--window` days against the immediately-preceding
 * `--window` days from the gsc_query_metrics table and surfaces pages whose
 * clicks dropped, impressions dropped, or average position regressed beyond
 * the configured thresholds.
 *
 * Aggregates at the PAGE level (impression-weighted position).
 */
class SeoContentDecay extends Command
{
    protected $signature = 'seo:content-decay
        {--window=28 : Days in each comparison window}
        {--min-impressions=50 : Ignore pages with fewer impressions in the prior window}
        {--click-drop=20 : Flag pages losing >=N% clicks}
        {--pos-drop=2 : Flag pages whose average position worsened by >=N}
        {--limit=40 : Max rows per section}
        {--markdown : Save report to storage/app/reports/content-decay.md}';

    protected $description = 'Find pages with declining clicks / impressions / position from Search Console data.';

    public function handle(): int
    {
        $win = max(1, (int) $this->option('window'));
        $end = Carbon::today();
        $recentStart = $end->copy()->subDays($win - 1);
        $priorEnd = $recentStart->copy()->subDay();
        $priorStart = $priorEnd->copy()->subDays($win - 1);

        $this->info(sprintf(
            'Recent: %s..%s   Prior: %s..%s',
            $recentStart->toDateString(), $end->toDateString(),
            $priorStart->toDateString(), $priorEnd->toDateString(),
        ));

        $recent = $this->aggregate($recentStart, $end);
        $prior = $this->aggregate($priorStart, $priorEnd);

        $minImpr = max(1, (int) $this->option('min-impressions'));
        $clickDropPct = max(1, (int) $this->option('click-drop'));
        $posDrop = (float) $this->option('pos-drop');

        $rows = [];
        foreach ($prior as $page => $p) {
            if ($p['impressions'] < $minImpr) {
                continue;
            }
            $r = $recent[$page] ?? ['impressions' => 0, 'clicks' => 0, 'position' => null];
            $clickDelta = $r['clicks'] - $p['clicks'];
            $clickPct = $p['clicks'] > 0 ? ($clickDelta / $p['clicks']) * 100 : 0;
            $imprDelta = $r['impressions'] - $p['impressions'];
            $imprPct = $p['impressions'] > 0 ? ($imprDelta / $p['impressions']) * 100 : 0;
            $posDelta = ($r['position'] !== null && $p['position'] !== null)
                ? $r['position'] - $p['position']
                : null;

            $rows[] = [
                'page' => $page,
                'p_clicks' => $p['clicks'], 'r_clicks' => $r['clicks'], 'click_pct' => $clickPct,
                'p_impr' => $p['impressions'], 'r_impr' => $r['impressions'], 'impr_pct' => $imprPct,
                'p_pos' => $p['position'], 'r_pos' => $r['position'], 'pos_delta' => $posDelta,
            ];
        }

        $clickDecay = collect($rows)
            ->filter(fn ($r) => $r['p_clicks'] > 0 && $r['click_pct'] <= -$clickDropPct)
            ->sortBy('click_pct')
            ->take((int) $this->option('limit'))
            ->values();

        $posDecay = collect($rows)
            ->filter(fn ($r) => $r['pos_delta'] !== null && $r['pos_delta'] >= $posDrop)
            ->sortByDesc('pos_delta')
            ->take((int) $this->option('limit'))
            ->values();

        $this->renderTable('Click drops', $clickDecay, ['page', 'p_clicks', 'r_clicks', 'click_pct'], ['Page', 'Prior clicks', 'Recent clicks', '% change']);
        $this->renderTable('Position regressions', $posDecay, ['page', 'p_pos', 'r_pos', 'pos_delta'], ['Page', 'Prior pos', 'Recent pos', 'Δ pos']);

        if ($this->option('markdown')) {
            $this->saveMarkdown($clickDecay, $posDecay, $recentStart, $end, $priorStart, $priorEnd);
        }

        if ($clickDecay->isNotEmpty() || $posDecay->isNotEmpty()) {
            // A 1-2 click page dropping to 0 is statistical noise, not decay —
            // count only drops from a meaningful click base toward the WARNING
            // level (the full list still lands in the report either way).
            $meaningfulClickDrops = $clickDecay->where('p_clicks', '>=', 5)->count();

            $summary = [
                'click_drops' => $clickDecay->count(),
                'meaningful_click_drops' => $meaningfulClickDrops,
                'pos_regressions' => $posDecay->count(),
            ];

            $cacheKey = 'seo:content-decay:warn:' . now()->format('Ymd') . ':' . md5(json_encode($summary));
            if (! Cache::add($cacheKey, true, now()->addHours(30))) {
                logger()->info('seo:content-decay repeated regressions suppressed', $summary);
            } elseif ($meaningfulClickDrops > 0) {
                logger()->warning('seo:content-decay found regressions', $summary);
            } else {
                logger()->info('seo:content-decay: low-volume churn only (no page lost 5+ clicks)', $summary);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{impressions:int,clicks:int,position:?float}>
     */
    protected function aggregate(Carbon $from, Carbon $to): array
    {
        $rows = GscQueryMetric::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('page, SUM(impressions) as impr, SUM(clicks) as clicks, SUM(impressions * position) as weighted_pos')
            ->groupBy('page')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $impr = (int) $r->impr;
            $out[$r->page] = [
                'impressions' => $impr,
                'clicks' => (int) $r->clicks,
                'position' => $impr > 0 ? round(((float) $r->weighted_pos) / $impr, 2) : null,
            ];
        }
        return $out;
    }

    protected function renderTable(string $title, \Illuminate\Support\Collection $rows, array $cols, array $headers): void
    {
        $this->newLine();
        $this->line("<fg=cyan>--- {$title} (" . $rows->count() . ') ---</>');
        if ($rows->isEmpty()) {
            $this->line('  (none)');
            return;
        }
        $tbl = $rows->map(function ($r) use ($cols) {
            $out = [];
            foreach ($cols as $c) {
                $v = $r[$c];
                if (is_float($v) && str_ends_with($c, '_pct')) {
                    $v = sprintf('%+.1f%%', $v);
                } elseif (is_float($v)) {
                    $v = number_format($v, 2);
                } elseif ($v === null) {
                    $v = '—';
                }
                $out[] = is_string($v) && str_starts_with($v, '/') ? \Illuminate\Support\Str::limit($v, 60) : $v;
            }
            return $out;
        })->all();
        $this->table($headers, $tbl);
    }

    protected function saveMarkdown(
        \Illuminate\Support\Collection $clickDecay,
        \Illuminate\Support\Collection $posDecay,
        Carbon $rs, Carbon $re, Carbon $ps, Carbon $pe,
    ): void {
        $md = "# Content decay report\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= sprintf("Recent window: %s..%s   Prior window: %s..%s\n\n",
            $rs->toDateString(), $re->toDateString(), $ps->toDateString(), $pe->toDateString());

        $md .= "## Click drops (" . $clickDecay->count() . ")\n\n";
        $md .= "| Page | Prior clicks | Recent clicks | % change |\n|---|---:|---:|---:|\n";
        foreach ($clickDecay as $r) {
            $md .= sprintf("| %s | %d | %d | %+.1f%% |\n", $r['page'], $r['p_clicks'], $r['r_clicks'], $r['click_pct']);
        }

        $md .= "\n## Position regressions (" . $posDecay->count() . ")\n\n";
        $md .= "| Page | Prior pos | Recent pos | Δ pos |\n|---|---:|---:|---:|\n";
        foreach ($posDecay as $r) {
            $md .= sprintf("| %s | %s | %s | %+.2f |\n",
                $r['page'],
                $r['p_pos'] !== null ? number_format($r['p_pos'], 2) : '—',
                $r['r_pos'] !== null ? number_format($r['r_pos'], 2) : '—',
                $r['pos_delta'],
            );
        }

        $md .= "\n## Action\n\nFor each page above: review on-page content for freshness, broken/dated facts, missing internal links from newer pages, and dropped queries (cross-reference with `seo:gsc-monitor`). Update last_modified after substantive edits so the sitemap reflects the change.\n";

        Storage::disk('local')->put('reports/content-decay.md', $md);
        $this->info('Saved: storage/app/reports/content-decay.md');
    }
}
