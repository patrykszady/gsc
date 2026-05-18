<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Quick-wins on-page audit. Crawls the production sitemap (or a custom URL list)
 * and flags pages with common technical-SEO issues:
 *
 *  - Missing/duplicate <title>
 *  - Missing/short meta description
 *  - Missing canonical / canonical mismatch
 *  - Missing H1, multiple H1s
 *  - Missing OG image
 *  - Images with empty alt
 *  - Pages with no JSON-LD schema
 *  - Slow TTFB (> --slow-ttfb ms)
 *  - Oversize HTML (> --max-html-kb)
 *
 * Read-only: makes one HTTP request per URL with a small concurrency.
 */
class SeoAuditQuickwins extends Command
{
    protected $signature = 'seo:audit-quickwins
        {--sitemap= : Sitemap URL (defaults to APP_URL/sitemap.xml)}
        {--limit=50 : Max URLs to audit}
        {--slow-ttfb=800 : Flag pages whose TTFB exceeds N ms}
        {--max-html-kb=500 : Flag pages larger than N KB of HTML}
        {--min-desc=70 : Minimum meta description length}
        {--markdown : Save markdown report to storage/app/reports/audit-quickwins.md}';

    protected $description = 'Crawl sitemap URLs and report on-page SEO quick wins (titles, descriptions, canonicals, alts, schema, TTFB).';

    public function handle(): int
    {
        $sitemap = (string) ($this->option('sitemap') ?: rtrim(config('app.url'), '/') . '/sitemap.xml');
        $limit = max(1, (int) $this->option('limit'));
        $slowTtfb = max(1, (int) $this->option('slow-ttfb'));
        $maxHtmlKb = max(1, (int) $this->option('max-html-kb'));
        $minDesc = max(1, (int) $this->option('min-desc'));

        $urls = $this->readSitemap($sitemap);
        if (empty($urls)) {
            $this->warn("No URLs found in {$sitemap}");
            return self::FAILURE;
        }

        $urls = array_slice($urls, 0, $limit);
        $this->info('Auditing ' . count($urls) . " URLs from {$sitemap}");

        $titles = [];
        $rows = [];

        foreach ($urls as $url) {
            $rows[] = $this->auditUrl($url, $slowTtfb, $maxHtmlKb, $minDesc, $titles);
        }

        $issuesByType = [];
        foreach ($rows as $row) {
            foreach ($row['issues'] as $issue) {
                $issuesByType[$issue][] = $row['url'];
            }
        }

        // Duplicate-title pass
        foreach ($titles as $title => $list) {
            if (count($list) > 1) {
                foreach ($list as $u) {
                    $issuesByType['Duplicate <title>: "' . Str::limit($title, 60) . '"'][] = $u;
                }
            }
        }

        $this->newLine();
        if (empty($issuesByType)) {
            $this->info('No quick-win issues found.');
        } else {
            $this->line('<fg=cyan>--- Issues found ---</>');
            foreach ($issuesByType as $issue => $list) {
                $this->line(sprintf('• %s — %d page(s)', $issue, count($list)));
                foreach (array_slice($list, 0, 3) as $u) {
                    $this->line('    ' . $u);
                }
            }
        }

        if ($this->option('markdown')) {
            $this->saveMarkdown($rows, $issuesByType);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function readSitemap(string $sitemap): array
    {
        try {
            $body = Http::timeout(15)->get($sitemap)->throw()->body();
        } catch (\Throwable $e) {
            $this->error('Sitemap fetch failed: ' . $e->getMessage());
            return [];
        }

        if (preg_match_all('#<loc>(.*?)</loc>#i', $body, $m)) {
            return array_values(array_unique(array_map('trim', $m[1])));
        }
        return [];
    }

    /**
     * @param array<string, array<int, string>> &$titles
     * @return array<string, mixed>
     */
    protected function auditUrl(string $url, int $slowTtfb, int $maxHtmlKb, int $minDesc, array &$titles): array
    {
        $issues = [];
        $start = microtime(true);
        try {
            $resp = Http::timeout(20)
                ->withUserAgent('GSC-QuickwinsAudit/1.0')
                ->get($url);
        } catch (ConnectionException $e) {
            $this->warn("  fetch failed: {$url}");
            return ['url' => $url, 'issues' => ['Fetch failed: ' . $e->getMessage()]];
        }
        $ttfbMs = (int) ((microtime(true) - $start) * 1000);

        if (! $resp->successful()) {
            return ['url' => $url, 'issues' => ['HTTP ' . $resp->status()]];
        }

        $html = $resp->body();
        $kb = (int) round(strlen($html) / 1024);

        if ($ttfbMs > $slowTtfb) {
            $issues[] = "Slow response ({$ttfbMs} ms)";
        }
        if ($kb > $maxHtmlKb) {
            $issues[] = "Large HTML ({$kb} KB)";
        }

        // <title>
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $tm)) {
            $title = trim(html_entity_decode(strip_tags($tm[1])));
            if ($title === '') {
                $issues[] = 'Missing <title>';
            } elseif (mb_strlen($title) > 65) {
                $issues[] = 'Long <title> (' . mb_strlen($title) . ' chars)';
            }
            $titles[$title][] = $url;
        } else {
            $issues[] = 'Missing <title>';
        }

        // Meta description
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $dm)) {
            $desc = trim($dm[1]);
            if ($desc === '') {
                $issues[] = 'Empty meta description';
            } elseif (mb_strlen($desc) < $minDesc) {
                $issues[] = 'Short meta description (' . mb_strlen($desc) . ' chars)';
            }
        } else {
            $issues[] = 'Missing meta description';
        }

        // Canonical
        if (preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']#i', $html, $cm)) {
            $canonical = trim($cm[1]);
            // Allow trailing-slash difference and query-stripping; compare host+path.
            $cParts = parse_url($canonical);
            $uParts = parse_url($url);
            if (($cParts['host'] ?? null) !== ($uParts['host'] ?? null)) {
                $issues[] = 'Canonical host mismatch (' . ($cParts['host'] ?? '?') . ')';
            } elseif (rtrim($cParts['path'] ?? '/', '/') !== rtrim($uParts['path'] ?? '/', '/')) {
                $issues[] = 'Canonical path mismatch (' . ($cParts['path'] ?? '/') . ')';
            }
        } else {
            $issues[] = 'Missing canonical';
        }

        // H1
        $h1Count = preg_match_all('#<h1[\s>]#i', $html);
        if ($h1Count === 0) {
            $issues[] = 'Missing H1';
        } elseif ($h1Count > 1) {
            $issues[] = "Multiple H1s ({$h1Count})";
        }

        // OG image
        if (! preg_match('#<meta[^>]+property=["\']og:image["\']#i', $html)) {
            $issues[] = 'Missing og:image';
        }

        // JSON-LD presence
        if (! preg_match('#<script[^>]+type=["\']application/ld\+json["\']#i', $html)) {
            $issues[] = 'No JSON-LD schema';
        }

        // Empty alt
        $emptyAlts = preg_match_all('#<img\b[^>]*\balt=("|\')\1#i', $html);
        $altMissing = preg_match_all('#<img\b(?:(?!alt=)[^>])*>#i', $html);
        $totalAltIssues = $emptyAlts + $altMissing;
        if ($totalAltIssues > 0) {
            $issues[] = "{$totalAltIssues} images with empty/missing alt";
        }

        return ['url' => $url, 'issues' => $issues, 'ttfb' => $ttfbMs, 'kb' => $kb];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<int, string>> $issuesByType
     */
    protected function saveMarkdown(array $rows, array $issuesByType): void
    {
        $md = "# Quick-wins SEO audit\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= '## Summary\n\n';
        if (empty($issuesByType)) {
            $md .= "_No issues found._\n";
        } else {
            $md .= "| Issue | Pages |\n|---|---:|\n";
            foreach ($issuesByType as $issue => $list) {
                $md .= '| ' . str_replace('|', '\\|', $issue) . ' | ' . count($list) . " |\n";
            }
        }

        $md .= "\n## Per-page detail\n\n";
        foreach ($rows as $r) {
            if (empty($r['issues'])) {
                continue;
            }
            $md .= '### ' . $r['url'] . "\n\n";
            foreach ($r['issues'] as $i) {
                $md .= "- {$i}\n";
            }
            if (isset($r['ttfb'])) {
                $md .= sprintf("- TTFB %d ms · %d KB\n", $r['ttfb'], $r['kb'] ?? 0);
            }
            $md .= "\n";
        }

        Storage::disk('local')->put('reports/audit-quickwins.md', $md);
        $this->info('Saved: storage/app/reports/audit-quickwins.md');
    }
}
