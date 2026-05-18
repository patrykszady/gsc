<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Audit per-area landing pages for "thin" content and near-duplicates.
 *
 * For each area in the DB, fetches a configurable set of variants
 * (home, contact, about, projects, testimonials, services pillar pages)
 * and reports:
 *
 *   - Word count of the main content (under <main> if present, else <body>)
 *   - Near-duplicate clusters within the SAME variant (e.g., all the
 *     /areas-served/{area}/services/kitchen-remodeling pages whose text
 *     differs only by city name) using a Jaccard similarity on word
 *     shingles. Areas in the same cluster share >= --sim ratio.
 *
 * Read-only. One HTTP request per URL. Use --sample to limit cost.
 */
class SeoAreaPagesAudit extends Command
{
    protected $signature = 'seo:area-pages-audit
        {--sample=10 : Number of areas to sample (0 = all)}
        {--variants=home,contact,services-kitchen,services-bathroom,services-home : CSV of page variants per area}
        {--thin=350 : Word-count threshold considered "thin"}
        {--sim=0.85 : Jaccard threshold for near-duplicate clustering (0..1)}
        {--shingle=5 : N-gram size for similarity}
        {--markdown : Save markdown report to storage/app/reports/area-pages-audit.md}';

    protected $description = 'Audit per-area landing pages for thin content and near-duplicates.';

    public function handle(): int
    {
        $sample = max(0, (int) $this->option('sample'));
        $thinThreshold = max(50, (int) $this->option('thin'));
        $sim = max(0.0, min(1.0, (float) $this->option('sim')));
        $shingle = max(2, (int) $this->option('shingle'));

        $variants = array_filter(array_map('trim', explode(',', (string) $this->option('variants'))));
        if (empty($variants)) {
            $this->error('No variants specified.');
            return self::FAILURE;
        }

        $areas = AreaServed::query()->orderBy('slug')->get(['id', 'slug', 'city']);
        if ($sample > 0 && $areas->count() > $sample) {
            $areas = $areas->random($sample)->values();
        }

        $this->info('Auditing ' . $areas->count() . ' areas × ' . count($variants) . ' variants = '
            . ($areas->count() * count($variants)) . ' URLs.');

        $base = rtrim((string) config('app.url'), '/');
        // Per-variant: [area_slug => ['url'=>..., 'words'=>int, 'text'=>string]]
        $byVariant = [];
        $fetchFails = [];

        foreach ($areas as $area) {
            foreach ($variants as $variant) {
                $path = $this->variantPath($area->slug, $variant);
                if ($path === null) {
                    continue;
                }
                $url = $base . $path;
                $text = $this->fetchMainText($url);
                if ($text === null) {
                    $fetchFails[] = $url;
                    continue;
                }
                $words = str_word_count($text);
                $byVariant[$variant][$area->slug] = [
                    'url' => $url,
                    'city' => $area->city,
                    'words' => $words,
                    'text' => $text,
                ];
                $this->line(sprintf('  %s [%d words] %s', $variant, $words, $url));
            }
        }

        // Thin pages
        $thin = [];
        foreach ($byVariant as $variant => $rows) {
            foreach ($rows as $slug => $r) {
                if ($r['words'] < $thinThreshold) {
                    $thin[] = ['variant' => $variant, 'slug' => $slug, 'city' => $r['city'], 'url' => $r['url'], 'words' => $r['words']];
                }
            }
        }

        // Duplicate clusters per variant
        $clusters = []; // ['variant' => [[slug1, slug2, ...], ...]]
        foreach ($byVariant as $variant => $rows) {
            $slugs = array_keys($rows);
            $shingles = [];
            foreach ($slugs as $s) {
                // Normalize: strip the city name itself so we don't penalize unique city words.
                $normalized = $this->normalize($rows[$s]['text'], $rows[$s]['city']);
                $shingles[$s] = $this->shingleSet($normalized, $shingle);
            }
            // Union-find clustering: edge if jaccard >= $sim
            $parent = array_combine($slugs, $slugs);
            $find = function ($x) use (&$parent, &$find) {
                while ($parent[$x] !== $x) {
                    $parent[$x] = $parent[$parent[$x]];
                    $x = $parent[$x];
                }
                return $x;
            };
            $union = function ($a, $b) use (&$parent, $find) {
                $ra = $find($a); $rb = $find($b);
                if ($ra !== $rb) { $parent[$ra] = $rb; }
            };
            $n = count($slugs);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $j_score = $this->jaccard($shingles[$slugs[$i]], $shingles[$slugs[$j]]);
                    if ($j_score >= $sim) {
                        $union($slugs[$i], $slugs[$j]);
                    }
                }
            }
            $groups = [];
            foreach ($slugs as $s) {
                $groups[$find($s)][] = $s;
            }
            foreach ($groups as $members) {
                if (count($members) >= 2) {
                    $clusters[$variant][] = $members;
                }
            }
        }

        $this->renderSummary($byVariant, $thin, $clusters, $fetchFails);

        if ($this->option('markdown')) {
            $this->saveMarkdown($byVariant, $thin, $clusters, $fetchFails, $thinThreshold, $sim);
        }

        return self::SUCCESS;
    }

    protected function variantPath(string $areaSlug, string $variant): ?string
    {
        return match ($variant) {
            'home' => "/areas-served/{$areaSlug}",
            'contact' => "/areas-served/{$areaSlug}/contact",
            'about' => "/areas-served/{$areaSlug}/about",
            'projects' => "/areas-served/{$areaSlug}/projects",
            'testimonials' => "/areas-served/{$areaSlug}/testimonials",
            'services' => "/areas-served/{$areaSlug}/services",
            'services-kitchen' => "/areas-served/{$areaSlug}/services/kitchen-remodeling",
            'services-bathroom' => "/areas-served/{$areaSlug}/services/bathroom-remodeling",
            'services-home' => "/areas-served/{$areaSlug}/services/home-remodeling",
            default => null,
        };
    }

    protected function fetchMainText(string $url): ?string
    {
        try {
            $resp = Http::timeout(20)->withHeaders(['User-Agent' => 'GS-SEO-Audit/1.0'])->get($url);
        } catch (ConnectionException) {
            return null;
        }
        if (! $resp->successful()) {
            return null;
        }
        $html = (string) $resp->body();

        // Extract <main>...</main> if present; fall back to <body>.
        if (preg_match('#<main\b[^>]*>(.*?)</main>#is', $html, $m)) {
            $inner = $m[1];
        } elseif (preg_match('#<body\b[^>]*>(.*?)</body>#is', $html, $m)) {
            $inner = $m[1];
        } else {
            $inner = $html;
        }

        // Strip scripts/styles/templates.
        $inner = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $inner);
        $text = trim(html_entity_decode(strip_tags((string) $inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text);
        return $text === '' ? null : $text;
    }

    protected function normalize(string $text, ?string $city): string
    {
        $t = mb_strtolower($text);
        if ($city) {
            $t = str_replace(mb_strtolower($city), 'CITY', $t);
        }
        return $t;
    }

    /** @return array<string,true> */
    protected function shingleSet(string $text, int $n): array
    {
        $tokens = preg_split('/[^a-z0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < $n) {
            return [implode(' ', $tokens) => true];
        }
        $out = [];
        for ($i = 0, $end = count($tokens) - $n; $i <= $end; $i++) {
            $out[implode(' ', array_slice($tokens, $i, $n))] = true;
        }
        return $out;
    }

    protected function jaccard(array $a, array $b): float
    {
        if (empty($a) && empty($b)) return 1.0;
        $inter = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }

    protected function renderSummary(array $byVariant, array $thin, array $clusters, array $fetchFails): void
    {
        $this->newLine();
        $this->info('=== Summary ===');
        foreach ($byVariant as $variant => $rows) {
            $words = array_column($rows, 'words');
            $min = $words ? min($words) : 0;
            $max = $words ? max($words) : 0;
            $avg = $words ? (int) round(array_sum($words) / count($words)) : 0;
            $this->line(sprintf('  %s — %d pages, words min/avg/max = %d / %d / %d',
                $variant, count($rows), $min, $avg, $max));
        }

        $this->newLine();
        $this->warn('Thin pages: ' . count($thin));
        foreach (array_slice($thin, 0, 20) as $t) {
            $this->line("  [{$t['words']}w] {$t['url']}");
        }

        $this->newLine();
        $totalDup = array_sum(array_map('count', $clusters));
        $this->warn("Near-duplicate clusters: {$totalDup}");
        foreach ($clusters as $variant => $groups) {
            foreach ($groups as $i => $members) {
                $this->line('  ' . $variant . ' cluster #' . ($i + 1) . ' (' . count($members) . ' areas): '
                    . implode(', ', array_slice($members, 0, 6))
                    . (count($members) > 6 ? ' …' : ''));
            }
        }

        if (! empty($fetchFails)) {
            $this->newLine();
            $this->error('Fetch failures: ' . count($fetchFails));
            foreach (array_slice($fetchFails, 0, 10) as $u) {
                $this->line('  ' . $u);
            }
        }
    }

    protected function saveMarkdown(array $byVariant, array $thin, array $clusters, array $fetchFails, int $thinThreshold, float $sim): void
    {
        $now = now()->toIso8601String();
        $md = "# Area-pages audit\n\nRun: {$now}\n\n";
        $md .= "Thin threshold: {$thinThreshold} words · Similarity threshold: {$sim}\n\n";

        $md .= "## Variant overview\n\n| Variant | Pages | Min | Avg | Max |\n|---|---:|---:|---:|---:|\n";
        foreach ($byVariant as $variant => $rows) {
            $w = array_column($rows, 'words');
            $min = $w ? min($w) : 0;
            $max = $w ? max($w) : 0;
            $avg = $w ? (int) round(array_sum($w) / count($w)) : 0;
            $md .= "| {$variant} | " . count($rows) . " | {$min} | {$avg} | {$max} |\n";
        }

        $md .= "\n## Thin pages (" . count($thin) . ")\n\n";
        if (empty($thin)) {
            $md .= "_None below {$thinThreshold} words._\n";
        } else {
            $md .= "| Words | Variant | URL |\n|---:|---|---|\n";
            foreach ($thin as $t) {
                $md .= "| {$t['words']} | {$t['variant']} | {$t['url']} |\n";
            }
        }

        $md .= "\n## Near-duplicate clusters\n\n";
        $any = false;
        foreach ($clusters as $variant => $groups) {
            if (empty($groups)) continue;
            $any = true;
            $md .= "### {$variant}\n\n";
            foreach ($groups as $i => $members) {
                $md .= '- Cluster ' . ($i + 1) . ' (' . count($members) . ' areas): ' . implode(', ', $members) . "\n";
            }
            $md .= "\n";
        }
        if (! $any) {
            $md .= "_No clusters detected above similarity ≥ {$sim}._\n";
        }

        if (! empty($fetchFails)) {
            $md .= "\n## Fetch failures (" . count($fetchFails) . ")\n\n";
            foreach ($fetchFails as $u) {
                $md .= "- {$u}\n";
            }
        }

        Storage::disk('local')->put('reports/area-pages-audit.md', $md);
        $this->info('Saved: storage/app/private/reports/area-pages-audit.md');
    }
}
