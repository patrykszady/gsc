<?php

namespace App\Services;

use App\Support\Seo\DataForSeoSettings;
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

    public function __construct(protected DataForSeoSettings $settings)
    {
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
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
     * One raw authenticated request for callers that shape their own payloads
     * (the intelligence sources). Task-based POST bodies are a list of tasks.
     * Returns the decoded envelope, [] on transport failure; check
     * getLastError() and resultOf() for the first task's result.
     */
    public function request(string $method, string $path, array $body = []): array
    {
        return $this->call($method, $path, $body);
    }

    /** The first task's result list of a decoded envelope. */
    public static function resultOf(array $data): array
    {
        return (array) ($data['tasks'][0]['result'] ?? []);
    }

    /** Queue an asynchronous task (…/task_post); returns its id or null. */
    public function postTask(string $path, array $task): ?string
    {
        $data = $this->call('POST', $path, [$task]);
        $first = $data['tasks'][0] ?? [];
        $id = $first['id'] ?? null;

        return is_string($id) && in_array((int) ($first['status_code'] ?? 0), [20000, 20100], true) ? $id : null;
    }

    /**
     * Poll until $probe returns non-null, sleeping $interval seconds between
     * attempts (no sleeping under tests, where a bounded number of attempts
     * is made instead). Returns the probe's value, or null on timeout.
     */
    public function pollUntil(callable $probe, int $maxSeconds = 600, int $interval = 15): mixed
    {
        $testing = app()->runningUnitTests();
        $maxAttempts = $testing ? 20 : max(1, (int) ceil($maxSeconds / max(1, $interval)));
        for ($i = 0; $i < $maxAttempts; $i++) {
            $value = $probe();
            if ($value !== null) {
                return $value;
            }
            if (! $testing) {
                sleep($interval);
            }
        }

        return null;
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
     * ~$0.01 + rows). Labs endpoints take country-level locations only —
     * a state name is rejected (40501). Filtered to remodeling-ish terms client-side.
     *
     * @return array<int, array{keyword:string,volume:int,position:int,difficulty:?int,url:?string}>
     */
    public function rankedKeywords(string $domain, int $limit = 300, string $locationName = 'United States'): array
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
        $this->logIfTruncated('rankedKeywords', $domain, (int) ($data['tasks'][0]['result'][0]['total_count'] ?? 0), count($items));
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
    public function keywordIdeas(array $seeds, int $limit = 200, string $locationName = 'United States'): array
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
        $items = (array) ($data['tasks'][0]['result'][0]['items'] ?? []);
        $this->logIfTruncated('keywordIdeas', implode(',', array_slice($seeds, 0, 3)), (int) ($data['tasks'][0]['result'][0]['total_count'] ?? 0), count($items));
        $out = [];
        foreach ($items as $it) {
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

    /**
     * Keyword difficulty 0–100 for up to 1,000 keywords (Labs, ~$0.012/call).
     *
     * @return array<string,int> keyword => difficulty
     */
    public function keywordDifficulty(array $keywords): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($keywords))), 1000) as $chunk) {
            $data = $this->call('POST', '/dataforseo_labs/google/bulk_keyword_difficulty/live', [['keywords' => $chunk, 'location_name' => 'United States', 'language_code' => 'en']]);
            foreach ((array) ($data['tasks'][0]['result'][0]['items'] ?? []) as $it) {
                if (isset($it['keyword'], $it['keyword_difficulty'])) {
                    $out[mb_strtolower((string) $it['keyword'])] = (int) $it['keyword_difficulty'];
                }
            }
        }

        return $out;
    }

    /**
     * Search intent (informational / navigational / commercial / transactional)
     * for up to 1,000 keywords (Labs, ~$0.012/call).
     *
     * @return array<string, array{label:string,probability:float}>
     */
    public function searchIntent(array $keywords): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($keywords))), 1000) as $chunk) {
            $data = $this->call('POST', '/dataforseo_labs/google/search_intent/live', [['keywords' => $chunk, 'language_code' => 'en']]);
            foreach ((array) ($data['tasks'][0]['result'][0]['items'] ?? []) as $it) {
                if (isset($it['keyword'], $it['keyword_intent']['label'])) {
                    $out[mb_strtolower((string) $it['keyword'])] = ['label' => (string) $it['keyword_intent']['label'], 'probability' => (float) ($it['keyword_intent']['probability'] ?? 0)];
                }
            }
        }

        return $out;
    }

    /**
     * Organic footprint of a domain in Google US (Labs, ~$0.012): how many
     * keywords it ranks for by position band, estimated traffic, churn.
     *
     * @return array{pos_1:int,pos_2_3:int,pos_4_10:int,pos_11_20:int,count:int,etv:float,is_new:int,is_lost:int}|null
     */
    public function domainRankOverview(string $domain): ?array
    {
        $data = $this->call('POST', '/dataforseo_labs/google/domain_rank_overview/live', [['target' => $domain, 'location_name' => 'United States', 'language_code' => 'en']]);
        $m = $data['tasks'][0]['result'][0]['items'][0]['metrics']['organic'] ?? null;
        if (! is_array($m)) {
            return null;
        }

        return [
            'pos_1' => (int) ($m['pos_1'] ?? 0), 'pos_2_3' => (int) ($m['pos_2_3'] ?? 0), 'pos_4_10' => (int) ($m['pos_4_10'] ?? 0), 'pos_11_20' => (int) ($m['pos_11_20'] ?? 0),
            'count' => (int) ($m['count'] ?? 0), 'etv' => (float) ($m['etv'] ?? 0), 'is_new' => (int) ($m['is_new'] ?? 0), 'is_lost' => (int) ($m['is_lost'] ?? 0),
        ];
    }

    /**
     * Backlink profile summary (~$0.024): domain rank, backlinks, referring domains.
     *
     * @return array{rank:?int,backlinks:int,referring_domains:int,spam_score:?int}|null
     */
    public function backlinkSummary(string $domain): ?array
    {
        $data = $this->call('POST', '/backlinks/summary/live', [['target' => $domain, 'internal_list_limit' => 5]]);
        $r = $data['tasks'][0]['result'][0] ?? null;
        if (! is_array($r)) {
            return null;
        }

        return ['rank' => isset($r['rank']) ? (int) $r['rank'] : null, 'backlinks' => (int) ($r['backlinks'] ?? 0), 'referring_domains' => (int) ($r['referring_domains'] ?? 0), 'spam_score' => isset($r['backlinks_spam_score']) ? (int) $r['backlinks_spam_score'] : null];
    }

    /**
     * Domains linking to a target (~$0.024 per 100), strongest first.
     *
     * @return array<int, array{domain:string,rank:int,backlinks:int,platform:?string,spam_score:?int}>
     */
    public function referringDomains(string $domain, int $limit = 100): array
    {
        $data = $this->call('POST', '/backlinks/referring_domains/live', [['target' => $domain, 'limit' => $limit, 'order_by' => ['rank,desc'], 'exclude_internal_backlinks' => true]]);
        $items = (array) ($data['tasks'][0]['result'][0]['items'] ?? []);
        $this->logIfTruncated('referringDomains', $domain, (int) ($data['tasks'][0]['result'][0]['total_count'] ?? 0), count($items));
        $out = [];
        foreach ($items as $it) {
            if (empty($it['domain'])) {
                continue;
            }
            $platform = is_array($it['referring_links_platform_types'] ?? null) ? array_key_first($it['referring_links_platform_types']) : null;
            $out[] = ['domain' => mb_strtolower((string) $it['domain']), 'rank' => (int) ($it['rank'] ?? 0), 'backlinks' => (int) ($it['backlinks'] ?? 0), 'platform' => $platform, 'spam_score' => isset($it['backlinks_spam_score']) ? (int) $it['backlinks_spam_score'] : null];
        }

        return $out;
    }

    /**
     * Ask an AI answer engine a question with web search on (~$0.03) and
     * return the answer text.
     *
     * @param  string  $platform  chat_gpt | gemini | perplexity | claude
     */
    public function llmAnswer(string $platform, string $model, string $prompt): ?string
    {
        $data = $this->call('POST', "/ai_optimization/{$platform}/llm_responses/live", [['user_prompt' => $prompt, 'model_name' => $model, 'web_search' => true]]);
        $sections = $data['tasks'][0]['result'][0]['items'][0]['sections'] ?? null;
        if (! is_array($sections)) {
            return null;
        }
        $text = '';
        foreach ($sections as $s) {
            $text .= ($s['text'] ?? '')."\n";
        }

        return trim($text) !== '' ? trim($text) : null;
    }

    /**
     * Google Maps results at a coordinate (~$0.002): the map pack as seen
     * from that point, top 20, with rank, rating, review count and profile facts.
     *
     * @return array<int, array{rank:int,title:string,place_id:?string,url:?string,rating:?float,reviews:?int,claimed:?bool,categories:?array}>
     */
    public function mapsResults(string $keyword, float $lat, float $lng, int $depth = 20): array
    {
        $data = $this->call('POST', '/serp/google/maps/live/advanced', [[
            'keyword' => $keyword, 'location_coordinate' => sprintf('%.6f,%.6f,14z', $lat, $lng), 'language_code' => 'en', 'depth' => $depth,
        ]]);
        $out = [];
        foreach ((array) ($data['tasks'][0]['result'][0]['items'] ?? []) as $it) {
            if (($it['type'] ?? '') !== 'maps_search' && ! isset($it['rank_absolute'])) {
                continue;
            }
            $cats = array_values(array_filter(array_merge([(string) ($it['category'] ?? '')], (array) ($it['additional_categories'] ?? []))));
            $out[] = [
                'rank' => (int) ($it['rank_absolute'] ?? $it['rank_group'] ?? 0),
                'title' => (string) ($it['title'] ?? ''),
                'place_id' => $it['place_id'] ?? null,
                'url' => $it['url'] ?? null,
                'rating' => isset($it['rating']['value']) ? (float) $it['rating']['value'] : null,
                'reviews' => isset($it['rating']['votes_count']) ? (int) $it['rating']['votes_count'] : null,
                'claimed' => isset($it['is_claimed']) ? (bool) $it['is_claimed'] : null,
                'categories' => $cats ?: null,
            ];
        }

        return $out;
    }

    /**
     * A6: Labs/Backlinks endpoints return `items` capped at `limit` even when
     * `total_count` says more exist — there is no paging here, so a caller
     * silently working off a partial list (e.g. a 300-row keyword pull that
     * is actually one of 4,000) never finds out. One warning line, no UI.
     */
    protected function logIfTruncated(string $method, string $subject, int $totalCount, int $returned): void
    {
        if ($totalCount > $returned) {
            Log::warning("DataForSeoService::{$method} truncated for \"{$subject}\": {$returned} of {$totalCount} returned (no paging).");
        }
    }

    /** One authenticated call; records the task cost; returns decoded JSON or [] on failure. */
    protected function call(string $method, string $path, array $body = []): array
    {
        $this->lastError = null;
        try {
            $req = Http::withBasicAuth((string) $this->settings->login(), (string) $this->settings->password())
                ->timeout(120)->retry(2, 1500, throw: false);
            $resp = $method === 'GET' ? $req->get(self::BASE.$path) : $req->post(self::BASE.$path, $body);
        } catch (\Throwable $e) {
            $this->lastError = 'request failed: '.mb_substr($e->getMessage(), 0, 160);

            return [];
        }
        if (! $resp->successful()) {
            $this->lastError = 'HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200);

            return [];
        }
        $data = (array) $resp->json();
        foreach ((array) ($data['tasks'] ?? []) as $task) {
            $this->recordTask($task);
        }

        return $data;
    }

    /**
     * Records one decoded task's billed cost (spent()) and, if it failed,
     * the error — the bookkeeping call() does per task in its response.
     * googleOrganicPosition() below makes its own request outside call()
     * (it has bespoke 40101-retry handling call() doesn't), so it calls
     * this directly; without it, spend from the Live endpoint never
     * reached spent(), and A1's mid-loop budget cutoff had nothing to cut
     * off on.
     */
    protected function recordTask(array $task): void
    {
        $this->spent += (float) ($task['cost'] ?? 0);
        if (($task['status_code'] ?? 20000) !== 20000) {
            $this->lastError = ($task['status_code'] ?? '?').' '.($task['status_message'] ?? '');
        }
    }

    /**
     * One live Google-organic SERP check.
     *
     * position = rank_absolute of the first result whose domain matches;
     * local_pack = whether a local pack was present on the SERP (null if undetectable);
     * null return = the API call itself failed.
     *
     * @return array{position: ?int, url: ?string, local_pack: ?bool, top_domains: array<int,string>}|null
     */
    public function googleOrganicPosition(string $query, string $targetDomain, string $locationName = 'Chicago,Illinois,United States'): ?array
    {
        $this->lastError = null;
        try {
            $resp = Http::withBasicAuth(
                (string) $this->settings->login(),
                (string) $this->settings->password(),
            )->timeout(90)->retry(2, 1500, throw: false)
                ->post(self::BASE.'/serp/google/organic/live/advanced', [[
                    'keyword' => $query,
                    'location_name' => $locationName,
                    'language_code' => 'en',
                    'device' => 'desktop',
                    'depth' => 100,
                ]]);
        } catch (\Throwable $e) {
            // A single slow SERP must never abort the whole weekly run — the
            // live endpoint occasionally exceeds a minute under load.
            $this->lastError = 'request failed: '.mb_substr($e->getMessage(), 0, 160);

            return null;
        }

        if (! $resp->successful()) {
            $this->lastError = 'HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200);

            return null;
        }

        // Cast: a malformed/empty envelope must fall through to "unknown
        // status" below, not a TypeError out of recordTask()'s array param.
        $task = (array) $resp->json('tasks.0');
        $this->recordTask($task);

        // 40101 "Internal SE Server Error" is DataForSEO's transient upstream
        // failure — 8 of 31 queries hit it on the first baseline sweep. One
        // in-place retry clears most of them; persistent ones stay null and
        // the next weekly run fills the hole.
        if (($task['status_code'] ?? 0) === 40101) {
            sleep(2);
            $retry = Http::withBasicAuth(
                (string) $this->settings->login(),
                (string) $this->settings->password(),
            )->timeout(90)->retry(2, 1500, throw: false)
                ->post(self::BASE.'/serp/google/organic/live/advanced', [[
                    'keyword' => $query,
                    'location_name' => $locationName,
                    'language_code' => 'en',
                    'device' => 'desktop',
                    'depth' => 100,
                ]]);
            if ($retry->successful()) {
                $task = (array) $retry->json('tasks.0');
                $this->recordTask($task);
            }
        }

        if (($task['status_code'] ?? 0) !== 20000) {
            return null;
        }

        return self::parseOrganicResult($task['result'][0] ?? [], $targetDomain);
    }

    /**
     * Parse one Google Organic result envelope's `items` array — the shape
     * shared by Live Advanced (googleOrganicPosition) and the Standard-queue
     * task_get/advanced result (googleOrganicStandardBatch) — into the
     * position/url/local-pack/top-domains summary both callers need.
     *
     * @return array{position: ?int, url: ?string, local_pack: ?bool, top_domains: array<int,string>}
     */
    public static function parseOrganicResult(array $result, string $targetDomain): array
    {
        $items = $result['items'] ?? [];
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

    /**
     * Standard-queue Google Organic SERP checks (A4): one task_post for the
     * WHOLE batch of queries, then poll tasks_ready and collect each ready
     * task with task_get/advanced — DataForSEO's async queue, billed
     * ~$0.0006/check versus Live Advanced's ~$0.002 per
     * https://dataforseo.com/pricing/serp/google-organic-serp-api. Reuses
     * pollUntil(); task_post/tasks_ready/task_get are new call sites since
     * postTask() only ever wraps a single task and this needs one task_post
     * call for up to 100 tasks (DataForSEO's own per-call limit).
     *
     * @param  array<int, array{keyword:string, location_name?:string, location_coordinate?:string, depth?:int, device?:string}>  $tasks
     * @return array<int, array|null>|null Positionally aligned with $tasks
     *                                     (0-indexed, regardless of $tasks' own keys): each value is
     *                                     that task's result envelope (same shape parseOrganicResult()
     *                                     takes), or null if it never completed inside the poll window.
     *                                     The WHOLE return is null only when task_post itself failed to
     *                                     queue a single task — callers treat that as "fall back to
     *                                     Live" for this run.
     */
    public function googleOrganicStandardBatch(array $tasks, int $maxSeconds = 240, int $interval = 10): ?array
    {
        if ($tasks === []) {
            return [];
        }

        $payload = array_map(fn (array $t) => array_merge([
            'language_code' => 'en',
            'device' => 'desktop',
            'depth' => 100,
        ], $t), array_values($tasks));

        $data = $this->call('POST', '/serp/google/organic/task_post', $payload);
        $idsByIndex = [];
        foreach ((array) ($data['tasks'] ?? []) as $i => $task) {
            $id = $task['id'] ?? null;
            if (is_string($id) && in_array((int) ($task['status_code'] ?? 0), [20000, 20100], true)) {
                $idsByIndex[$i] = $id;
            }
        }

        if ($idsByIndex === []) {
            // task_post failed outright — lastError is already set by call();
            // the caller falls back to per-query Live Advanced for this run.
            return null;
        }

        $pending = array_flip($idsByIndex); // task id => original index
        $ready = [];
        $this->pollUntil(function () use (&$ready, $pending) {
            $envelope = $this->call('GET', '/serp/google/organic/tasks_ready');
            foreach (self::resultOf($envelope) as $row) {
                $id = $row['id'] ?? null;
                if (is_string($id) && isset($pending[$id])) {
                    $ready[$id] = true;
                }
            }

            return count($ready) >= count($pending) ? true : null;
        }, $maxSeconds, $interval);

        $out = array_fill(0, count($tasks), null);
        foreach ($idsByIndex as $i => $id) {
            if (! isset($ready[$id])) {
                continue; // never showed up as ready inside the poll window
            }
            $envelope = $this->call('GET', "/serp/google/organic/task_get/advanced/{$id}");
            $result = self::resultOf($envelope)[0] ?? null;
            if (is_array($result)) {
                $out[$i] = $result;
            }
        }

        return $out;
    }
}
