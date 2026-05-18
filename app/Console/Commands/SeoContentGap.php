<?php

namespace App\Console\Commands;

use App\Models\GscQueryMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Content / topic-cluster gap planner.
 *
 * Pulls queries from gsc_query_metrics in the rank-band 8..20 with meaningful
 * impressions — i.e., queries we already "almost" rank for — and groups them
 * by intent/cluster (using simple token-overlap clustering on the query
 * stems). Each cluster becomes a candidate content brief or page-expansion
 * target.
 */
class SeoContentGap extends Command
{
    protected $signature = 'seo:content-gap
        {--days=28 : Days back to aggregate}
        {--min-pos=8 : Minimum average position to consider}
        {--max-pos=20 : Maximum average position to consider}
        {--min-impressions=50 : Drop queries with fewer impressions}
        {--max-clusters=20 : Show top N clusters}
        {--markdown : Save report to storage/app/reports/content-gap.md}';

    protected $description = 'Surface low-hanging GSC queries (rank 8-20) and cluster them into content-brief candidates.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $to = Carbon::today();
        $from = $to->copy()->subDays($days - 1);

        $rows = GscQueryMetric::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('query, page, SUM(impressions) as impr, SUM(clicks) as clicks, SUM(impressions * position) as wpos')
            ->groupBy('query', 'page')
            ->havingRaw('SUM(impressions) >= ?', [(int) $this->option('min-impressions')])
            ->get();

        $minPos = (float) $this->option('min-pos');
        $maxPos = (float) $this->option('max-pos');

        $candidates = [];
        foreach ($rows as $r) {
            $impr = (int) $r->impr;
            if ($impr <= 0) continue;
            $pos = ((float) $r->wpos) / $impr;
            if ($pos < $minPos || $pos > $maxPos) continue;
            $candidates[] = [
                'query' => (string) $r->query,
                'page' => (string) ($r->page ?? ''),
                'impr' => $impr,
                'clicks' => (int) $r->clicks,
                'pos' => round($pos, 2),
            ];
        }

        if (empty($candidates)) {
            $this->warn('No rank-band queries found in window. Make sure seo:gsc-sync has been run.');
            return self::SUCCESS;
        }

        $clusters = $this->cluster($candidates);
        usort($clusters, fn ($a, $b) => $b['impr'] <=> $a['impr']);
        $clusters = array_slice($clusters, 0, (int) $this->option('max-clusters'));

        $this->renderClusters($clusters);

        if ($this->option('markdown')) {
            $this->saveMarkdown($clusters, $from, $to);
        }

        return self::SUCCESS;
    }

    /**
     * Token-overlap clustering: queries sharing >=2 significant tokens
     * (length >= 4, not in stopword list) fall into the same cluster.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    protected function cluster(array $candidates): array
    {
        $stop = ['near', 'best', 'cheap', 'cost', 'price', 'with', 'from', 'that', 'this', 'your', 'their', 'they', 'have', 'have'];
        $tokensOf = function (string $q) use ($stop): array {
            $q = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $q) ?? '');
            $parts = array_filter(preg_split('/\s+/', $q) ?: [], fn ($t) => mb_strlen($t) >= 4 && ! in_array($t, $stop, true));
            return array_values(array_unique($parts));
        };

        $items = array_map(function ($c) use ($tokensOf) {
            $c['tokens'] = $tokensOf($c['query']);
            return $c;
        }, $candidates);

        $clusters = [];
        $assigned = array_fill(0, count($items), -1);

        for ($i = 0; $i < count($items); $i++) {
            if ($assigned[$i] !== -1) continue;
            $cid = count($clusters);
            $assigned[$i] = $cid;
            $clusters[$cid] = ['members' => [$items[$i]], 'tokens' => $items[$i]['tokens']];
            for ($j = $i + 1; $j < count($items); $j++) {
                if ($assigned[$j] !== -1) continue;
                $overlap = count(array_intersect($clusters[$cid]['tokens'], $items[$j]['tokens']));
                if ($overlap >= 2) {
                    $assigned[$j] = $cid;
                    $clusters[$cid]['members'][] = $items[$j];
                    $clusters[$cid]['tokens'] = array_values(array_unique(array_merge($clusters[$cid]['tokens'], $items[$j]['tokens'])));
                }
            }
        }

        // Summarize
        return array_map(function ($c) {
            $impr = array_sum(array_column($c['members'], 'impr'));
            $clicks = array_sum(array_column($c['members'], 'clicks'));
            $avgPos = $impr > 0
                ? array_sum(array_map(fn ($m) => $m['pos'] * $m['impr'], $c['members'])) / $impr
                : 0;
            // Theme label = most-frequent significant token
            $allTokens = [];
            foreach ($c['members'] as $m) $allTokens = array_merge($allTokens, $m['tokens']);
            $freq = array_count_values($allTokens);
            arsort($freq);
            $theme = implode(' + ', array_slice(array_keys($freq), 0, 3));
            return [
                'theme' => $theme,
                'impr' => $impr,
                'clicks' => $clicks,
                'avg_pos' => round($avgPos, 2),
                'queries' => $c['members'],
            ];
        }, $clusters);
    }

    /**
     * @param array<int, array<string, mixed>> $clusters
     */
    protected function renderClusters(array $clusters): void
    {
        $this->newLine();
        $this->line('<fg=cyan>--- Content-gap clusters (rank 8-20) ---</>');
        foreach ($clusters as $i => $c) {
            $this->line(sprintf('%d. [%s]  %d impr, %d clicks, avg pos %.2f  (%d queries)',
                $i + 1, $c['theme'], $c['impr'], $c['clicks'], $c['avg_pos'], count($c['queries'])));
            foreach (array_slice($c['queries'], 0, 5) as $q) {
                $this->line(sprintf('     • "%s"  → %s  (pos %.2f, %d impr)', $q['query'], $q['page'] ?: '—', $q['pos'], $q['impr']));
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $clusters
     */
    protected function saveMarkdown(array $clusters, Carbon $from, Carbon $to): void
    {
        $md = "# Content-gap report (rank-band 8–20)\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= sprintf("Window: %s..%s\n\n", $from->toDateString(), $to->toDateString());

        foreach ($clusters as $i => $c) {
            $md .= sprintf("## %d. %s\n\n", $i + 1, $c['theme']);
            $md .= sprintf("- **Impressions:** %d  |  **Clicks:** %d  |  **Avg position:** %.2f  |  **Queries:** %d\n\n",
                $c['impr'], $c['clicks'], $c['avg_pos'], count($c['queries']));
            $md .= "| Query | Current page | Position | Impressions |\n|---|---|---:|---:|\n";
            foreach ($c['queries'] as $q) {
                $md .= sprintf("| %s | %s | %.2f | %d |\n", $q['query'], $q['page'] ?: '—', $q['pos'], $q['impr']);
            }
            $md .= "\n";
        }
        $md .= "\n## Action\n\nFor each cluster: if a strong page exists at _Current page_, expand it (add FAQ, schema, internal links). If multiple pages compete, consolidate. If none rank well, draft a new page targeted at the cluster theme.\n";

        Storage::disk('local')->put('reports/content-gap.md', $md);
        $this->info('Saved: storage/app/reports/content-gap.md');
    }
}
