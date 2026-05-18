<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Internal-link audit. Reads sitemap.xml, fetches every URL, parses internal <a href>s,
 * and reports:
 *   - Orphan pages (0 inbound internal links from other pages in the sitemap).
 *   - Weak pages (< MIN inbound links).
 *   - Per-URL inbound count (top + bottom).
 *
 * Useful for surfacing high-value pages (e.g. /services/kitchen-remodeling)
 * that aren't getting linked from enough other places.
 *
 * Run sparingly: makes one HTTP request per URL in the sitemap.
 */
class SeoInternalLinkAudit extends Command
{
    protected $signature = 'seo:internal-link-audit
        {--sitemap= : Sitemap URL (defaults to APP_URL/sitemap.xml)}
        {--min=3 : Minimum inbound internal links before a page is flagged "weak"}
        {--limit=300 : Max URLs to crawl from sitemap}
        {--json : Output JSON}
        {--orphans-only : Only show orphans (0 inbound)}
        {--include-feeds : Include AI/feed endpoints (llms.txt, /geo/*, /ai-*, sitemap, etc.) in the audit}';

    protected $description = 'Crawl sitemap and report orphan / weakly-linked internal pages.';

    public function handle(): int
    {
        $base = rtrim((string) (config('app.url') ?: 'https://gs.construction'), '/');
        $sitemapUrl = (string) ($this->option('sitemap') ?: $base . '/sitemap.xml');
        $min = (int) $this->option('min');

        $this->info("Reading sitemap: {$sitemapUrl}");

        $resp = Http::timeout(15)->get($sitemapUrl);
        if (! $resp->ok()) {
            $this->error('Sitemap fetch failed: HTTP ' . $resp->status());
            return self::FAILURE;
        }

        $urls = collect($this->extractLocs($resp->body()))
            ->filter(fn ($u) => Str::startsWith($u, $base))
            ->reject(fn ($u) => ! $this->option('include-feeds') && $this->isAiOrFeedUrl($u))
            ->unique()
            ->take((int) $this->option('limit'))
            ->values();

        if ($urls->isEmpty()) {
            $this->error('No URLs found in sitemap.');
            return self::FAILURE;
        }

        $this->info("Crawling {$urls->count()} URLs…");
        $bar = $this->output->createProgressBar($urls->count());

        $inbound = $urls->mapWithKeys(fn ($u) => [$this->normalize($u, $base) => 0])->all();
        $outboundCounts = [];

        foreach ($urls as $url) {
            try {
                $html = Http::timeout(15)->withHeaders(['User-Agent' => 'GSC-SeoAudit/1.0'])->get($url)->body();
            } catch (\Throwable) {
                $bar->advance();
                continue;
            }

            $crawler = new Crawler($html);
            $hrefs = collect($crawler->filter('a[href]')->each(fn ($n) => $n->attr('href')))
                ->filter()
                ->map(fn ($h) => $this->resolve($h, $url, $base))
                ->filter(fn ($h) => $h && Str::startsWith($h, $base))
                ->unique()
                ->reject(fn ($h) => $this->normalize($h, $base) === $this->normalize($url, $base)) // ignore self-links
                ->values();

            $outboundCounts[$this->normalize($url, $base)] = $hrefs->count();
            foreach ($hrefs as $href) {
                $key = $this->normalize($href, $base);
                if (array_key_exists($key, $inbound)) {
                    $inbound[$key]++;
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $rows = collect($inbound)
            ->map(fn ($cnt, $url) => [
                'url'       => $url,
                'inbound'   => $cnt,
                'outbound'  => $outboundCounts[$url] ?? 0,
            ])
            ->sortBy('inbound')
            ->values();

        if ($this->option('orphans-only')) {
            $rows = $rows->filter(fn ($r) => $r['inbound'] === 0)->values();
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $orphans = $rows->where('inbound', 0)->count();
        $weak    = $rows->where('inbound', '<', $min)->count();

        $this->table(
            ['URL (path)', 'Inbound', 'Outbound'],
            $rows->take(60)->map(fn ($r) => [
                Str::limit(parse_url($r['url'], PHP_URL_PATH) ?: $r['url'], 70),
                $r['inbound'],
                $r['outbound'],
            ])->all()
        );

        $this->newLine();
        $this->warn("Orphans (0 inbound): {$orphans}");
        $this->warn("Weak (< {$min} inbound): {$weak}");

        return self::SUCCESS;
    }

    private function normalize(string $url, string $base): string
    {
        $url = strtok($url, '#');
        $url = strtok($url, '?');
        return rtrim($url, '/');
    }

    /**
     * AI/feed endpoints are intentionally in the sitemap for crawlers but never
     * linked from regular pages — they should not be reported as orphans.
     */
    private function isAiOrFeedUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        return (bool) preg_match('#^/(llms|geo|ai-|sitemap|feed)#i', $path)
            || str_ends_with($path, '.txt')
            || str_ends_with($path, '.json')
            || str_ends_with($path, '.xml');
    }

    /**
     * Extract <loc> values from sitemap XML, handling default namespaces
     * (the standard sitemap schema uses xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
     * which makes Symfony DomCrawler's filter('loc') return zero matches).
     * Also follows sitemap-index files recursively.
     *
     * @return string[]
     */
    private function extractLocs(string $body, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            return [];
        }

        $locs = [];

        // Sitemap index → recurse into each child sitemap.
        if ($xml->getName() === 'sitemapindex') {
            foreach ($xml->sitemap as $entry) {
                $childUrl = trim((string) $entry->loc);
                if ($childUrl === '') {
                    continue;
                }
                try {
                    $child = Http::timeout(15)->get($childUrl);
                    if ($child->ok()) {
                        $locs = array_merge($locs, $this->extractLocs($child->body(), $depth + 1));
                    }
                } catch (\Throwable) {
                    // skip
                }
            }
            return $locs;
        }

        // Standard urlset.
        foreach ($xml->url as $u) {
            $loc = trim((string) $u->loc);
            if ($loc !== '') {
                $locs[] = $loc;
            }
        }

        return $locs;
    }

    private function resolve(string $href, string $base, string $appBase): ?string
    {
        $href = trim($href);
        if ($href === '' || Str::startsWith($href, ['mailto:', 'tel:', 'javascript:', '#'])) return null;
        if (Str::startsWith($href, '//')) return 'https:' . $href;
        if (Str::startsWith($href, ['http://', 'https://'])) return $href;
        if (Str::startsWith($href, '/')) return $appBase . $href;
        // Relative — resolve against base page URL.
        return rtrim(dirname($base), '/') . '/' . ltrim($href, '/');
    }
}
