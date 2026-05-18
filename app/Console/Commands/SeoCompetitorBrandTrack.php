<?php

namespace App\Console\Commands;

use App\Models\GscQueryMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Surface GSC queries that signal competitor / comparison intent:
 *   - competitor brand names (from config/competitors.php)
 *   - generic comparison modifiers: "alternative", "vs", "best", "near me", "reviews"
 *
 * Output is grouped by bucket so we can see where to invest content next
 * (e.g. low-position high-impression "vs" terms = build a comparison page).
 */
class SeoCompetitorBrandTrack extends Command
{
    protected $signature = 'seo:competitor-brand-track
        {--days=28 : Look-back window in days}
        {--country=usa : Country filter (blank = all)}
        {--min-impressions=1 : Minimum impressions threshold}
        {--limit=50 : Max rows per bucket}
        {--markdown : Save markdown report to storage/app/reports/competitor-brand.md}';

    protected $description = 'Track GSC queries with competitor-brand or comparison intent (alternative/vs/best/reviews).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $country = trim((string) $this->option('country'));
        $minImpressions = max(1, (int) $this->option('min-impressions'));
        $limit = max(1, (int) $this->option('limit'));

        $from = now()->subDays($days)->toDateString();

        $rows = GscQueryMetric::query()
            ->where('date', '>=', $from)
            ->when($country !== '', fn ($q) => $q->where('country', $country))
            ->groupBy('query')
            ->select([
                'query',
                DB::raw('SUM(impressions) as imp'),
                DB::raw('SUM(clicks) as clk'),
                DB::raw('AVG(position) as pos'),
                DB::raw('MAX(page) as sample_page'),
            ])
            ->having('imp', '>=', $minImpressions)
            ->orderByDesc('imp')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No GSC data in window. Run seo:gsc-sync first.');
            return self::SUCCESS;
        }

        $buckets = $this->bucketize($rows, $limit);

        $totalMatched = array_sum(array_map('count', $buckets));
        $this->info("Scanned {$rows->count()} queries — {$totalMatched} matched competitor/comparison intent in last {$days} days.");

        foreach ($buckets as $name => $bucketRows) {
            if (empty($bucketRows)) {
                continue;
            }

            $this->newLine();
            $this->line("<fg=cyan>--- {$name} (" . count($bucketRows) . ') ---</>');
            $this->table(
                ['Query', 'Imp', 'Clk', 'Pos', 'Sample page'],
                array_map(fn ($r) => [
                    $r['query'],
                    $r['imp'],
                    $r['clk'],
                    number_format($r['pos'], 1),
                    $this->shortPath((string) $r['sample_page']),
                ], $bucketRows),
            );
        }

        if ($this->option('markdown')) {
            $this->saveMarkdown($buckets, $days);
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function bucketize($rows, int $limit): array
    {
        $brandTerms = $this->brandTerms();
        $vsPattern = '/\b(vs\.?|versus)\b/i';
        $altPattern = '/\b(alternative|alternatives|other than|better than|instead of)\b/i';
        $reviewPattern = '/\b(reviews?|complaints?|rating|ratings)\b/i';
        $bestPattern = '/\b(best|top|top[- ]rated|highest[- ]rated)\b.*\b(remodel|renovat|contractor|kitchen|bath)/i';
        $nearMePattern = '/\bnear me\b/i';

        $buckets = [
            'Competitor brand mentions' => [],
            '"vs" / versus queries' => [],
            '"alternative" queries' => [],
            '"best / top" queries' => [],
            'Review-intent queries' => [],
            'Near-me queries' => [],
        ];

        foreach ($rows as $r) {
            $q = mb_strtolower((string) $r->query);
            $entry = [
                'query' => (string) $r->query,
                'imp' => (int) $r->imp,
                'clk' => (int) $r->clk,
                'pos' => (float) $r->pos,
                'sample_page' => (string) $r->sample_page,
            ];

            foreach ($brandTerms as $term) {
                if ($term !== '' && Str::contains($q, $term)) {
                    $buckets['Competitor brand mentions'][] = $entry + ['matched' => $term];
                    break;
                }
            }

            if (preg_match($vsPattern, $q)) {
                $buckets['"vs" / versus queries'][] = $entry;
            }
            if (preg_match($altPattern, $q)) {
                $buckets['"alternative" queries'][] = $entry;
            }
            if (preg_match($bestPattern, $q)) {
                $buckets['"best / top" queries'][] = $entry;
            }
            if (preg_match($reviewPattern, $q)) {
                $buckets['Review-intent queries'][] = $entry;
            }
            if (preg_match($nearMePattern, $q)) {
                $buckets['Near-me queries'][] = $entry;
            }
        }

        foreach ($buckets as $name => $list) {
            usort($list, fn ($a, $b) => $b['imp'] <=> $a['imp']);
            $buckets[$name] = array_slice($list, 0, $limit);
        }

        return $buckets;
    }

    /**
     * @return array<int, string>
     */
    protected function brandTerms(): array
    {
        $terms = [];
        foreach ((array) config('competitors.competitors', []) as $row) {
            $terms[] = mb_strtolower((string) ($row['name'] ?? ''));
            foreach ((array) ($row['also_known_as'] ?? []) as $aka) {
                $terms[] = mb_strtolower((string) $aka);
            }
            if (! empty($row['website'])) {
                $host = parse_url((string) $row['website'], PHP_URL_HOST);
                if ($host) {
                    $terms[] = mb_strtolower(preg_replace('/^www\./', '', $host));
                }
            }
        }

        return array_values(array_unique(array_filter($terms)));
    }

    protected function shortPath(string $page): string
    {
        $path = parse_url($page, PHP_URL_PATH) ?: $page;
        return Str::limit($path, 60);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $buckets
     */
    protected function saveMarkdown(array $buckets, int $days): void
    {
        $md = "# Competitor & comparison query tracker\n\n";
        $md .= '_Window: last ' . $days . " days_\n\n";

        foreach ($buckets as $name => $rows) {
            if (empty($rows)) {
                continue;
            }
            $md .= "## {$name}\n\n";
            $md .= "| Query | Imp | Clk | Pos | Sample page |\n";
            $md .= "|---|---:|---:|---:|---|\n";
            foreach ($rows as $r) {
                $md .= sprintf(
                    "| %s | %d | %d | %.1f | %s |\n",
                    str_replace('|', '\\|', (string) $r['query']),
                    (int) $r['imp'],
                    (int) $r['clk'],
                    (float) $r['pos'],
                    $this->shortPath((string) $r['sample_page']),
                );
            }
            $md .= "\n";
        }

        Storage::disk('local')->put('reports/competitor-brand.md', $md);
        $this->info('Saved: storage/app/reports/competitor-brand.md');
    }
}
