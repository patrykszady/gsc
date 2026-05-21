<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Competitor rank-share gap analysis.
 *
 * For each seed query (city + service combinations from AreaServed × the
 * service slugs we publish), pull the top-N organic SERP via SerpApi and
 * record:
 *
 *   - our best position on gs.construction (or "not in top N")
 *   - which configured competitors from config/competitors.php appear
 *   - position delta vs each competitor
 *
 * Then aggregate per-competitor: count of queries where they appear AND we
 * either don't appear or rank worse. That's the actionable gap.
 *
 * SerpApi cost: 1 search per seed query. The default --max-queries=20
 * cap keeps weekly runs to < 100/month (well inside the free tier).
 */
class SeoCompetitorRankGap extends Command
{
    protected $signature = 'seo:competitor-rank-gap
        {--services=kitchen-remodeling,bathroom-remodeling,home-remodeling,basement-remodeling,home-additions : CSV of service slugs to test}
        {--cities= : CSV of city names (defaults to first --max-cities AreaServed)}
        {--max-cities=4 : When --cities is empty, use this many top AreaServed rows}
        {--max-queries=20 : Hard cap on total SerpApi calls}
        {--top=20 : SerpApi result depth}
        {--location=Illinois, United States : SerpApi location string}
        {--markdown : Save report to storage/app/reports/competitor-rank-gap.md}';

    protected $description = 'Find queries where configured competitors outrank gs.construction (SerpApi-based).';

    public function handle(): int
    {
        $apiKey = (string) config('services.serpapi.api_key', '');
        if ($apiKey === '') {
            $this->error('SERPAPI_API_KEY is not set.');
            return self::FAILURE;
        }

        $competitors = collect(config('competitors.competitors', []))
            ->map(function (array $c) {
                $host = (string) parse_url((string) ($c['website'] ?? ''), PHP_URL_HOST);
                return [
                    'slug' => (string) $c['slug'],
                    'name' => (string) $c['name'],
                    'host' => $this->normalizeHost($host),
                ];
            })
            ->filter(fn ($c) => $c['host'] !== '')
            ->values();

        if ($competitors->isEmpty()) {
            $this->error('No competitors configured in config/competitors.php.');
            return self::FAILURE;
        }

        $queries = $this->buildSeedQueries();
        $cap = max(1, (int) $this->option('max-queries'));
        if (count($queries) > $cap) {
            $queries = array_slice($queries, 0, $cap);
        }

        $this->info('Running ' . count($queries) . ' SerpApi searches against ' . $competitors->count() . ' competitors.');

        $top = (int) $this->option('top');
        $location = (string) $this->option('location');
        $ourHost = $this->normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'gs.construction');

        $perQuery = [];
        $perCompetitor = []; // slug => [ ahead => n, tied => n, total => n ]
        foreach ($competitors as $c) {
            $perCompetitor[$c['slug']] = ['ahead' => 0, 'behind' => 0, 'absent_us' => 0, 'name' => $c['name']];
        }

        foreach ($queries as $q) {
            $results = $this->fetchSerp($apiKey, $q, $location, $top);
            if ($results === null) {
                $this->warn("  SerpApi failed for: {$q}");
                continue;
            }

            $ourPos = null;
            $compPos = []; // slug => position
            foreach ($results as $i => $r) {
                $host = $this->normalizeHost((string) ($r['host'] ?? ''));
                $pos = $i + 1;
                if ($host === $ourHost && $ourPos === null) {
                    $ourPos = $pos;
                    continue;
                }
                foreach ($competitors as $c) {
                    if ($host === $c['host'] && ! isset($compPos[$c['slug']])) {
                        $compPos[$c['slug']] = $pos;
                    }
                }
            }

            foreach ($compPos as $slug => $cp) {
                if ($ourPos === null) {
                    $perCompetitor[$slug]['absent_us']++;
                } elseif ($cp < $ourPos) {
                    $perCompetitor[$slug]['ahead']++;
                } else {
                    $perCompetitor[$slug]['behind']++;
                }
            }

            $perQuery[] = [
                'query' => $q,
                'our_pos' => $ourPos ?? 'not in top ' . $top,
                'competitors' => $compPos,
            ];

            // be gentle on the API
            usleep(250000);
        }

        $this->renderPerQuery($perQuery, $competitors->pluck('slug')->all());
        $this->renderPerCompetitor($perCompetitor);

        if ($this->option('markdown')) {
            $this->saveMarkdown($perQuery, $perCompetitor, $competitors->all());
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function buildSeedQueries(): array
    {
        $services = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('services')),
        )));

        $serviceTerms = array_map(fn ($s) => str_replace('-', ' ', $s), $services);

        $citiesRaw = trim((string) $this->option('cities'));
        if ($citiesRaw !== '') {
            $cities = array_values(array_filter(array_map('trim', explode(',', $citiesRaw))));
        } else {
            $cities = AreaServed::query()
                ->orderBy('id')
                ->limit((int) $this->option('max-cities'))
                ->pluck('city')
                ->all();
        }

        $queries = [];
        foreach ($cities as $city) {
            foreach ($serviceTerms as $svc) {
                $queries[] = strtolower("{$city} {$svc}");
            }
        }
        return array_values(array_unique($queries));
    }

    /**
     * @return array<int, array{title:string,link:string,host:string}>|null
     */
    protected function fetchSerp(string $apiKey, string $query, string $location, int $top): ?array
    {
        try {
            $resp = Http::timeout(40)->get('https://serpapi.com/search.json', [
                'engine' => 'google',
                'q' => $query,
                'hl' => 'en',
                'gl' => 'us',
                'location' => $location,
                'num' => max(10, $top),
                'api_key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }
        $json = $resp->json();
        if (! empty($json['error'])) {
            return null;
        }

        $out = [];
        foreach (($json['organic_results'] ?? []) as $row) {
            $link = (string) ($row['link'] ?? '');
            $host = $link !== '' ? (string) parse_url($link, PHP_URL_HOST) : '';
            if ($host === '') {
                continue;
            }
            $out[] = ['title' => (string) ($row['title'] ?? ''), 'link' => $link, 'host' => $host];
            if (count($out) >= $top) {
                break;
            }
        }
        return $out;
    }

    protected function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $slugs
     */
    protected function renderPerQuery(array $rows, array $slugs): void
    {
        $this->newLine();
        $this->line('<fg=cyan>--- Per-query positions ---</>');
        if (empty($rows)) {
            $this->line('  (no rows)');
            return;
        }
        $headers = array_merge(['Query', 'Us'], array_map(fn ($s) => Str::limit($s, 14), $slugs));
        $table = [];
        foreach ($rows as $r) {
            $cells = [Str::limit($r['query'], 38), (string) $r['our_pos']];
            foreach ($slugs as $s) {
                $cells[] = isset($r['competitors'][$s]) ? '#' . $r['competitors'][$s] : '—';
            }
            $table[] = $cells;
        }
        $this->table($headers, $table);
    }

    /**
     * @param array<string, array{ahead:int,behind:int,absent_us:int,name:string}> $perCompetitor
     */
    protected function renderPerCompetitor(array $perCompetitor): void
    {
        $this->newLine();
        $this->line('<fg=cyan>--- Per-competitor summary ---</>');
        $rows = [];
        foreach ($perCompetitor as $slug => $stats) {
            $gap = $stats['ahead'] + $stats['absent_us'];
            $rows[] = [
                $stats['name'],
                $slug,
                $stats['ahead'],
                $stats['behind'],
                $stats['absent_us'],
                $gap,
            ];
        }
        usort($rows, fn ($a, $b) => $b[5] <=> $a[5]);
        $this->table(
            ['Competitor', 'Slug', 'Outranks us', 'We outrank', 'Ranks (we don\'t)', 'Gap score'],
            $rows
        );
    }

    /**
     * @param array<int, array<string, mixed>> $perQuery
     * @param array<string, array<string, mixed>> $perCompetitor
     * @param array<int, array<string, mixed>> $competitors
     */
    protected function saveMarkdown(array $perQuery, array $perCompetitor, array $competitors): void
    {
        $md = "# Competitor rank-gap report\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= "_Gap score = (queries where they outrank us) + (queries where they rank and we don't)._\n\n";

        $md .= "## Per-competitor summary\n\n";
        $md .= "| Competitor | Outranks us | We outrank | They rank, we don't | Gap score |\n|---|---:|---:|---:|---:|\n";
        $sorted = $perCompetitor;
        uasort($sorted, fn ($a, $b) => ($b['ahead'] + $b['absent_us']) <=> ($a['ahead'] + $a['absent_us']));
        foreach ($sorted as $s) {
            $md .= sprintf(
                "| %s | %d | %d | %d | %d |\n",
                $s['name'],
                $s['ahead'],
                $s['behind'],
                $s['absent_us'],
                $s['ahead'] + $s['absent_us'],
            );
        }

        $md .= "\n## Per-query positions\n\n";
        $slugs = array_keys($perCompetitor);
        $md .= '| Query | Us | ' . implode(' | ', $slugs) . " |\n";
        $md .= '|---|---:|' . str_repeat('---:|', count($slugs)) . "\n";
        foreach ($perQuery as $r) {
            $cells = [str_replace('|', '\\|', $r['query']), (string) $r['our_pos']];
            foreach ($slugs as $s) {
                $cells[] = isset($r['competitors'][$s]) ? '#' . $r['competitors'][$s] : '—';
            }
            $md .= '| ' . implode(' | ', $cells) . " |\n";
        }

        $md .= "\n## Action\n\n";
        $md .= "Focus on queries above where _Us_ is blank but multiple competitors rank — those are clean acquisition targets. ";
        $md .= "For queries where one competitor consistently outranks us, study their on-page structure (do not copy text) and run `php artisan seo:competitor-gap --queries=\"the query\"`.\n";

        Storage::disk('local')->put('reports/competitor-rank-gap.md', $md);
        $this->info('Saved: storage/app/reports/competitor-rank-gap.md');
    }
}
