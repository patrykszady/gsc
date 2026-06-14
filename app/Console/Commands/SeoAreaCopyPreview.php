<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Services\SeoService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeoAreaCopyPreview extends Command
{
    protected $signature = 'seo:area-copy-preview
        {--slug= : Limit to one area slug}
        {--limit=20 : Max number of areas in table mode}
        {--section=both : home|services|both}
        {--service= : Service slug when --section=services (kitchen-remodeling|bathroom-remodeling|home-remodeling|basement-remodeling|home-additions)}
        {--check-duplicates : Analyze generated copy for duplicate-risk across areas}
        {--dup-threshold=0.90 : Similarity threshold (0..1) for duplicate-risk detection}
        {--json : Output full payload as JSON}';

    protected $description = 'Preview generated SEO copy for all area home/service pages to QA consistency and uniqueness.';

    public function handle(): int
    {
        $section = (string) $this->option('section');
        if (! in_array($section, ['home', 'services', 'both'], true)) {
            $this->error('Invalid --section. Use: home|services|both');
            return self::FAILURE;
        }

        $areas = AreaServed::query()
            ->when($this->option('slug'), fn ($q) => $q->where('slug', (string) $this->option('slug')))
            ->orderBy('city')
            ->get();

        if ($areas->isEmpty()) {
            $this->warn('No matching areas found.');
            return self::SUCCESS;
        }

        $serviceSlugs = $this->resolveServiceSlugs();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'areas' => [],
        ];

        foreach ($areas as $area) {
            $entry = [
                'city' => $area->city,
                'slug' => $area->slug,
                'url' => $area->url,
            ];

            if ($section === 'home' || $section === 'both') {
                $homeMeta = SeoService::buildAreaHomeMeta($area);
                $variant = abs(crc32((string) $area->slug)) % 3;
                $headings = [
                    $area->city . ' Home Remodeling Contractor',
                    $area->city . ' Remodeling Contractor for Kitchen, Bath & Home',
                    $area->city . ' Kitchen, Bathroom & Home Renovation Team',
                ];
                $subheadings = [
                    'Professional remodeling services for ' . $area->city . ' homeowners.',
                    'Family-run remodeling for kitchens, bathrooms, and whole-home projects in ' . $area->city . '.',
                    'Plan and build your next remodel in ' . $area->city . ' with clear scope, timeline, and pricing.',
                ];

                $entry['home'] = [
                    'title' => $homeMeta['title'],
                    'description' => $homeMeta['description'],
                    'heading' => $headings[$variant],
                    'subheading' => $subheadings[$variant],
                ];
            }

            if ($section === 'services' || $section === 'both') {
                $entry['services'] = [];
                foreach ($serviceSlugs as $serviceSlug) {
                    $meta = SeoService::buildAreaServiceMeta($area, $serviceSlug);
                    $entry['services'][$serviceSlug] = [
                        'title' => $meta['title'],
                        'description' => $meta['description'],
                        'heading' => $area->city . ' ' . ($meta['service']['label'] ?? Str::headline(str_replace('-', ' ', $serviceSlug))),
                        'url' => $area->serviceUrl($serviceSlug),
                    ];
                }
            }

            $payload['areas'][] = $entry;
        }

        $duplicateReport = null;
        if ((bool) $this->option('check-duplicates')) {
            $threshold = (float) $this->option('dup-threshold');
            if ($threshold <= 0 || $threshold > 1) {
                $this->warn('Invalid --dup-threshold; using 0.90.');
                $threshold = 0.90;
            }
            $duplicateReport = $this->buildDuplicateReport($payload['areas'], $section, $serviceSlugs, $threshold);
            $payload['duplicate_report'] = $duplicateReport;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $slice = collect($payload['areas'])->take($limit);

        if ($section === 'home' || $section === 'both') {
            $this->newLine();
            $this->info('Area Home Copy Preview');
            $rows = $slice->map(fn (array $a) => [
                $a['city'],
                $a['slug'],
                Str::limit((string) ($a['home']['title'] ?? ''), 65),
                Str::limit((string) ($a['home']['heading'] ?? ''), 65),
                Str::limit((string) ($a['home']['description'] ?? ''), 90),
            ])->all();
            $this->table(['City', 'Slug', 'Title', 'Heading', 'Description'], $rows);
        }

        if ($section === 'services' || $section === 'both') {
            $this->newLine();
            $this->info('Area Service Copy Preview');
            $rows = [];
            foreach ($slice as $a) {
                foreach ($serviceSlugs as $serviceSlug) {
                    $service = $a['services'][$serviceSlug] ?? null;
                    if (! is_array($service)) {
                        continue;
                    }
                    $rows[] = [
                        $a['city'],
                        $serviceSlug,
                        Str::limit((string) ($service['title'] ?? ''), 65),
                        Str::limit((string) ($service['heading'] ?? ''), 55),
                    ];
                }
            }
            $this->table(['City', 'Service', 'Title', 'Heading'], $rows);
        }

        if ($duplicateReport !== null) {
            $this->newLine();
            $this->info('Duplicate-Risk Report');
            $this->line('Threshold: ' . number_format((float) $duplicateReport['threshold'], 2));
            $this->line('Pairs flagged: ' . (int) $duplicateReport['flagged_pairs']);

            $topRows = collect($duplicateReport['pairs'] ?? [])->take(20)->map(fn (array $r) => [
                $r['section'],
                $r['field'],
                $r['a_slug'],
                $r['b_slug'],
                number_format((float) $r['similarity'], 3),
                Str::limit((string) $r['a_text'], 68),
            ])->all();

            if (empty($topRows)) {
                $this->line('No duplicate-risk pairs above threshold.');
            } else {
                $this->table(['Section', 'Field', 'A', 'B', 'Similarity', 'Sample'], $topRows);
            }
        }

        $this->line('Tip: use --json for full descriptions and URLs.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveServiceSlugs(): array
    {
        $requested = trim((string) $this->option('service'));
        $valid = [
            'kitchen-remodeling',
            'bathroom-remodeling',
            'home-remodeling',
            'basement-remodeling',
            'home-additions',
        ];

        if ($requested === '') {
            return $valid;
        }

        if (! in_array($requested, $valid, true)) {
            $this->warn('Unknown --service value. Falling back to all services.');
            return $valid;
        }

        return [$requested];
    }

    /**
     * @param array<int, array<string, mixed>> $areas
     * @param array<int, string> $serviceSlugs
     * @return array{threshold:float, flagged_pairs:int, pairs:array<int, array<string, mixed>>}
     */
    protected function buildDuplicateReport(array $areas, string $section, array $serviceSlugs, float $threshold): array
    {
        $pairs = [];

        $cityTokenMap = [];
        foreach ($areas as $a) {
            $slug = (string) ($a['slug'] ?? '');
            $city = strtolower((string) ($a['city'] ?? ''));
            $tokens = array_filter(preg_split('/[^a-z0-9]+/i', $city) ?: [], fn ($t) => $t !== '');
            $tokens[] = strtolower($slug);
            $slugParts = array_filter(explode('-', strtolower($slug)), fn ($t) => $t !== '');
            $cityTokenMap[$slug] = array_values(array_unique(array_merge($tokens, $slugParts)));
        }

        if ($section === 'home' || $section === 'both') {
            $pairs = array_merge($pairs, $this->compareEntryPairs($areas, 'home', 'title', $cityTokenMap, $threshold));
            $pairs = array_merge($pairs, $this->compareEntryPairs($areas, 'home', 'description', $cityTokenMap, $threshold));
        }

        if ($section === 'services' || $section === 'both') {
            foreach ($serviceSlugs as $serviceSlug) {
                $pairs = array_merge($pairs, $this->compareEntryPairs($areas, "services.{$serviceSlug}", 'title', $cityTokenMap, $threshold));
                $pairs = array_merge($pairs, $this->compareEntryPairs($areas, "services.{$serviceSlug}", 'description', $cityTokenMap, $threshold));
            }
        }

        usort($pairs, fn (array $a, array $b) => ($b['similarity'] <=> $a['similarity']) ?: strcmp($a['a_slug'], $b['a_slug']));

        return [
            'threshold' => $threshold,
            'flagged_pairs' => count($pairs),
            'pairs' => $pairs,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $areas
     * @param array<string, array<int, string>> $cityTokenMap
     * @return array<int, array<string, mixed>>
     */
    protected function compareEntryPairs(array $areas, string $path, string $field, array $cityTokenMap, float $threshold): array
    {
        $out = [];
        $count = count($areas);

        for ($i = 0; $i < $count; $i++) {
            $a = $areas[$i];
            $aSlug = (string) ($a['slug'] ?? '');
            $aText = (string) data_get($a, "{$path}.{$field}", '');
            if ($aText === '') {
                continue;
            }
            $aNorm = $this->normalizeForSimilarity($aText, $cityTokenMap[$aSlug] ?? []);

            for ($j = $i + 1; $j < $count; $j++) {
                $b = $areas[$j];
                $bSlug = (string) ($b['slug'] ?? '');
                $bText = (string) data_get($b, "{$path}.{$field}", '');
                if ($bText === '') {
                    continue;
                }
                $bNorm = $this->normalizeForSimilarity($bText, $cityTokenMap[$bSlug] ?? []);

                $similarity = $this->jaccardSimilarity($aNorm, $bNorm);
                if ($similarity >= $threshold) {
                    $out[] = [
                        'section' => $path,
                        'field' => $field,
                        'a_slug' => $aSlug,
                        'b_slug' => $bSlug,
                        'similarity' => round($similarity, 4),
                        'a_text' => $aText,
                        'b_text' => $bText,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, string> $cityTokens
     */
    protected function normalizeForSimilarity(string $text, array $cityTokens): string
    {
        $s = strtolower($text);
        foreach ($cityTokens as $token) {
            $token = trim(strtolower($token));
            if ($token === '' || strlen($token) < 2) {
                continue;
            }
            $s = preg_replace('/\\b' . preg_quote($token, '/') . '\\b/i', ' {city} ', $s) ?? $s;
        }

        $s = preg_replace('/\b\d+\+?\b/u', ' {num} ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9{} ]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s);

        return $s;
    }

    protected function jaccardSimilarity(string $a, string $b): float
    {
        $aTokens = array_values(array_filter(explode(' ', $a), fn ($t) => $t !== ''));
        $bTokens = array_values(array_filter(explode(' ', $b), fn ($t) => $t !== ''));

        if (empty($aTokens) || empty($bTokens)) {
            return 0.0;
        }

        $aSet = array_fill_keys($aTokens, true);
        $bSet = array_fill_keys($bTokens, true);

        $intersection = count(array_intersect_key($aSet, $bSet));
        $union = count($aSet) + count($bSet) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }
}
