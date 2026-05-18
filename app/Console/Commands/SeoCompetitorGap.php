<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SeoCompetitorGap extends Command
{
    protected $signature = 'seo:competitor-gap
        {--queries= : Comma-separated queries. If omitted, uses default local service queries}
        {--top=5 : Number of top organic competitors per query}
        {--location=Illinois, United States : SerpApi location string}
        {--markdown : Save markdown report to storage/app/reports/competitor-gap.md}';

    protected $description = 'Analyze top SERP competitors for local remodeling queries and output compliant content/link gap briefs (no copied text).';

    /** @var string[] */
    protected array $signalPhrases = [
        'cost',
        'price',
        'budget',
        'timeline',
        'how long',
        'permit',
        'code',
        'warranty',
        'financing',
        'before and after',
        'process',
        'faq',
        'reviews',
    ];

    public function handle(): int
    {
        $apiKey = (string) config('services.serpapi.api_key', '');
        if ($apiKey === '') {
            $this->error('SERPAPI_API_KEY (or SERPAPI_KEY) is not set.');
            return self::FAILURE;
        }

        $top = max(1, (int) $this->option('top'));
        $location = trim((string) $this->option('location'));
        $queries = $this->resolveQueries();

        if (empty($queries)) {
            $this->warn('No queries to analyze.');
            return self::SUCCESS;
        }

        $areas = AreaServed::query()->get(['city', 'slug']);
        $reportRows = [];

        foreach ($queries as $query) {
            $results = $this->fetchSerp($apiKey, $query, $location, $top);
            if (empty($results)) {
                $reportRows[] = [
                    'query' => $query,
                    'target_url' => $this->inferTargetUrl($query, $areas),
                    'domains' => 'n/a',
                    'missing_signals' => 'n/a',
                    'action' => 'No SERP results returned; retry later.',
                ];
                continue;
            }

            $domains = [];
            $competitorSignals = [];
            foreach ($results as $item) {
                $host = (string) ($item['host'] ?? '');
                if ($host !== '') {
                    $domains[$host] = true;
                }

                $pageSignals = $this->extractSignalsFromResult($item);
                foreach ($pageSignals as $sig) {
                    $competitorSignals[$sig] = true;
                }
            }

            $targetUrl = $this->inferTargetUrl($query, $areas);
            $ourSignals = $this->extractSignalsFromOurPage($targetUrl);
            $missing = array_values(array_diff(array_keys($competitorSignals), $ourSignals));

            $action = empty($missing)
                ? 'Strengthen trust proof (photos, local proof, FAQs) and internal links to this page.'
                : 'Expand page with original sections for: ' . implode(', ', array_slice($missing, 0, 5));

            $reportRows[] = [
                'query' => $query,
                'target_url' => $targetUrl,
                'domains' => implode(', ', array_slice(array_keys($domains), 0, 4)),
                'missing_signals' => empty($missing) ? 'none' : implode(', ', array_slice($missing, 0, 6)),
                'action' => $action,
            ];
        }

        $this->table(
            ['Query', 'Target URL', 'Top Domains', 'Missing Signals', 'Recommended Action'],
            array_map(fn (array $r) => [
                mb_strimwidth($r['query'], 0, 45, '...'),
                mb_strimwidth($r['target_url'], 0, 38, '...'),
                mb_strimwidth($r['domains'], 0, 45, '...'),
                mb_strimwidth($r['missing_signals'], 0, 45, '...'),
                mb_strimwidth($r['action'], 0, 58, '...'),
            ], $reportRows)
        );

        $this->newLine();
        $this->line('Compliance note: This report extracts SEO signals only. Do not copy or AI-rewrite competitor text.');

        if ((bool) $this->option('markdown')) {
            $path = 'reports/competitor-gap.md';
            $content = $this->toMarkdown($reportRows);
            \Storage::disk('local')->put($path, $content);
            $this->info('Saved report: storage/app/' . $path);
        }

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    protected function resolveQueries(): array
    {
        $raw = trim((string) $this->option('queries'));
        if ($raw !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        return [
            'palatine kitchen remodeling',
            'palatine bathroom remodeling',
            'arlington heights kitchen remodeling',
            'arlington heights bathroom remodeling',
            'schaumburg kitchen remodeling',
            'mount prospect bathroom remodeling',
        ];
    }

    /**
     * @return array<int, array{title:string,link:string,snippet:string,host:string}>
     */
    protected function fetchSerp(string $apiKey, string $query, string $location, int $top): array
    {
        $response = Http::timeout(40)->get('https://serpapi.com/search.json', [
            'engine' => 'google',
            'q' => $query,
            'hl' => 'en',
            'gl' => 'us',
            'location' => $location,
            'num' => max(10, $top + 4),
            'api_key' => $apiKey,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();
        if (! empty($json['error'])) {
            return [];
        }

        $out = [];
        foreach (($json['organic_results'] ?? []) as $row) {
            $link = (string) ($row['link'] ?? '');
            $host = $link !== '' ? (string) parse_url($link, PHP_URL_HOST) : '';
            if ($host === '' || str_contains($host, 'gs.construction')) {
                continue;
            }

            $out[] = [
                'title' => (string) ($row['title'] ?? ''),
                'link' => $link,
                'snippet' => (string) ($row['snippet'] ?? ''),
                'host' => $host,
            ];

            if (count($out) >= $top) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array{title:string,link:string,snippet:string,host:string} $result
     * @return string[]
     */
    protected function extractSignalsFromResult(array $result): array
    {
        $signals = [];
        $text = mb_strtolower($result['title'] . ' ' . $result['snippet']);

        // Best-effort page fetch for heading signals (skip if blocked).
        try {
            $html = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36'])
                ->get($result['link'])
                ->body();

            if ($html !== '') {
                preg_match_all('/<h[12][^>]*>(.*?)<\/h[12]>/is', $html, $matches);
                if (! empty($matches[1])) {
                    $headings = implode(' ', array_map(fn ($h) => strip_tags((string) $h), $matches[1]));
                    $text .= ' ' . mb_strtolower($headings);
                }
            }
        } catch (\Throwable) {
            // Ignore fetch failures; snippet/title still gives usable intent signals.
        }

        foreach ($this->signalPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $signals[] = $phrase;
            }
        }

        return array_values(array_unique($signals));
    }

    /**
     * @return string[]
     */
    protected function extractSignalsFromOurPage(string $targetPath): array
    {
        if ($targetPath === '/' || ! str_starts_with($targetPath, '/')) {
            return [];
        }

        $url = rtrim((string) config('app.url'), '/') . $targetPath;

        try {
            $html = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36'])
                ->get($url)
                ->body();
        } catch (\Throwable) {
            return [];
        }

        if ($html === '') {
            return [];
        }

        preg_match_all('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $html, $matches);
        $text = mb_strtolower(strip_tags(implode(' ', $matches[1] ?? [])) . ' ' . strip_tags($html));

        $found = [];
        foreach ($this->signalPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $found[] = $phrase;
            }
        }

        return array_values(array_unique($found));
    }

    protected function inferTargetUrl(string $query, $areas): string
    {
        $queryLower = mb_strtolower($query);

        $service = match (true) {
            str_contains($queryLower, 'kitchen') => 'kitchen-remodeling',
            str_contains($queryLower, 'bathroom'), str_contains($queryLower, 'bath ') => 'bathroom-remodeling',
            str_contains($queryLower, 'home remodel'), str_contains($queryLower, 'general contractor') => 'home-remodeling',
            default => null,
        };

        $areaSlug = null;
        foreach ($areas as $area) {
            $city = mb_strtolower((string) $area->city);
            if (preg_match('/\\b' . preg_quote($city, '/') . '\\b/u', $queryLower)) {
                $areaSlug = (string) $area->slug;
                break;
            }
        }

        if ($areaSlug && $service) {
            return '/areas-served/' . $areaSlug . '/services/' . $service;
        }

        if ($areaSlug) {
            return '/areas-served/' . $areaSlug;
        }

        if ($service) {
            return '/services/' . $service;
        }

        return '/services';
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    protected function toMarkdown(array $rows): string
    {
        $lines = [];
        $lines[] = '# Competitor SERP Gap Report';
        $lines[] = '';
        $lines[] = '- Generated: ' . now()->toDateTimeString();
        $lines[] = '- Method: SERP + heading signal analysis only (no copied text)';
        $lines[] = '';
        $lines[] = '| Query | Target URL | Top Domains | Missing Signals | Recommended Action |';
        $lines[] = '|---|---|---|---|---|';

        foreach ($rows as $r) {
            $lines[] = '| '
                . str_replace('|', '\\|', $r['query']) . ' | '
                . str_replace('|', '\\|', $r['target_url']) . ' | '
                . str_replace('|', '\\|', $r['domains']) . ' | '
                . str_replace('|', '\\|', $r['missing_signals']) . ' | '
                . str_replace('|', '\\|', $r['action']) . ' |';
        }

        $lines[] = '';
        $lines[] = '## Usage';
        $lines[] = '1. Expand existing target pages with original sections for missing signals.';
        $lines[] = '2. Add internal links from related service/area pages.';
        $lines[] = '3. Use listed domains for ethical outreach/backlink prospecting.';

        return implode("\n", $lines) . "\n";
    }
}
