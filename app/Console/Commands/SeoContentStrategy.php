<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\GscQueryMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SeoContentStrategy extends Command
{
    protected $signature = 'seo:content-strategy
        {--days=28 : Look-back window}
        {--limit=30 : Max opportunities to show}
        {--min-impressions=20 : Minimum impressions threshold}
        {--max-position=30 : Ignore terms ranking worse than this average position}
        {--country=usa : Country filter (blank = all)}
        {--markdown : Save markdown report to storage/app/reports/content-strategy.md}';

    protected $description = 'Build a prioritized content backlog from GSC query data (topic, target URL, format, and priority score).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $minImpressions = max(1, (int) $this->option('min-impressions'));
        $maxPosition = max(1, (float) $this->option('max-position'));
        $country = trim((string) $this->option('country'));

        $from = now()->subDays($days)->toDateString();

        $query = GscQueryMetric::query()
            ->where('date', '>=', $from)
            ->when($country !== '', fn ($q) => $q->where('country', $country))
            ->groupBy('query')
            ->select([
                'query',
                DB::raw('SUM(impressions) as imp'),
                DB::raw('SUM(clicks) as clk'),
                DB::raw('CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 1.0 / SUM(impressions) ELSE 0 END as ctr'),
                DB::raw('AVG(position) as pos'),
                DB::raw('MAX(page) as sample_page'),
            ])
            ->having('imp', '>=', $minImpressions)
            ->having('pos', '<=', $maxPosition)
            ->orderByDesc(DB::raw('SUM(impressions) * (1 - (SUM(clicks) * 1.0 / NULLIF(SUM(impressions), 0))) / GREATEST(AVG(position), 1)'));

        $rows = $query->limit($limit * 3)->get();

        if ($rows->isEmpty()) {
            $this->warn('No GSC opportunities found. Run seo:gsc-sync first or loosen thresholds.');
            return self::SUCCESS;
        }

        $areas = AreaServed::query()->get(['city', 'slug']);

        $opportunities = collect();
        foreach ($rows as $row) {
            $normalizedQuery = mb_strtolower((string) $row->query);
            $classification = $this->classifyQuery($normalizedQuery, $areas);

            $score = ((int) $row->imp) * (1 - (float) $row->ctr) / max((float) $row->pos, 1.0);
            $opportunities->push([
                'query' => (string) $row->query,
                'imp' => (int) $row->imp,
                'clk' => (int) $row->clk,
                'ctr' => round((float) $row->ctr * 100, 2),
                'pos' => round((float) $row->pos, 1),
                'score' => round($score, 2),
                'format' => $classification['format'],
                'angle' => $classification['angle'],
                'target_url' => $classification['target_url'] ?: $this->shortPath((string) $row->sample_page),
            ]);
        }

        $opportunities = $opportunities
            ->sortByDesc('score')
            ->unique(fn ($r) => mb_strtolower($r['query']))
            ->take($limit)
            ->values();

        $this->table(
            ['Query', 'Imp', 'CTR%', 'Pos', 'Score', 'Format', 'Target URL', 'Angle'],
            $opportunities->map(fn ($r) => [
                mb_strimwidth($r['query'], 0, 55, '...'),
                $r['imp'],
                $r['ctr'],
                $r['pos'],
                $r['score'],
                $r['format'],
                mb_strimwidth($r['target_url'], 0, 38, '...'),
                mb_strimwidth($r['angle'], 0, 36, '...'),
            ])->all()
        );

        $this->newLine();
        $this->info('Strategy: publish 2-3 pieces/week from top score down, then re-check with seo:gsc-top and seo:title-audit.');

        if ((bool) $this->option('markdown')) {
            $path = 'reports/content-strategy.md';
            Storage::disk('local')->put($path, $this->toMarkdown($opportunities, $days, $country));
            $this->line('Saved report: storage/app/' . $path);
        }

        return self::SUCCESS;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, AreaServed> $areas
     * @return array{format:string,angle:string,target_url:string}
     */
    protected function classifyQuery(string $query, $areas): array
    {
        $service = $this->serviceFromQuery($query);
        $area = $this->areaFromQuery($query, $areas);
        $format = $this->formatFromQuery($query);

        $targetUrl = '';
        if ($service && $area) {
            $targetUrl = '/areas-served/' . $area['slug'] . '/services/' . $service;
        } elseif ($service) {
            $targetUrl = '/services/' . $service;
        } elseif ($area) {
            $targetUrl = '/areas-served/' . $area['slug'];
        }

        $angle = $this->angleFromQuery($query, $service, $area);

        return [
            'format' => $format,
            'angle' => $angle,
            'target_url' => $targetUrl,
        ];
    }

    protected function serviceFromQuery(string $query): ?string
    {
        return match (true) {
            str_contains($query, 'kitchen') => 'kitchen-remodeling',
            str_contains($query, 'bathroom'), str_contains($query, 'bath ') => 'bathroom-remodeling',
            str_contains($query, 'home remodel'), str_contains($query, 'whole home'), str_contains($query, 'general contractor') => 'home-remodeling',
            default => null,
        };
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, AreaServed> $areas
     * @return array{city:string,slug:string}|null
     */
    protected function areaFromQuery(string $query, $areas): ?array
    {
        foreach ($areas as $area) {
            $city = mb_strtolower($area->city);
            if (preg_match('/\\b' . preg_quote($city, '/') . '\\b/u', $query)) {
                return ['city' => $area->city, 'slug' => $area->slug];
            }
        }

        return null;
    }

    protected function formatFromQuery(string $query): string
    {
        if (preg_match('/\b(cost|price|budget|average)\b/u', $query)) {
            return 'pricing-guide';
        }

        if (preg_match('/\b(permit|code|inspection|timeline|how long)\b/u', $query)) {
            return 'how-to-guide';
        }

        if (preg_match('/\b(best|top|near me|contractor|company)\b/u', $query)) {
            return 'comparison-page';
        }

        return 'service-page-expansion';
    }

    /**
     * @param array{city:string,slug:string}|null $area
     */
    protected function angleFromQuery(string $query, ?string $service, ?array $area): string
    {
        $serviceLabel = match ($service) {
            'kitchen-remodeling' => 'kitchen remodel',
            'bathroom-remodeling' => 'bathroom remodel',
            'home-remodeling' => 'home remodel',
            default => 'remodeling',
        };

        if ($area) {
            return ucfirst($serviceLabel) . ' in ' . $area['city'] . ' with local proof, process, and cost ranges';
        }

        if (str_contains($query, 'cost') || str_contains($query, 'price') || str_contains($query, 'budget')) {
            return ucfirst($serviceLabel) . ' cost breakdown with examples from real projects';
        }

        if (str_contains($query, 'permit') || str_contains($query, 'timeline')) {
            return ucfirst($serviceLabel) . ' permit + timeline checklist for Chicago suburbs';
        }

        return ucfirst($serviceLabel) . ' page refresh with FAQs, examples, and stronger internal links';
    }

    protected function shortPath(string $url): string
    {
        if ($url === '') {
            return '/';
        }

        $path = parse_url($url, PHP_URL_PATH);
        return $path ?: $url;
    }

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $opportunities
     */
    protected function toMarkdown($opportunities, int $days, string $country): string
    {
        $lines = [];
        $lines[] = '# Content Strategy Backlog';
        $lines[] = '';
        $lines[] = '- Generated: ' . now()->toDateTimeString();
        $lines[] = '- Look-back: ' . $days . ' days';
        $lines[] = '- Country: ' . ($country === '' ? 'all' : $country);
        $lines[] = '';
        $lines[] = '| Priority | Query | Score | Format | Target URL | Angle |';
        $lines[] = '|---:|---|---:|---|---|---|';

        foreach ($opportunities->values() as $idx => $row) {
            $lines[] = '| ' . ($idx + 1)
                . ' | ' . str_replace('|', '\\|', (string) $row['query'])
                . ' | ' . $row['score']
                . ' | ' . $row['format']
                . ' | ' . str_replace('|', '\\|', (string) $row['target_url'])
                . ' | ' . str_replace('|', '\\|', (string) $row['angle'])
                . ' |';
        }

        $lines[] = '';
        $lines[] = '## Weekly Workflow';
        $lines[] = '1. Publish the top 2-3 backlog items.';
        $lines[] = '2. Link new content from relevant service and area pages.';
        $lines[] = '3. Re-check impact with `php artisan seo:gsc-top --mode=opportunity` and `php artisan seo:title-audit`.';

        return implode("\n", $lines) . "\n";
    }
}
