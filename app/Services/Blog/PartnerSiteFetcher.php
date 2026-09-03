<?php

namespace App\Services\Blog;

use App\Models\ProjectCollaborator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads a partner's homepage so the blog writer knows what they do — title,
 * meta description and the first stretch of visible text. Context only: the
 * writer is told to describe them in its own words, never to copy.
 *
 * Always stamps site_fetched_at, even on failure, so a dead site is not
 * re-fetched on every save.
 */
class PartnerSiteFetcher
{
    public function fetch(ProjectCollaborator $collaborator): void
    {
        $url = trim((string) $collaborator->url);
        if ($url === '') {
            return;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $data = ['site_fetched_at' => now()];

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; GSConstructionBlogBot/1.0; +' . url('/') . ')'])
                ->get($url);

            if ($response->successful()) {
                $html = (string) $response->body();
                $data += $this->parse($html);

                // Script-rendered homepages often carry almost no text; the
                // services / about pages usually do. Follow up to three.
                $extra = [];
                foreach ($this->followUpLinks($html, $url) as $link) {
                    try {
                        $sub = Http::timeout(8)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; GSConstructionBlogBot/1.0)'])->get($link);
                        if ($sub->successful()) {
                            $text = $this->parse((string) $sub->body())['site_excerpt'] ?? '';
                            if ($text !== '') {
                                $extra[] = $text;
                            }
                        }
                    } catch (\Throwable) {
                        // one page failing is fine
                    }
                }
                if ($extra) {
                    $data['site_excerpt'] = Str::limit(trim(($data['site_excerpt'] ?? '') . "\n" . implode("\n", $extra)), 6000, '');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Partner site fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
        }

        $collaborator->forceFill($data)->save();
    }

    /** @return array{site_title: ?string, site_description: ?string, site_excerpt: ?string} */
    public function parse(string $html): array
    {
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? $this->clean($m[1]) : null;
        $description = preg_match('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m)
            || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description["\']#i', $html, $m)
            ? $this->clean($m[1]) : null;

        // Headings first: on a sparse page they are the services list.
        preg_match_all('#<h[1-4][^>]*>(.*?)</h[1-4]>#is', $html, $hm);
        $headings = collect($hm[1] ?? [])->map(fn ($h) => $this->clean(strip_tags($h)))->filter()->unique()->take(40)->implode(' · ');

        $body = preg_replace('#<(head|script|style|noscript|svg|nav|header|footer|form)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $body = preg_replace('#<!--.*?-->#s', ' ', $body) ?? $body;
        // A space at every tag boundary, so "<h1>A</h1><p>B" does not fuse into "AB".
        $text = $this->clean(strip_tags(str_replace('>', '> ', $body)));
        if ($headings !== '') {
            $text = trim($headings . ' — ' . $text);
        }

        return [
            'site_title' => $title ? Str::limit($title, 190, '') : null,
            'site_description' => $description ? Str::limit($description, 990, '') : null,
            'site_excerpt' => $text !== '' ? Str::limit($text, 1800, '') : null,
        ];
    }

    /**
     * Internal links worth reading for services: /services, /about,
     * /what-we-do, /design, /process, /portfolio … at most three.
     *
     * @return array<int, string>
     */
    public function followUpLinks(string $html, string $baseUrl): array
    {
        preg_match_all('~<a[^>]+href=["\']([^"\'#]+)["\']~i', $html, $m);
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $links = [];
        foreach ($m[1] ?? [] as $href) {
            $href = html_entity_decode($href);
            if (str_starts_with($href, '/')) {
                $href = "{$scheme}://{$host}{$href}";
            }
            $h = parse_url($href, PHP_URL_HOST);
            $path = strtolower((string) parse_url($href, PHP_URL_PATH));
            if (! $h || preg_replace('/^www\./', '', $h) !== preg_replace('/^www\./', '', (string) $host) || $path === '' || $path === '/') {
                continue;
            }
            if (preg_match('#services|about|what-we-do|design|process|portfolio|work|kitchen|bath|remodel#', $path)) {
                $links[preg_replace('#/$#', '', strtolower($href))] = $href;
            }
        }

        return array_values(array_slice($links, 0, 3));
    }

    protected function clean(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        // "View fullsize View fullsize View fullsize …" — gallery pages repeat
        // one UI label per image; keep a single copy.
        return preg_replace('/\b((?:\w+ ){0,3}\w+)(?:\s+\1\b){2,}/u', '$1', $s) ?? $s;
    }
}
