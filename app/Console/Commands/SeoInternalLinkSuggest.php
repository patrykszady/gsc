<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Internal-link opportunity finder.
 *
 * For every URL in our sitemap, fetch the page, isolate the visible <main>
 * region (or body), and look for plain-text mentions of OTHER pages' target
 * keyword (derived from URL slug + H1) that are NOT already wrapped in any
 * <a> tag. Each match becomes a "from → to (anchor)" suggestion.
 *
 * Read-only; one HTTP request per URL. Designed to complement the existing
 * seo:internal-link-audit (which finds orphans) — this finds missed links
 * inside otherwise well-linked pages.
 */
class SeoInternalLinkSuggest extends Command
{
    protected $signature = 'seo:internal-link-suggest
        {--limit=80 : Max URLs to scan as sources}
        {--target-limit=60 : Max target pages to consider}
        {--min-anchor=4 : Minimum anchor-word length (single-word anchors discouraged)}
        {--max-per-page=5 : Cap suggestions per source page}
        {--markdown : Save report to storage/app/reports/internal-link-suggest.md}';

    protected $description = 'Suggest internal links where target-page keywords appear unlinked in other pages\' body copy.';

    public function handle(): int
    {
        $base = rtrim((string) config('app.url'), '/');
        $sitemap = $base . '/sitemap.xml';

        $urls = $this->readSitemap($sitemap);
        if (empty($urls)) {
            $this->error("No URLs from {$sitemap}");
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $targetLimit = max(1, (int) $this->option('target-limit'));

        // Build target index: URL => primary anchor phrase (longest reasonable phrase from H1 or slug)
        $this->info('Building target index from ' . min(count($urls), $targetLimit) . ' pages…');
        $targets = $this->buildTargets(array_slice($urls, 0, $targetLimit), $base);
        $this->line('  ' . count($targets) . ' targets indexed.');

        $sources = array_slice($urls, 0, $limit);
        $suggestions = []; // [source_url => [ ['target'=>..., 'anchor'=>..., 'excerpt'=>...] ]]

        foreach ($sources as $src) {
            $html = $this->fetch($src);
            if ($html === '') {
                continue;
            }
            $text = $this->extractText($html);
            if ($text === '') {
                continue;
            }
            // Strip everything already inside <a> so we don't suggest a link that already exists.
            $textOutsideLinks = preg_replace('#<a\b[^>]*>.*?</a>#is', ' ', $html);
            $textOutsideLinks = $this->extractText((string) $textOutsideLinks);

            foreach ($targets as $targetUrl => $anchor) {
                if ($targetUrl === $src) {
                    continue;
                }
                if (mb_strlen($anchor) < (int) $this->option('min-anchor')) {
                    continue;
                }
                $needle = preg_quote($anchor, '#');
                if (preg_match('#\\b' . $needle . '\\b#i', $textOutsideLinks, $m, PREG_OFFSET_CAPTURE)) {
                    $offset = $m[0][1];
                    $excerpt = trim(Str::limit(
                        mb_substr($textOutsideLinks, max(0, $offset - 40), 120),
                        110,
                    ));
                    $suggestions[$src][] = [
                        'target' => $targetUrl,
                        'anchor' => $anchor,
                        'excerpt' => $excerpt,
                    ];
                    if (count($suggestions[$src]) >= (int) $this->option('max-per-page')) {
                        break;
                    }
                }
            }
        }

        $total = array_sum(array_map('count', $suggestions));
        $this->newLine();
        $this->line("<fg=cyan>--- Suggestions: {$total} across " . count($suggestions) . ' pages ---</>');
        foreach (array_slice($suggestions, 0, 8, true) as $src => $list) {
            $this->line($src);
            foreach ($list as $s) {
                $this->line(sprintf('  → %s  [anchor: "%s"]', $s['target'], $s['anchor']));
            }
        }
        if (count($suggestions) > 8) {
            $this->line('  … +' . (count($suggestions) - 8) . ' more pages (see markdown report)');
        }

        if ($this->option('markdown')) {
            $this->saveMarkdown($suggestions);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, string> url => anchor phrase
     */
    protected function buildTargets(array $urls, string $base): array
    {
        $targets = [];
        foreach ($urls as $url) {
            $html = $this->fetch($url);
            $anchor = null;
            if ($html !== '' && preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
                $anchor = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
            }
            if ($anchor === null || $anchor === '') {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $slug = trim(basename($path), '/');
                $anchor = ucwords(str_replace('-', ' ', $slug));
            }
            // Trim to a short, link-worthy anchor (max 6 words).
            $words = preg_split('/\s+/', $anchor) ?: [];
            $anchor = trim(implode(' ', array_slice($words, 0, 6)));
            // Drop super-generic anchors that would create noise.
            if (in_array(strtolower($anchor), ['home', 'about', 'contact', 'services', 'projects', ''], true)) {
                continue;
            }
            $targets[$url] = $anchor;
        }
        return $targets;
    }

    protected function fetch(string $url): string
    {
        try {
            $r = Http::timeout(15)->withUserAgent('GSC-LinkSuggest/1.0')->get($url);
            return $r->successful() ? (string) $r->body() : '';
        } catch (\Throwable) {
            return '';
        }
    }

    protected function extractText(string $html): string
    {
        // Isolate <main>...</main> if present, else <body>.
        if (preg_match('#<main\b[^>]*>(.*?)</main>#is', $html, $m)) {
            $html = $m[1];
        } elseif (preg_match('#<body\b[^>]*>(.*?)</body>#is', $html, $m)) {
            $html = $m[1];
        }
        $html = (string) preg_replace('#<(script|style|noscript|nav|footer|header|svg)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<string, array<int, array<string, string>>> $suggestions
     */
    protected function saveMarkdown(array $suggestions): void
    {
        $md = "# Internal-link suggestions\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= "Each row: an unlinked plain-text mention of another page's anchor phrase. Add an `<a href=\"...\">` only where editorially natural.\n\n";
        foreach ($suggestions as $src => $list) {
            $md .= "## From: {$src}\n\n";
            $md .= "| Target | Anchor | Excerpt |\n|---|---|---|\n";
            foreach ($list as $s) {
                $md .= sprintf("| %s | %s | …%s… |\n",
                    $s['target'],
                    str_replace('|', '\\|', $s['anchor']),
                    str_replace(['|', "\n"], ['\\|', ' '], $s['excerpt']),
                );
            }
            $md .= "\n";
        }
        Storage::disk('local')->put('reports/internal-link-suggest.md', $md);
        $this->info('Saved: storage/app/reports/internal-link-suggest.md');
    }

    /**
     * @return array<int, string>
     */
    protected function readSitemap(string $sitemap): array
    {
        try {
            $body = Http::timeout(15)->get($sitemap)->throw()->body();
        } catch (\Throwable) {
            return [];
        }
        if (! preg_match_all('#<loc>(.*?)</loc>#i', $body, $m)) {
            return [];
        }
        $urls = array_values(array_unique(array_map('trim', $m[1])));
        return array_values(array_filter($urls, function (string $u): bool {
            $p = (string) parse_url($u, PHP_URL_PATH);
            return ! preg_match('#\.(txt|xml|json|csv|webmanifest|ico|png|jpe?g|webp|gif|svg|pdf|mp4|mp3)$#i', $p);
        }));
    }
}
