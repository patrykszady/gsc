<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DataForSEO SERP client — the real-observation half of seo:track-rankings.
 *
 * Exists because the previous "rank tracker" never looked at a search result:
 * it re-averaged our own gsc_query_metrics rows, so a flood of new
 * deep-position impressions read as a ranking collapse (the exact false alarm
 * the Aug 2026 click-drop investigation spent a verification round unwinding).
 *
 * Uses the Live Advanced endpoint: one synchronous POST per query, ~$0.002
 * per check — the 31 tracked queries weekly cost about a quarter per month.
 */
class DataForSeoService
{
    private const BASE = 'https://api.dataforseo.com/v3';

    protected ?string $lastError = null;

    public function isConfigured(): bool
    {
        return (string) config('services.dataforseo.login') !== ''
            && (string) config('services.dataforseo.password') !== '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** Money spent by the research calls in this process, from the tasks' own cost field. */
    protected float $spent = 0.0;

    public function spent(): float
    {
        return round($this->spent, 4);
    }

    /** Account balance in USD, or null when the call fails. */
    public function balance(): ?float
    {
        $data = $this->call('GET', '/appendix/user_data');
        $balance = $data['tasks'][0]['result'][0]['money']['balance'] ?? null;

        return is_numeric($balance) ? (float) $balance : null;
    }

    /**
     * Google Ads search volume for up to 1,000 keywords (one task, ~$0.08).
     *
     * @return array<string, array{volume:int,cpc:?float,competition:?float}> keyed by keyword (lowercase)
     */
    public function searchVolume(array $keywords, string $locationName = 'Illinois,United States'): array
    {
        $keywords = array_values(array_unique(array_filter(array_map(fn ($k) => mb_strtolower(trim((string) $k)), $keywords))));
        if ($keywords === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($keywords, 1000) as $chunk) {
            $data = $this->call('POST', '/keywords_data/google_ads/search_volume/live', [[
                'keywords' => $chunk,
                'location_name' => $locationName,
                'language_code' => 'en',
            ]]);
            foreach ((array) ($data['tasks'][0]['result'] ?? []) as $row) {
                $k = mb_strtolower((string) ($row['keyword'] ?? ''));
                if ($k === '') {
                    continue;
                }
                $out[$k] = [
                    'volume' => (int) ($row['search_volume'] ?? 0),
                    'cpc' => is_numeric($row['cpc'] ?? null) ? (float) $row['cpc'] : null,
                    'competition' => is_numeric($row['competition_index'] ?? null) ? (float) $row['competition_index'] / 100 : (is_numeric($row['competition'] ?? null) ? (float) $row['competition'] : null),
                ];
            }
        }

        return $out;
    }

    /**
     * Keywords a competitor domain ranks for in Google (DataForSEO Labs,
     * ~$0.01 + rows). Filtered to remodeling-ish terms client-side.
     *
     * @return array<int, array{keyword:string,volume:int,position:int,difficulty:?int,url:?string}>
     */
    public function rankedKeywords(string $domain, int $limit = 300, string $locationName = 'Illinois,United States'): array
    {
        $data = $this->call('POST', '/dataforseo_labs/google/ranked_keywords/live', [[
            'target' => $domain,
            'location_name' => $locationName,
            'language_code' => 'en',
            'limit' => $limit,
            'order_by' => ['keyword_data.keyword_info.search_volume,desc'],
            'filters' => [['keyword_data.keyword_info.search_volume', '>', 0]],
        ]]);
        $items = (array) ($data['tasks'][0]['result'][0]['items'] ?? []);
        $out = [];
        foreach ($items as $it) {
            $kd = $it['keyword_data'] ?? [];
            $keyword = mb_strtolower((string) ($kd['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }
            $out[] = [
                'keyword' => $keyword,
                'volume' => (int) ($kd['keyword_info']['search_volume'] ?? 0),
                'position' => (int) ($it['ranked_serp_element']['serp_item']['rank_absolute'] ?? $it['ranked_serp_element']['serp_item']['rank_group'] ?? 0),
                'difficulty' => is_numeric($kd['keyword_properties']['keyword_difficulty'] ?? null) ? (int) $kd['keyword_properties']['keyword_difficulty'] : null,
                'url' => $it['ranked_serp_element']['serp_item']['url'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Keyword ideas around seed phrases (DataForSEO Labs).
     *
     * @return array<int, array{keyword:string,volume:int,difficulty:?int}>
     */
    public function keywordIdeas(array $seeds, int $limit = 200, string $locationName = 'Illinois,United States'): array
    {
        $seeds = array_values(array_filter(array_map('trim', $seeds)));
        if ($seeds === []) {
            return [];
        }
        $data = $this->call('POST', '/dataforseo_labs/google/keyword_ideas/live', [[
            'keywords' => array_slice($seeds, 0, 200),
            'location_name' => $locationName,
            'language_code' => 'en',
            'limit' => $limit,
            'order_by' => ['keyword_info.search_volume,desc'],
        ]]);
        $out = [];
        foreach ((array) ($data['tasks'][0]['result'][0]['items'] ?? []) as $it) {
            $keyword = mb_strtolower((string) ($it['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }
            $out[] = [
                'keyword' => $keyword,
                'volume' => (int) ($it['keyword_info']['search_volume'] ?? 0),
                'difficulty' => is_numeric($it['keyword_properties']['keyword_difficulty'] ?? null) ? (int) $it['keyword_properties']['keyword_difficulty'] : null,
            ];
        }

        return $out;
    }

    /** One authenticated call; records the task cost; returns decoded JSON or [] on failure. */
    protected function call(string $method, string $path, array $body = []): array
    {
        try {
            $req = Http::withBasicAuth((string) config('services.dataforseo.login'), (string) config('services.dataforseo.password'))
                ->timeout(120)->retry(2, 1500, throw: false);
            $resp = $method === 'GET' ? $req->get(self::BASE . $path) : $req->post(self::BASE . $path, $body);
        } catch (\Throwable $e) {
            $this->lastError = 'request failed: ' . mb_substr($e->getMessage(), 0, 160);

            return [];
        }
        if (! $resp->successful()) {
            $this->lastError = 'HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 200);

            return [];
        }
        $data = (array) $resp->json();
        foreach ((array) ($data['tasks'] ?? []) as $task) {
            $this->spent += (float) ($task['cost'] ?? 0);
            if (($task['status_code'] ?? 20000) !== 20000) {
                $this->lastError = ($task['status_code'] ?? '?') . ' ' . ($task['status_message'] ?? '');
            }
        }

        return $data;
    }

    /**
     * One live Google-organic SERP check.
     *
     * @return array{position: ?int, url: ?string, local_pack: ?bool, top_domains: array<int,string>}|null
     *         position = rank_absolute of the first result whose domain matches;
     *         local_pack = whether a local pack was present on the SERP (null if undetectable);
     *         null return = the API call itself failed.
     */
    public function googleOrganicPosition(string $query, string $targetDomain, string $locationName = 'Chicago,Illinois,United States'): ?array
    {
        try {
            $resp = Http::withBasicAuth(
                (string) config('services.dataforseo.login'),
                (string) config('services.dataforseo.password'),
            )->timeout(90)->retry(2, 1500, throw: false)
                ->post(self::BASE . '/serp/google/organic/live/advanced', [[
            'keyword' => $query,
            'location_name' => $locationName,
            'language_code' => 'en',
            'device' => 'desktop',
            'depth' => 100,
            ]]);
        } catch (\Throwable $e) {
            // A single slow SERP must never abort the whole weekly run — the
            // live endpoint occasionally exceeds a minute under load.
            $this->lastError = 'request failed: ' . mb_substr($e->getMessage(), 0, 160);

            return null;
        }

        if (! $resp->successful()) {
            $this->lastError = 'HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 200);

            return null;
        }

        $task = $resp->json('tasks.0');

        // 40101 "Internal SE Server Error" is DataForSEO's transient upstream
        // failure — 8 of 31 queries hit it on the first baseline sweep. One
        // in-place retry clears most of them; persistent ones stay null and
        // the next weekly run fills the hole.
        if (($task['status_code'] ?? 0) === 40101) {
            sleep(2);
            $retry = Http::withBasicAuth(
                (string) config('services.dataforseo.login'),
                (string) config('services.dataforseo.password'),
            )->timeout(90)->retry(2, 1500, throw: false)
                ->post(self::BASE . '/serp/google/organic/live/advanced', [[
                    'keyword' => $query,
                    'location_name' => $locationName,
                    'language_code' => 'en',
                    'device' => 'desktop',
                    'depth' => 100,
                ]]);
            if ($retry->successful()) {
                $task = $retry->json('tasks.0');
            }
        }

        if (($task['status_code'] ?? 0) !== 20000) {
            $this->lastError = ($task['status_code'] ?? '?') . ' ' . ($task['status_message'] ?? 'unknown');

            return null;
        }

        $items = $task['result'][0]['items'] ?? [];
        $position = null;
        $url = null;
        $localPack = false;
        $topDomains = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'local_pack' || $type === 'map') {
                $localPack = true;
            }
            if ($type !== 'organic') {
                continue;
            }
            $domain = (string) ($item['domain'] ?? '');
            if (count($topDomains) < 10) {
                $topDomains[] = $domain;
            }
            if ($position === null && str_contains($domain, $targetDomain)) {
                $position = (int) ($item['rank_absolute'] ?? 0) ?: null;
                $url = $item['url'] ?? null;
            }
        }

        return ['position' => $position, 'url' => $url, 'local_pack' => $localPack, 'top_domains' => $topDomains];
    }
}
