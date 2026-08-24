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
