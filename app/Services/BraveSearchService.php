<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Web-search results via the Brave Search API, used by the competitor and
 * backlink discovery commands.
 *
 * Results are normalized to the `organic_results` shape the command parsers
 * expect ([['position','title','link','snippet'], …]). Note Brave's index is
 * not Google's: positions are a proxy for visibility, fine for
 * discovery/mention monitoring, not an exact Google rank. Free tier allows
 * ~1 request/second — respect it with a retry.
 */
class BraveSearchService
{
    protected ?string $lastError = null;

    public function isConfigured(): bool
    {
        return (string) config('services.brave.api_key', '') !== '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Run a web search and return normalized organic results.
     *
     * @param  int  $count  Max results (Brave caps a single page at 20).
     * @param  int  $offset  Result offset for pagination (0, 20, 40 …).
     * @return array<int, array{position:int,title:string,link:string,snippet:string}>|null
     *         null on hard failure (missing key, HTTP error after retry).
     */
    public function organicResults(string $query, int $count = 20, int $offset = 0): ?array
    {
        if (! $this->isConfigured()) {
            $this->lastError = 'BRAVE_SEARCH_API_KEY is not set.';

            return null;
        }

        $params = [
            'q' => $query,
            'count' => max(1, min(20, $count)),
            'offset' => max(0, (int) floor($offset / 20)),
            'country' => 'us',
            'search_lang' => 'en',
        ];

        // Free tier is ~1 req/s: retry once on 429 after a polite pause.
        foreach ([0, 1200] as $delayMs) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $resp = Http::withHeaders([
                    'X-Subscription-Token' => (string) config('services.brave.api_key'),
                    'Accept' => 'application/json',
                ])->timeout(30)->get('https://api.search.brave.com/res/v1/web/search', $params);
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();

                continue;
            }

            if ($resp->status() === 429) {
                $this->lastError = 'Brave Search rate limit (429).';

                continue;
            }

            if (! $resp->successful()) {
                $this->lastError = 'Brave Search HTTP ' . $resp->status();
                Log::warning('BraveSearch: request failed', [
                    'status' => $resp->status(),
                    'query' => $query,
                    'body' => mb_substr($resp->body(), 0, 300),
                ]);

                return null;
            }

            $rows = (array) $resp->json('web.results', []);
            $out = [];
            foreach ($rows as $i => $row) {
                $link = (string) ($row['url'] ?? '');
                if ($link === '') {
                    continue;
                }
                $out[] = [
                    'position' => $offset + $i + 1,
                    'title' => (string) ($row['title'] ?? ''),
                    'link' => $link,
                    'snippet' => (string) ($row['description'] ?? ''),
                ];
            }

            $this->lastError = null;

            return $out;
        }

        return null;
    }
}
