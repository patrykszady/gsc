<?php

namespace App\Services\Seo\Intel\Sources;

use App\Models\AreaServed;
use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;
use App\Support\Tenancy;

/**
 * SERP family: weekly rank + SERP-feature tracking for the queries that
 * matter, seen from our service area — via serp/google/organic/live/advanced.
 *
 * Why it matters: Search Console only tells us impressions/clicks for
 * queries Google already shows us for; it never shows what actually renders
 * on the page — whether an AI Overview now answers the question before any
 * blue link, whether the local 3-pack (the box most "near me" clicks go to)
 * has us in it, or which competitor just took the spot we lost. Those are
 * the features that quietly erode local traffic and GSC never reports them.
 *
 * Endpoint: POST /serp/google/organic/live/advanced, one call per tracked
 * query, location_coordinate centred on the shop (see center()), device
 * desktop, depth config('depth', 20). Billed per SERP of up to 10 results
 * (https://dataforseo.com/pricing/serp/google-organic-serp-api: $0.002 /
 * SERP; depth above 10 multiplies), so one query at depth 20 costs ~$0.004.
 * calculate_rectangles stays false (no pixel-ranking surcharge) and
 * load_async_ai_overview is never set (no +$0.002 surcharge) — only the AI
 * Overview that renders synchronously is read.
 */
class SerpSource extends IntelSource
{
    protected const DEFAULT_SERVICES = ['kitchen remodeling', 'bathroom remodeling'];

    public function family(): string
    {
        return 'serp';
    }

    public function label(): string
    {
        return 'SERP tracking';
    }

    public function estimateCost(): float
    {
        return round(count($this->buildQueries()) * $this->pricePerQuery(), 4);
    }

    public function collect(): array
    {
        $queries = $this->buildQueries();
        $maxCost = (float) $this->config('max_cost', 0.15);
        $spentAtStart = $this->dfs->spent();
        [$lat, $lng] = $this->center();
        $depth = $this->depth();
        $ourDomain = $this->ourDomain();
        $snapshots = [];

        foreach ($queries as $query) {
            if (($this->dfs->spent() - $spentAtStart) > $maxCost) {
                break;
            }
            $envelope = $this->dfs->request('POST', '/serp/google/organic/live/advanced', [[
                'keyword' => $query,
                'location_coordinate' => sprintf('%.6f,%.6f,100000', $lat, $lng),
                'language_code' => 'en',
                'device' => 'desktop',
                'depth' => $depth,
                'calculate_rectangles' => false,
            ]]);
            $result = DataForSeoService::resultOf($envelope)[0] ?? null;
            if (! is_array($result)) {
                continue;
            }
            $snapshots[] = $this->snapshotFor($query, $result, $ourDomain);
        }

        if ($snapshots === [] && $this->dfs->getLastError()) {
            throw new \RuntimeException($this->dfs->getLastError());
        }

        return $snapshots;
    }

    public function findings(): array
    {
        $now = $this->latestSet('keyword');
        $prev = $this->previousSet('keyword');
        $maxFindings = max(1, (int) $this->config('max_findings', 25));
        $candidates = [];

        foreach ($now as $query => $snap) {
            $nowPos = $snap['metrics']['position'] ?? null;
            $prevSnap = $prev->get($query);
            $prevPos = $prevSnap['metrics']['position'] ?? null;
            $path = $this->pathFor($snap['payload']['best_organic_url'] ?? null);

            // Position drop >=3, or fallen out of the tracked depth entirely.
            if ($prevPos !== null) {
                $depth = $this->depth();
                $effectiveNow = $nowPos ?? ($depth + 1);
                $drop = $effectiveNow - $prevPos;
                if ($drop >= 3) {
                    $critical = $prevPos <= 3 && $effectiveNow > 10;
                    $candidates[] = $this->finding(
                        'position_drop', $critical ? Finding::CRITICAL : Finding::WARN,
                        "\"{$query}\" dropped ".round($drop).' spots',
                        $nowPos === null ? "Was #{$prevPos}, now outside the top {$depth}." : "From #{$prevPos} to #{$nowPos}.",
                        $query, null, ['position' => ['prev' => $prevPos, 'now' => $nowPos]],
                        $path ? ['type' => 'content_refresh', 'path' => $path] : null,
                    );
                } elseif ($prevPos - $effectiveNow >= 3) {
                    $candidates[] = $this->finding(
                        'position_gain', Finding::WIN,
                        "\"{$query}\" gained ".round($prevPos - $effectiveNow).' spots',
                        "From #{$prevPos} to #{$nowPos}.", $query, null,
                        ['position' => ['prev' => $prevPos, 'now' => $nowPos]],
                    );
                }
            }

            // AI Overview appeared where it was absent last run.
            if ($prevSnap !== null && ($snap['metrics']['ai_overview'] ?? 0) == 1 && ($prevSnap['metrics']['ai_overview'] ?? 0) == 0) {
                $candidates[] = $this->finding(
                    'ai_overview_appeared', Finding::INFO,
                    "AI Overview now answers \"{$query}\"",
                    $snap['payload']['ai_overview_mentions_us'] ?? false
                        ? 'It cites us — worth strengthening the page it draws from.'
                        : 'It does not cite us — worth refreshing our ranking page to be a better source.',
                    $query, null, [], $path ? ['type' => 'content_refresh', 'path' => $path] : null,
                );
            }

            // Local pack present but we are not in it.
            if (($snap['payload']['local_pack_present'] ?? false) && ! ($snap['metrics']['in_local_pack'] ?? 0)) {
                $candidates[] = $this->finding(
                    'local_pack_absent', Finding::WARN,
                    "Local pack shows for \"{$query}\" — we are not in it",
                    'A 3-pack renders for this query on Google Maps/Search and our listing does not appear in it.',
                    $query,
                );
            }

            // A domain new to the top 3 now ranks above us (needs a previous run to be "new" against).
            $prevTop3 = array_slice((array) (($prevSnap['payload'] ?? [])['top10_domains'] ?? []), 0, 3);
            $nowTop3 = array_slice((array) ($snap['payload']['top10_domains'] ?? []), 0, 3);
            foreach ($prevSnap === null ? [] : array_diff($nowTop3, $prevTop3, [$this->ourDomain()]) as $domain) {
                $rank = array_search($domain, $nowTop3, true) + 1;
                if ($nowPos === null || $rank < $nowPos) {
                    $candidates[] = $this->finding(
                        'competitor_top3', Finding::INFO,
                        "{$domain} entered the top 3 for \"{$query}\"",
                        $nowPos === null ? 'We do not rank in the tracked depth for this query.' : "It now outranks us (we're #{$nowPos}).",
                        $query, $domain,
                    );
                }
            }

            // Unanswered "People also ask" questions on a query we rank for.
            $paa = (array) ($snap['payload']['paa_questions'] ?? []);
            if ($paa !== [] && $nowPos !== null) {
                $candidates[] = $this->finding(
                    'paa_gap', Finding::INFO,
                    "\"{$query}\" has unanswered People Also Ask questions",
                    'Questions Google surfaces for this query: '.implode(' · ', $paa),
                    $query, null, [], $path ? ['type' => 'content_refresh', 'path' => $path] : null,
                );
            }
        }

        usort($candidates, fn (Finding $a, Finding $b) => $this->severityRank($a->severity) <=> $this->severityRank($b->severity));

        return array_slice($candidates, 0, $maxFindings);
    }

    public function report(): array
    {
        $now = $this->latestSet('keyword');
        $prev = $this->previousSet('keyword');

        $positions = $now->map(fn ($s) => $s['metrics']['position'] ?? null)->filter(fn ($p) => $p !== null);
        $prevPositions = $prev->map(fn ($s) => $s['metrics']['position'] ?? null)->filter(fn ($p) => $p !== null);

        $tiles = [
            ['label' => 'Tracked queries', 'value' => $now->count()],
            ['label' => 'Avg. position', 'value' => $positions->isNotEmpty() ? round($positions->avg(), 1) : null, 'prev' => $prevPositions->isNotEmpty() ? round($prevPositions->avg(), 1) : null, 'good' => 'down'],
            ['label' => 'In top 10', 'value' => $positions->filter(fn ($p) => $p <= 10)->count(), 'prev' => $prevPositions->filter(fn ($p) => $p <= 10)->count(), 'good' => 'up'],
            ['label' => 'In local pack', 'value' => $now->sum(fn ($s) => (int) ($s['metrics']['in_local_pack'] ?? 0)), 'prev' => $prev->sum(fn ($s) => (int) ($s['metrics']['in_local_pack'] ?? 0)), 'good' => 'up'],
            ['label' => 'AI Overview present', 'value' => $now->sum(fn ($s) => (int) ($s['metrics']['ai_overview'] ?? 0)), 'prev' => $prev->sum(fn ($s) => (int) ($s['metrics']['ai_overview'] ?? 0))],
        ];

        $rows = $now->map(function ($s, $query) use ($prev) {
            $prevPos = $prev->get($query)['metrics']['position'] ?? null;
            $pos = $s['metrics']['position'] ?? null;
            $topCompetitor = collect((array) ($s['payload']['top10_domains'] ?? []))->first(fn ($d) => $d !== $this->ourDomain());

            return [
                'sort' => $pos ?? PHP_FLOAT_MAX,
                'row' => [
                    $query,
                    ($pos ?? '—').($prevPos !== null ? " ({$prevPos})" : ''),
                    ($s['metrics']['in_local_pack'] ?? 0) ? '✓' : '✗',
                    ($s['metrics']['ai_overview'] ?? 0) ? '✓' : '✗',
                    $topCompetitor ?? '—',
                ],
            ];
        })->sortBy('sort')->take(12)->pluck('row')->values()->all();

        $day = $now->isNotEmpty() ? $this->store->latestDay('serp', 'keyword') : null;

        return [
            'tiles' => $tiles,
            'tables' => [['title' => 'Tracked queries', 'columns' => ['Query', 'Position (prev)', 'Local pack', 'AI Overview', 'Top competitor'], 'rows' => $rows]],
            'note' => $day ? "Live Google SERPs for {$now->count()} tracked queries, checked {$day}." : 'No SERP checks stored yet.',
        ];
    }

    /** Top opportunity keywords first (guaranteed local anchors), then highest-opportunity researched keywords, deduped, capped. */
    protected function buildQueries(): array
    {
        $configured = array_values(array_filter(array_map('trim', (array) $this->config('queries', []))));
        if ($configured !== []) {
            return array_slice($this->dedupe($configured), 0, max(1, (int) $this->config('tracked', 30)));
        }

        $tracked = max(1, (int) $this->config('tracked', 30));
        $services = (array) $this->config('services', self::DEFAULT_SERVICES);
        $anchors = [];
        foreach (AreaServed::coreTowns(6) as $town) {
            foreach ($services as $service) {
                $anchors[] = "{$service} {$town} IL";
            }
        }

        // Researched phrases only for towns we actually serve: a "chicago
        // kitchen renovation" local pack, seen from Prospect Heights, is one
        // we can never enter, so tracking it only produces noise.
        $served = $this->servedTowns();
        $researched = Tenancy::table('seo_keywords')
            ->whereNotNull('city')
            ->orderByDesc('opportunity')
            ->limit($tracked * 4)
            ->get(['keyword', 'city'])
            ->filter(fn ($r) => isset($served[mb_strtolower(trim((string) $r->city))]))
            ->pluck('keyword')
            ->all();

        return array_slice($this->dedupe(array_merge($anchors, $researched)), 0, $tracked);
    }

    /** Case-insensitive de-dupe that keeps the first-seen casing. */
    /**
     * Towns we serve, lower-cased, as keys: the Business Profile service areas
     * plus the core towns. Not every area page — Chicago has one, but a local
     * pack for "chicago kitchen renovation" seen from Prospect Heights is not
     * ours to win.
     */
    protected function servedTowns(): array
    {
        $towns = [];
        foreach ((array) config('gbp-services.service_areas', []) as $entry) {
            if (stripos((string) $entry, 'county') !== false) {
                continue;
            }
            $name = mb_strtolower(trim((string) explode(',', (string) $entry)[0]));
            if ($name !== '') {
                $towns[$name] = true;
            }
        }
        foreach (AreaServed::coreTowns(6) as $city) {
            $towns[mb_strtolower(trim((string) $city))] = true;
        }

        return $towns;
    }

    protected function dedupe(array $queries): array
    {
        $seen = [];
        $out = [];
        foreach ($queries as $q) {
            $q = trim((string) $q);
            $key = mb_strtolower($q);
            if ($q === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $q;
        }

        return $out;
    }

    /** $0.002 per SERP of up to 10 results (depth above 10 multiplies). */
    protected function pricePerQuery(): float
    {
        return round(0.002 * ceil($this->depth() / 10), 4);
    }

    /** Clamped to DataForSEO's documented range for this endpoint: 10-200. */
    protected function depth(): int
    {
        return min(200, max(10, (int) $this->config('depth', 20)));
    }

    protected function snapshotFor(string $query, array $result, string $ourDomain): Snapshot
    {
        $items = (array) ($result['items'] ?? []);
        $position = null;
        $bestUrl = null;
        $top10 = [];
        $localPackPresent = false;
        $inLocalPack = false;
        $ourLocalPackRank = null;
        $aiOverviewPresent = false;
        $aiOverviewMentionsUs = false;
        $paaQuestions = [];
        $featuredDomain = null;

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            $domain = mb_strtolower((string) ($item['domain'] ?? ''));

            if ($type === 'organic') {
                if (count($top10) < 10) {
                    $top10[] = $domain;
                }
                if ($position === null && $domain !== '' && str_contains($domain, $ourDomain)) {
                    $position = (int) ($item['rank_absolute'] ?? 0) ?: null;
                    $bestUrl = $item['url'] ?? null;
                }
            } elseif ($type === 'local_pack') {
                $localPackPresent = true;
                if ($domain !== '' && str_contains($domain, $ourDomain)) {
                    $inLocalPack = true;
                    $ourLocalPackRank = $item['rank_group'] ?? null;
                }
            } elseif ($type === 'ai_overview') {
                $aiOverviewPresent = true;
                foreach ((array) ($item['items'] ?? []) as $el) {
                    foreach (array_merge((array) ($el['links'] ?? []), (array) ($el['references'] ?? [])) as $ref) {
                        if (str_contains(mb_strtolower((string) ($ref['domain'] ?? '')), $ourDomain)) {
                            $aiOverviewMentionsUs = true;
                        }
                    }
                }
            } elseif ($type === 'people_also_ask') {
                foreach ((array) ($item['items'] ?? []) as $el) {
                    $title = trim((string) ($el['title'] ?? ''));
                    if ($title !== '' && count($paaQuestions) < 8) {
                        $paaQuestions[] = $title;
                    }
                }
            } elseif ($type === 'featured_snippet') {
                $featuredDomain = $item['domain'] ?? null;
            }
        }

        $metrics = [
            'position' => $position,
            'in_local_pack' => $inLocalPack ? 1 : 0,
            'ai_overview' => $aiOverviewPresent ? 1 : 0,
            'paa_count' => count($paaQuestions),
            'featured' => ($featuredDomain !== null && str_contains(mb_strtolower((string) $featuredDomain), $ourDomain)) ? 1 : 0,
        ];
        $payload = [
            'top10_domains' => $top10,
            'best_organic_url' => $bestUrl,
            'local_pack_present' => $localPackPresent,
            'local_pack_rank' => $ourLocalPackRank,
            'ai_overview_present' => $aiOverviewPresent,
            'ai_overview_mentions_us' => $aiOverviewMentionsUs,
            'paa_questions' => $paaQuestions,
            'featured_domain' => $featuredDomain,
        ];

        return new Snapshot('keyword', $query, $metrics, $payload);
    }

    protected function pathFor(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    protected function severityRank(string $severity): int
    {
        return match ($severity) {
            Finding::CRITICAL => 0,
            Finding::WARN => 1,
            Finding::INFO => 2,
            Finding::WIN => 3,
            default => 4,
        };
    }
}
