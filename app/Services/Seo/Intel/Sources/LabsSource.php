<?php

namespace App\Services\Seo\Intel\Sources;

use App\Models\AreaServed;
use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;
use Illuminate\Support\Collection;

/**
 * DataForSEO Labs (Google): competitive keyword intelligence beyond the
 * keyword research already stored in seo_keywords — who else ranks for our
 * kind of work, which of THEIR keywords we are missing, whether our overall
 * organic footprint is growing, and which of our own pages are losing steam.
 *
 * Why it matters for a local remodeling contractor: seo_keywords answers
 * "what should we target"; this answers "who is beating us and where" —
 * the town+service phrases a competitor ranks for that we don't even show
 * up on are the most defensible list of pages/content worth building next.
 *
 * Endpoints (all Live, US/en via location_code 2840 — Labs rejects a
 * state-level location_name):
 *  - dataforseo_labs/google/competitors_domain/live   our top organic competitors, excluding giants
 *  - dataforseo_labs/google/domain_intersection/live  keywords a competitor ranks for that we don't
 *  - dataforseo_labs/google/historical_rank_overview/live  our 12-month organic trend
 *  - dataforseo_labs/google/relevant_pages/live       our own pages' keyword footprint
 *  - dataforseo_labs/google/bulk_traffic_estimation/live  ETV for us + competitors in one call
 *
 * Pricing (dataforseo.com/pricing/dataforseo-labs, "all other endpoints"
 * tier): $0.012/task + $0.00012/item, except Historical Rank which is
 * $0.12/task + $0.0012/item. See estimateCost() for the per-call math.
 *
 * "Gap" keywords: domain_intersection is called with intersections=false and
 * target1=competitor / target2=us, which DataForSEO documents as "keywords
 * where target1 ranks but target2 doesn't" — i.e. keywords the competitor
 * owns outright and we are entirely absent from, filtered to the competitor
 * ranking top 20. That is the cheap, single-call, unambiguous half of "we
 * rank >30 or not at all" (the >30-but-present case would need a second,
 * intersections=true call per competitor and isn't worth doubling the cost
 * for a less actionable signal — see caveats).
 */
class LabsSource extends IntelSource
{
    private const LOCATION_CODE = 2840; // United States

    private const LANGUAGE_CODE = 'en';

    /** @var array<string, string> trade-word fragment => canonical service slug, for action hints only. */
    private const SERVICE_MAP = [
        'kitchen' => 'kitchen-remodeling',
        'cabinet' => 'kitchen-remodeling',
        'countertop' => 'kitchen-remodeling',
        'bath' => 'bathroom-remodeling',
        'basement' => 'basement-remodeling',
        'addition' => 'home-additions',
    ];

    public function family(): string
    {
        return 'labs';
    }

    public function label(): string
    {
        return 'DataForSEO Labs — competitor keyword gaps';
    }

    public function estimateCost(): float
    {
        $competitorLimit = (int) $this->config('competitor_limit', 15);
        $gapCompetitors = (int) $this->config('gap_competitors', 3);
        $gapLimit = (int) $this->config('gap_limit_per_pair', 200);
        $months = (int) $this->config('historical_months', 12);
        $pagesLimit = (int) $this->config('relevant_pages_limit', 50);
        $trafficTargets = (int) $this->config('traffic_targets', 10);

        $competitorsDomain = 0.012 + $competitorLimit * 0.00012;
        $domainIntersection = $gapCompetitors * (0.012 + $gapLimit * 0.00012);
        $historicalRank = 0.12 + $months * 0.0012;
        $relevantPages = 0.012 + $pagesLimit * 0.00012;
        $bulkTraffic = 0.012 + (1 + $trafficTargets) * 0.00012;

        return round($competitorsDomain + $domainIntersection + $historicalRank + $relevantPages + $bulkTraffic, 4);
    }

    public function collect(): array
    {
        $spentAtStart = $this->dfs->spent();
        $maxCost = (float) $this->config('max_cost', 0.6);
        $over = fn (): bool => ($this->dfs->spent() - $spentAtStart) > $maxCost;

        $ours = $this->ourDomain();
        $competitorAcc = []; // domain => ['metrics' => [...], 'payload' => [...]]
        $domainAcc = null;   // ['metrics' => [...], 'payload' => [...]] for $ours
        $pageAcc = [];       // page url => ['metrics' => [...], 'payload' => [...]]
        $gapAcc = [];        // keyword => ['metrics' => [...], 'payload' => [...]]

        // 1. Our top organic competitors, giants excluded.
        if (! $over()) {
            $resp = $this->dfs->request('POST', '/dataforseo_labs/google/competitors_domain/live', [[
                'target' => $ours,
                'location_code' => self::LOCATION_CODE,
                'language_code' => self::LANGUAGE_CODE,
                'limit' => (int) $this->config('competitor_limit', 15),
                'exclude_top_domains' => true,
                'order_by' => ['intersections,desc'],
            ]]);
            foreach ($this->items($resp) as $it) {
                $domain = mb_strtolower(trim((string) ($it['domain'] ?? '')));
                if ($domain === '' || $domain === $ours) {
                    continue;
                }
                $organic = $it['full_domain_metrics']['organic'] ?? [];
                $competitorAcc[$domain] = [
                    'metrics' => [
                        'intersections' => (float) ($it['intersections'] ?? 0),
                        'avg_position' => (float) ($it['avg_position'] ?? 0),
                        'organic_count' => (float) ($organic['count'] ?? 0),
                        'organic_etv' => (float) ($organic['etv'] ?? 0),
                    ],
                    'payload' => ['domain' => $domain],
                ];
            }
        }

        // 2. Keyword gaps: for the top N competitors by intersections, the
        // local-trade keywords they rank for (top 20) that we don't rank for at all.
        $topCompetitors = array_slice(array_keys($competitorAcc), 0, (int) $this->config('gap_competitors', 3));
        if ($topCompetitors === []) {
            $topCompetitors = array_slice($this->competitorDomains(8), 0, (int) $this->config('gap_competitors', 3));
        }
        $towns = $this->geoTowns();
        foreach ($topCompetitors as $competitorDomain) {
            if ($over()) {
                break;
            }
            $resp = $this->dfs->request('POST', '/dataforseo_labs/google/domain_intersection/live', [[
                'target1' => $competitorDomain,
                'target2' => $ours,
                'location_code' => self::LOCATION_CODE,
                'language_code' => self::LANGUAGE_CODE,
                'intersections' => false,
                'item_types' => ['organic'],
                'limit' => (int) $this->config('gap_limit_per_pair', 200),
                'filters' => [
                    ['keyword_data.keyword_info.search_volume', '>', 0],
                ],
                'order_by' => ['keyword_data.keyword_info.search_volume,desc'],
            ]]);
            foreach ($this->items($resp) as $it) {
                $kd = $it['keyword_data'] ?? [];
                $keyword = mb_strtolower(trim((string) ($kd['keyword'] ?? '')));
                if ($keyword === '' || ! $this->isLocalTradeKeyword($keyword, $towns, $competitorDomain)) {
                    continue;
                }
                // The live API returns the SERP element flat ({type: 'organic',
                // rank_absolute: …}); the docs table shows it type-nested. Read both.
                $theirPos = self::elementRank($it['first_domain_serp_element'] ?? null);
                if ($theirPos === null || (int) $theirPos > 20) {
                    // Filtered client-side (rather than via the request's
                    // 'filters', whose dot-path support for a type-nested
                    // SERP field is undocumented) to keep the top-20-rank
                    // cutoff reliable.
                    continue;
                }
                $ourPos = self::elementRank($it['second_domain_serp_element'] ?? null);
                $volume = (int) ($kd['keyword_info']['search_volume'] ?? 0);
                if (isset($gapAcc[$keyword]) && ($gapAcc[$keyword]['metrics']['volume'] ?? 0) >= $volume) {
                    continue;
                }
                $gapAcc[$keyword] = [
                    'metrics' => [
                        'volume' => $volume,
                        'competitor_position' => (int) $theirPos,
                        'our_position' => $ourPos !== null ? (int) $ourPos : null,
                    ],
                    'payload' => ['competitor' => $competitorDomain, 'keyword' => $keyword],
                ];
            }
        }

        // 3. Our 12-month organic trend.
        if (! $over()) {
            $months = max(1, (int) $this->config('historical_months', 12));
            $resp = $this->dfs->request('POST', '/dataforseo_labs/google/historical_rank_overview/live', [[
                'target' => $ours,
                'location_code' => self::LOCATION_CODE,
                'language_code' => self::LANGUAGE_CODE,
                'date_from' => now()->subMonths($months)->startOfMonth()->toDateString(),
                'date_to' => now()->toDateString(),
            ]]);
            $items = $this->items($resp); // newest month first
            if ($items !== []) {
                $organic = $items[0]['metrics']['organic'] ?? [];
                $domainAcc = [
                    'metrics' => [
                        'count' => (float) ($organic['count'] ?? 0),
                        'etv' => (float) ($organic['etv'] ?? 0),
                        'pos_1' => (float) ($organic['pos_1'] ?? 0),
                        'pos_2_3' => (float) ($organic['pos_2_3'] ?? 0),
                        'pos_4_10' => (float) ($organic['pos_4_10'] ?? 0),
                    ],
                    'payload' => ['months' => array_slice($items, 0, $months)],
                ];
            }
        }

        // 4. Our own pages' keyword footprint.
        if (! $over()) {
            $resp = $this->dfs->request('POST', '/dataforseo_labs/google/relevant_pages/live', [[
                'target' => $ours,
                'location_code' => self::LOCATION_CODE,
                'language_code' => self::LANGUAGE_CODE,
                'limit' => (int) $this->config('relevant_pages_limit', 50),
            ]]);
            foreach ($this->items($resp) as $it) {
                $url = (string) ($it['page_address'] ?? '');
                if ($url === '') {
                    continue;
                }
                $organic = $it['metrics']['organic'] ?? [];
                $pageAcc[$url] = [
                    'metrics' => [
                        'keywords' => (float) ($organic['count'] ?? 0),
                        'etv' => (float) ($organic['etv'] ?? 0),
                        'pos_1' => (float) ($organic['pos_1'] ?? 0),
                        'pos_2_3' => (float) ($organic['pos_2_3'] ?? 0),
                        'pos_4_10' => (float) ($organic['pos_4_10'] ?? 0),
                    ],
                    'payload' => ['url' => $url],
                ];
            }
        }

        // 5. ETV for us + competitors in one call, merged into the accumulators above.
        if (! $over()) {
            $others = array_slice(array_keys($competitorAcc) ?: $this->competitorDomains(8), 0, (int) $this->config('traffic_targets', 10));
            $targets = array_values(array_unique(array_merge([$ours], $others)));
            $resp = $this->dfs->request('POST', '/dataforseo_labs/google/bulk_traffic_estimation/live', [[
                'targets' => $targets,
                'location_code' => self::LOCATION_CODE,
                'language_code' => self::LANGUAGE_CODE,
            ]]);
            foreach ($this->items($resp) as $it) {
                $target = mb_strtolower(trim((string) ($it['target'] ?? '')));
                if ($target === '') {
                    continue;
                }
                $organic = $it['metrics']['organic'] ?? [];
                $etv = (float) ($organic['etv'] ?? 0);
                $count = (float) ($organic['count'] ?? 0);
                if ($target === $ours) {
                    $domainAcc = $domainAcc ?? ['metrics' => [], 'payload' => []];
                    $domainAcc['metrics']['bulk_etv'] = $etv;
                    $domainAcc['metrics']['bulk_count'] = $count;
                } elseif (isset($competitorAcc[$target])) {
                    $competitorAcc[$target]['metrics']['bulk_etv'] = $etv;
                    $competitorAcc[$target]['metrics']['bulk_count'] = $count;
                }
            }
        }

        $snapshots = [];
        foreach ($competitorAcc as $domain => $acc) {
            $snapshots[] = new Snapshot('competitor', $domain, $acc['metrics'], $acc['payload']);
        }
        if ($domainAcc !== null) {
            $snapshots[] = new Snapshot('domain', $ours, $domainAcc['metrics'], $domainAcc['payload']);
        }
        foreach ($pageAcc as $url => $acc) {
            $snapshots[] = new Snapshot('page', $url, $acc['metrics'], $acc['payload']);
        }
        foreach ($gapAcc as $keyword => $acc) {
            $snapshots[] = new Snapshot('gap_keyword', $keyword, $acc['metrics'], $acc['payload']);
        }

        if ($snapshots === [] && $this->dfs->getLastError()) {
            throw new \RuntimeException($this->dfs->getLastError());
        }

        return $snapshots;
    }

    public function findings(): array
    {
        $findings = [];
        $ours = $this->ourDomain();

        // New organic competitor in the top N by keyword overlap.
        $latestComp = $this->latestSet('competitor');
        $prevComp = $this->previousSet('competitor');
        $topN = (int) $this->config('new_competitor_top_n', 10);
        if ($prevComp->isNotEmpty()) {
            $topLatest = $latestComp->sortByDesc(fn ($s) => $s['metrics']['intersections'] ?? 0)->take($topN);
            foreach ($topLatest as $domain => $snap) {
                if (! $prevComp->has($domain)) {
                    $findings[] = $this->finding(
                        'new_competitor', Finding::INFO, "New organic competitor: {$domain}",
                        sprintf('%s entered the top %d organic competitors by keyword overlap (%d shared keywords, avg position %.1f).', $domain, $topN, (int) ($snap['metrics']['intersections'] ?? 0), $snap['metrics']['avg_position'] ?? 0),
                        $domain
                    );
                }
            }
        }

        // Keyword gaps, bounded to the top N by volume.
        $maxGap = (int) $this->config('max_gap_findings', 15);
        $areasByCity = AreaServed::query()->get()->keyBy(fn ($a) => mb_strtolower(trim((string) $a->city)));
        $gaps = $this->latestSet('gap_keyword')->sortByDesc(fn ($s) => $s['metrics']['volume'] ?? 0)->take($maxGap);
        foreach ($gaps as $keyword => $snap) {
            $competitor = (string) ($snap['payload']['competitor'] ?? 'a competitor');
            $volume = (int) ($snap['metrics']['volume'] ?? 0);
            $theirPos = $snap['metrics']['competitor_position'] ?? null;
            $findings[] = $this->finding(
                // key is intentionally null: which competitor currently
                // surfaces this gap can shift month to month (topCompetitors
                // is re-derived from a fresh competitors_domain call every
                // run), so identity is the keyword alone — a competitor
                // change must not reopen an otherwise-persisting finding.
                'keyword_gap', Finding::INFO, "Keyword gap: {$keyword}",
                sprintf('%s ranks #%s for "%s" (~%d searches/mo); we do not rank in the top 20.', $competitor, $theirPos ?? '?', $keyword, $volume),
                $keyword, null,
                ['volume' => ['prev' => null, 'now' => $volume]],
                $this->gapAction($keyword, $areasByCity)
            );
        }

        // Our overall organic trend, month over month.
        $now = $this->latest('domain', $ours);
        $prev = $this->previous('domain', $ours);
        if ($now && $prev && ($prev['metrics']['etv'] ?? 0) > 0) {
            $pct = ((float) $now['metrics']['etv'] - (float) $prev['metrics']['etv']) / (float) $prev['metrics']['etv'];
            $threshold = (float) $this->config('etv_swing_pct', 0.15);
            $delta = ['etv' => ['prev' => $prev['metrics']['etv'], 'now' => $now['metrics']['etv']]];
            if ($pct <= -$threshold) {
                $findings[] = $this->finding('etv_drop', Finding::WARN, 'Estimated organic traffic dropped',
                    sprintf('Estimated organic traffic fell %.0f%% month over month (%.0f → %.0f).', $pct * 100, $prev['metrics']['etv'], $now['metrics']['etv']), $ours, null, $delta);
            } elseif ($pct >= $threshold) {
                $findings[] = $this->finding('etv_up', Finding::WIN, 'Estimated organic traffic climbed',
                    sprintf('Estimated organic traffic rose %.0f%% month over month (%.0f → %.0f).', $pct * 100, $prev['metrics']['etv'], $now['metrics']['etv']), $ours, null, $delta);
            }
        }

        // Pages that lost a large share of their ranking keywords.
        $nowPages = $this->latestSet('page');
        $prevPages = $this->previousSet('page');
        $pageThreshold = (float) $this->config('page_drop_pct', 0.3);
        foreach ($nowPages as $url => $snap) {
            $p = $prevPages->get($url);
            $prevKw = (float) ($p['metrics']['keywords'] ?? 0);
            if (! $p || $prevKw <= 0) {
                continue;
            }
            $nowKw = (float) ($snap['metrics']['keywords'] ?? 0);
            $drop = ($prevKw - $nowKw) / $prevKw;
            if ($drop >= $pageThreshold) {
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
                $findings[] = $this->finding(
                    'page_keyword_loss', Finding::WARN, 'Page lost ranking keywords',
                    sprintf('%s lost %.0f%% of its ranking keywords (%d → %d).', $path, $drop * 100, (int) $prevKw, (int) $nowKw),
                    $url, null,
                    ['keywords' => ['prev' => $prevKw, 'now' => $nowKw]],
                    ['type' => 'content_refresh', 'path' => $path]
                );
            }
        }

        return $findings;
    }

    public function report(): array
    {
        $ours = $this->ourDomain();
        $domainNow = $this->latest('domain', $ours);
        $domainPrev = $this->previous('domain', $ours);
        $competitors = $this->latestSet('competitor');
        $gaps = $this->latestSet('gap_keyword');

        $tiles = [
            ['label' => 'Organic keywords', 'value' => $domainNow['metrics']['count'] ?? null, 'prev' => $domainPrev['metrics']['count'] ?? null, 'good' => 'up'],
            ['label' => 'Estimated traffic (ETV)', 'value' => $domainNow['metrics']['etv'] ?? null, 'prev' => $domainPrev['metrics']['etv'] ?? null, 'good' => 'up'],
            ['label' => 'Competitors tracked', 'value' => $competitors->count()],
            ['label' => 'Gap keywords found', 'value' => $gaps->count()],
        ];

        $gapRows = $gaps->sortByDesc(fn ($s) => $s['metrics']['volume'] ?? 0)->take(12)
            ->map(fn ($s, $keyword) => [$keyword, (int) ($s['metrics']['volume'] ?? 0), $s['payload']['competitor'] ?? '—', $s['metrics']['competitor_position'] ?? '—'])
            ->values()->all();

        $compRows = $competitors->sortByDesc(fn ($s) => $s['metrics']['intersections'] ?? 0)->take(12)
            ->map(fn ($s, $domain) => [$domain, (int) ($s['metrics']['intersections'] ?? 0), (int) ($s['metrics']['organic_count'] ?? 0), (int) ($s['metrics']['organic_etv'] ?? 0)])
            ->values()->all();

        return [
            'tiles' => $tiles,
            'tables' => [
                ['title' => 'Top keyword gaps', 'columns' => ['Keyword', 'Volume/mo', 'Competitor', 'Their position'], 'rows' => $gapRows],
                ['title' => 'Organic competitors', 'columns' => ['Domain', 'Shared keywords', 'Their organic keywords', 'Their ETV'], 'rows' => $compRows],
            ],
            'note' => $domainNow
                ? sprintf('Labs competitive analysis for %s as of %s, versus %d tracked organic competitors.', $ours, $domainNow['taken_on'], $competitors->count())
                : 'No Labs data collected yet.',
        ];
    }

    /** The first task's items list of a decoded envelope ([] on any missing shape). */
    private function items(array $envelope): array
    {
        $result = DataForSeoService::resultOf($envelope);

        return (array) ($result[0]['items'] ?? []);
    }

    /**
     * Lowercased town names worth matching in a keyword: our configured GBP
     * service areas (county suffix stripped) plus every AreaServed city.
     */
    private function geoTowns(): array
    {
        $fromConfig = collect((array) config('gbp-services.service_areas', []))
            ->map(fn ($s) => mb_strtolower(trim(explode(',', (string) $s)[0] ?? '')));
        $fromAreas = AreaServed::query()->pluck('city')->map(fn ($c) => mb_strtolower(trim((string) $c)));

        return $fromConfig->merge($fromAreas)->filter()->unique()->values()->all();
    }

    /**
     * A local-trade keyword: mentions the trade AND a place (a served town,
     * or "near me"/"chicago"/"illinois"/"il"), and does not contain a token
     * of the competitor's own domain (so we don't chase their brand name).
     */
    private function isLocalTradeKeyword(string $keyword, array $towns, string $competitorDomain): bool
    {
        if (! preg_match('/kitchen|bath|remodel|renovat|contractor|basement|addition|design.build|countertop|cabinet/i', $keyword)) {
            return false;
        }
        $hasGeo = false;
        foreach ($towns as $town) {
            if ($town !== '' && str_contains($keyword, $town)) {
                $hasGeo = true;
                break;
            }
        }
        if (! $hasGeo) {
            $hasGeo = (bool) preg_match('/\b(near me|chicago|illinois|il)\b/i', $keyword);
        }
        if (! $hasGeo) {
            return false;
        }

        $base = explode('.', preg_replace('/^www\./', '', $competitorDomain) ?? '')[0] ?? '';
        $tokens = array_filter(preg_split('/[^a-z0-9]+/i', mb_strtolower($base)) ?: [], fn ($t) => mb_strlen($t) >= 4);
        foreach ($tokens as $token) {
            if (str_contains($keyword, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Action hint for a gap keyword: a matched service AND a matched served
     * town means we likely have no dedicated page for that combination yet
     * (create_page); a matched town with no specific service is a case for
     * refreshing that town's general area page; anything else gets no hint.
     */
    private function gapAction(string $keyword, Collection $areasByCity): ?array
    {
        $service = null;
        foreach (self::SERVICE_MAP as $needle => $slug) {
            if (str_contains($keyword, $needle)) {
                $service = $slug;
                break;
            }
        }

        $area = null;
        foreach ($areasByCity as $cityKey => $candidate) {
            if ($cityKey !== '' && str_contains($keyword, $cityKey)) {
                $area = $candidate;
                break;
            }
        }

        if ($service !== null && $area !== null) {
            return ['type' => 'create_page', 'town' => $area->city, 'service' => $service];
        }
        if ($area !== null) {
            return ['type' => 'content_refresh', 'path' => '/areas/' . $area->slug];
        }

        return null;
    }

    /** rank_absolute of a domain SERP element, whether flat or nested under its type key; null when absent or not organic. */
    public static function elementRank(mixed $el): ?int
    {
        if (! is_array($el)) {
            return null;
        }
        if (isset($el['rank_absolute']) && (($el['type'] ?? 'organic') === 'organic')) {
            return (int) $el['rank_absolute'];
        }
        if (isset($el['organic']['rank_absolute'])) {
            return (int) $el['organic']['rank_absolute'];
        }

        return null;
    }
}
