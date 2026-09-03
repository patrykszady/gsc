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
                $data += $this->parse((string) $response->body());
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

        $body = preg_replace('#<(head|script|style|noscript|svg|nav|header|footer|form)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $body = preg_replace('#<!--.*?-->#s', ' ', $body) ?? $body;
        // A space at every tag boundary, so "<h1>A</h1><p>B" does not fuse into "AB".
        $text = $this->clean(strip_tags(str_replace('>', '> ', $body)));

        return [
            'site_title' => $title ? Str::limit($title, 190, '') : null,
            'site_description' => $description ? Str::limit($description, 990, '') : null,
            'site_excerpt' => $text !== '' ? Str::limit($text, 1800, '') : null,
        ];
    }

    protected function clean(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
}
