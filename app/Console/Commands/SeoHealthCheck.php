<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Local SEO health-check: per-URL composite score (0–100) covering
 * the on-page essentials. Single HTTP request per URL; no external APIs.
 *
 * Score breakdown (max 100):
 *   - Title length 30–60        : 15
 *   - Meta description 70–160   : 15
 *   - Exactly one <h1>          : 10
 *   - Image alt coverage ≥ 90%  : 15
 *   - Internal-link count 3–40  : 10
 *   - JSON-LD present           : 15
 *   - Word count > 300          : 10
 *   - <link rel="canonical">    : 10
 */
class SeoHealthCheck extends Command
{
    protected $signature = 'seo:health-check
        {--sitemap= : Sitemap URL (defaults to APP_URL/sitemap.xml)}
        {--urls= : CSV of explicit URLs (overrides --sitemap)}
        {--limit=60 : Max URLs}
        {--min-score=80 : Fail (non-zero exit) if any URL scores below this}
        {--markdown : Save markdown report to storage/app/reports/health-check.md}';

    protected $description = 'Composite per-URL Local SEO health-check (0–100 score).';

    public function handle(): int
    {
        $urls = $this->resolveUrls();
        if (empty($urls)) {
            $this->error('No URLs to audit.');
            return self::FAILURE;
        }

        $minScore = max(0, (int) $this->option('min-score'));
        $appUrl = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        $rows = [];

        foreach ($urls as $url) {
            $row = $this->scoreUrl($url, $appUrl);
            $rows[] = $row;
            $this->line(sprintf('  [%3d] %s', $row['score'], $url));
        }

        // Sort worst first
        usort($rows, fn ($a, $b) => $a['score'] <=> $b['score']);

        $this->renderSummary($rows);

        if ($this->option('markdown')) {
            $this->saveMarkdown($rows);
        }

        $worst = $rows[0]['score'] ?? 100;
        return $worst < $minScore ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int,string> */
    protected function resolveUrls(): array
    {
        $limit = max(1, (int) $this->option('limit'));
        if ($urls = $this->option('urls')) {
            return array_slice(array_filter(array_map('trim', explode(',', (string) $urls))), 0, $limit);
        }
        $sitemap = (string) ($this->option('sitemap') ?: rtrim((string) config('app.url'), '/') . '/sitemap.xml');
        try {
            $resp = Http::timeout(15)->get($sitemap);
        } catch (ConnectionException) {
            return [];
        }
        if (! $resp->successful()) {
            return [];
        }
        $body = $resp->body();
        if (preg_match_all('#<loc>([^<]+)</loc>#i', $body, $m)) {
            $urls = array_map('trim', $m[1]);
            // Skip non-HTML endpoints (LLM feeds, JSON-LD, sitemaps, robots, etc.)
            $urls = array_values(array_filter($urls, fn ($u) => $this->looksLikeHtml($u)));
            return array_slice($urls, 0, $limit);
        }
        return [];
    }

    protected function looksLikeHtml(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '' || $path === '/') return true;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $skip = ['json', 'xml', 'txt', 'csv', 'rss', 'atom', 'pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'ico', 'css', 'js', 'map', 'webmanifest'];
        return ! in_array($ext, $skip, true);
    }

    /**
     * @return array{url:string, score:int, breakdown:array<string,array{ok:bool,note:string}>}
     */
    protected function scoreUrl(string $url, string $appHost): array
    {
        $breakdown = [];
        $score = 0;

        try {
            $resp = Http::timeout(20)->withHeaders(['User-Agent' => 'GS-SEO-Health/1.0'])->get($url);
        } catch (ConnectionException) {
            return [
                'url' => $url,
                'score' => 0,
                'breakdown' => ['fetch' => ['ok' => false, 'note' => 'connection failed']],
            ];
        }
        if (! $resp->successful()) {
            return [
                'url' => $url,
                'score' => 0,
                'breakdown' => ['fetch' => ['ok' => false, 'note' => "HTTP {$resp->status()}"]],
            ];
        }

        $html = (string) $resp->body();

        // Title
        preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m);
        $title = trim(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $tl = mb_strlen($title);
        $ok = $tl >= 30 && $tl <= 60;
        if ($ok) $score += 15;
        $breakdown['title'] = ['ok' => $ok, 'note' => "{$tl} chars" . ($ok ? '' : ' (target 30–60)')];

        // Meta description
        preg_match('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m);
        $desc = trim($m[1] ?? '');
        $dl = mb_strlen($desc);
        $ok = $dl >= 70 && $dl <= 160;
        if ($ok) $score += 15;
        $breakdown['description'] = ['ok' => $ok, 'note' => "{$dl} chars" . ($ok ? '' : ' (target 70–160)')];

        // H1 count
        $h1Count = preg_match_all('#<h1\b[^>]*>#i', $html);
        $ok = $h1Count === 1;
        if ($ok) $score += 10;
        $breakdown['h1'] = ['ok' => $ok, 'note' => "{$h1Count} H1" . ($ok ? '' : ' (want exactly 1)')];

        // Image alt coverage
        $imgs = preg_match_all('#<img\b[^>]*>#i', $html, $im) ? $im[0] : [];
        $imgTotal = count($imgs);
        $withAlt = 0;
        foreach ($imgs as $tag) {
            // alt="..." with non-empty content
            if (preg_match('#\balt=["\']([^"\']*)["\']#i', $tag, $am) && trim($am[1]) !== '') {
                $withAlt++;
            }
        }
        $coverage = $imgTotal > 0 ? $withAlt / $imgTotal : 1.0;
        $ok = $coverage >= 0.9;
        if ($ok) $score += 15;
        $breakdown['image_alt'] = [
            'ok' => $ok,
            'note' => $imgTotal === 0 ? 'no images' : sprintf('%d/%d (%d%%)', $withAlt, $imgTotal, (int) round($coverage * 100)),
        ];

        // Internal links
        $links = preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\']#i', $html, $lm) ? $lm[1] : [];
        $internal = 0;
        foreach ($links as $href) {
            if ($href === '' || $href[0] === '#') continue;
            if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
                $internal++;
                continue;
            }
            if ($appHost && stripos($href, $appHost) !== false) {
                $internal++;
            }
        }
        $ok = $internal >= 3 && $internal <= 40;
        if ($ok) $score += 10;
        $breakdown['internal_links'] = ['ok' => $ok, 'note' => "{$internal} internal" . ($ok ? '' : ' (target 3–40)')];

        // JSON-LD
        $jsonLdCount = preg_match_all('#<script[^>]+type=["\']application/ld\+json["\']#i', $html);
        $ok = $jsonLdCount > 0;
        if ($ok) $score += 15;
        $breakdown['jsonld'] = ['ok' => $ok, 'note' => "{$jsonLdCount} block(s)" . ($ok ? '' : ' (missing)')];

        // Word count (body text)
        $bodyText = '';
        if (preg_match('#<body\b[^>]*>(.*?)</body>#is', $html, $bm)) {
            $bodyText = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $bm[1]) ?? '';
            $bodyText = trim((string) preg_replace('/\s+/u', ' ', strip_tags($bodyText)));
        }
        $words = str_word_count($bodyText);
        $ok = $words > 300;
        if ($ok) $score += 10;
        $breakdown['word_count'] = ['ok' => $ok, 'note' => "{$words} words" . ($ok ? '' : ' (want > 300)')];

        // Canonical
        $ok = (bool) preg_match('#<link[^>]+rel=["\']canonical["\']#i', $html);
        if ($ok) $score += 10;
        $breakdown['canonical'] = ['ok' => $ok, 'note' => $ok ? 'present' : 'missing'];

        return ['url' => $url, 'score' => $score, 'breakdown' => $breakdown];
    }

    protected function renderSummary(array $rows): void
    {
        $this->newLine();
        $this->info('=== Health-check summary ===');
        $avg = $rows ? (int) round(array_sum(array_column($rows, 'score')) / count($rows)) : 0;
        $this->line('Average score: ' . $avg . ' / 100');

        $this->newLine();
        $this->line('Worst 10:');
        $headers = ['Score', 'URL', 'Failing checks'];
        $tbl = [];
        foreach (array_slice($rows, 0, 10) as $r) {
            $failed = collect($r['breakdown'])
                ->filter(fn ($v) => ! $v['ok'])
                ->map(fn ($v, $k) => "{$k}: {$v['note']}")
                ->values()
                ->implode('; ');
            $tbl[] = [$r['score'], $r['url'], $failed ?: '—'];
        }
        $this->table($headers, $tbl);
    }

    protected function saveMarkdown(array $rows): void
    {
        $now = now()->toIso8601String();
        $avg = $rows ? (int) round(array_sum(array_column($rows, 'score')) / count($rows)) : 0;
        $md = "# Local SEO health-check\n\nRun: {$now}\n\nAverage score: **{$avg} / 100** across " . count($rows) . " URLs.\n\n";
        $md .= "## Per-URL scores (worst first)\n\n";
        $md .= "| Score | URL | Failing checks |\n|---:|---|---|\n";
        foreach ($rows as $r) {
            $failed = collect($r['breakdown'])
                ->filter(fn ($v) => ! $v['ok'])
                ->map(fn ($v, $k) => "**{$k}** ({$v['note']})")
                ->values()
                ->implode('; ');
            $md .= "| {$r['score']} | {$r['url']} | " . ($failed ?: '_all checks passed_') . " |\n";
        }

        Storage::disk('local')->put('reports/health-check.md', $md);
        $this->info('Saved: storage/app/private/reports/health-check.md');
    }
}
