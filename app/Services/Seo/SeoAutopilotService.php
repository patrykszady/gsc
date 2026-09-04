<?php

namespace App\Services\Seo;

use App\Models\AreaServed;
use App\Models\GscCoverageState;
use App\Models\Project;
use App\Models\SeoAction;
use App\Services\Seo\Appliers\CreatePageApplier;
use App\Services\Seo\Appliers\LlmsRegenApplier;
use App\Services\Seo\Appliers\ReindexApplier;
use App\Services\Seo\Appliers\TitleMetaApplier;
use App\Support\SEO\AreaSeoPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The SEO Autopilot orchestrator — the self-improving loop.
 *
 *   synthesize() → turn the existing seo:* signals + GSC data into a deduped,
 *                  scored ledger of concrete actions
 *   act()        → auto-apply the safe allowlist (capturing a metric baseline),
 *                  leave everything else as a proposal
 *   measure()    → after the learning window, compare each applied action's
 *                  metric to its baseline, record the outcome, and let that
 *                  feed back into the scoring weights
 *
 * Autonomy policy: FULL-AUTO on a conservative reversible allowlist
 * (title_meta, reindex, llms_regen). GBP and anything that edits page body copy
 * stay as manual/review proposals.
 */
class SeoAutopilotService
{
    /** Categories the autopilot may apply without human approval.
     *  create_page only ever creates a DRAFT — publishing stays a human step. */
    public const SAFE_ALLOWLIST = ['title_meta', 'reindex', 'llms_regen', 'create_page', 'content_refresh'];

    private const BASE_URL = 'https://gs.construction';

    /** Minimum impressions (28d) for a page to be worth a title/meta rewrite. */
    private const MIN_IMPRESSIONS = 120;

    /** Standard organic CTR-by-position curve (fraction). Used to estimate the
     *  click headroom a better snippet could unlock. */
    private const CTR_CURVE = [
        1 => 0.28, 2 => 0.15, 3 => 0.11, 4 => 0.08, 5 => 0.06,
        6 => 0.045, 7 => 0.035, 8 => 0.030, 9 => 0.025, 10 => 0.022,
    ];

    public function __construct(
        private readonly TitleMetaGenerator $titles = new TitleMetaGenerator(),
        private readonly MetricProbe $probe = new MetricProbe(),
    ) {
    }

    /** @return array<string,\App\Services\Seo\ActionApplier> keyed by category */
    private function appliers(): array
    {
        return [
            'title_meta' => new TitleMetaApplier(),
            'reindex' => new ReindexApplier(),
            'llms_regen' => new LlmsRegenApplier(),
            'create_page' => new CreatePageApplier(),
            'content_refresh' => new \App\Services\Seo\Appliers\ContentRefreshApplier(),
        ];
    }

    // ---------------------------------------------------------------------
    // Phase 1 — synthesize
    // ---------------------------------------------------------------------

    /**
     * Refresh the proposed-action ledger from current signals. Returns the
     * number of new actions created.
     */
    public function synthesize(): int
    {
        $created = 0;
        $created += $this->synthesizeTitleMeta();
        $created += $this->synthesizeReindex();
        $created += $this->synthesizeCoverageClusters();
        $created += $this->synthesizeLlmsRefresh();
        $created += $this->synthesizeCreatePage();
        $created += $this->synthesizeResearch();

        return $created;
    }

    /** Query keyword => service slug, for parsing GSC demand into intent. */
    private const SERVICE_KEYWORDS = [
        'kitchen' => 'kitchen-remodeling',
        'bathroom' => 'bathroom-remodeling',
        'bath ' => 'bathroom-remodeling',
        'basement' => 'basement-remodeling',
        'addition' => 'home-additions',
        'mudroom' => 'mudroom-remodeling',
        'whole home' => 'home-remodeling',
        'whole-home' => 'home-remodeling',
        'home remodel' => 'home-remodeling',
        'home renovation' => 'home-remodeling',
        'remodel' => 'home-remodeling',
    ];

    private const MODIFIER_KEYWORDS = [
        'luxury' => 'luxury', 'high end' => 'luxury', 'high-end' => 'luxury', 'custom' => 'luxury',
        'affordable' => 'affordable', 'budget' => 'affordable', 'cheap' => 'affordable',
        'small' => 'small-space', 'condo' => 'condo', 'modern' => 'modern',
    ];

    /**
     * Demand-driven landing-page candidates: GSC queries that carry a modifier
     * (luxury/affordable/…) OR name a city we don't have a dedicated page for,
     * where we can back the page with real project proof. This is the ONLY
     * page-creation path, and the proof gate keeps it from making thin pages.
     */
    private function synthesizeCreatePage(): int
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return 0;
        }

        $generator = new LandingPageContentGenerator();
        $knownCities = $this->knownCities();          // [lower => Display]
        $areaCities = $this->areaCityKeys();          // set of AreaServed cities (lower)

        $end = Carbon::today();
        $start = $end->copy()->subDays(MetricProbe::WINDOW_DAYS - 1);

        $queries = \App\Support\Tenancy::table('gsc_query_metrics')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('query')
            ->havingRaw('SUM(impressions) >= 30')
            ->selectRaw('query, SUM(impressions) impressions, SUM(clicks) clicks, AVG(position) position')
            ->orderByDesc(DB::raw('SUM(impressions)'))
            ->limit(400)
            ->get();

        $created = 0;
        $budget = 6; // cap new page candidates per run

        foreach ($queries as $q) {
            if ($budget <= 0) {
                break;
            }

            $parsed = $this->parseQuery((string) $q->query, $knownCities);
            if ($parsed === null) {
                continue;
            }
            [$service, $city, $modifier] = $parsed;

            // Only fill genuine gaps: a modifier angle, OR a city without its own
            // area page. Never duplicate an existing /areas-served/{city} page.
            $cityIsCovered = isset($areaCities[Str::lower($city)]);
            if ($modifier === null && $cityIsCovered) {
                continue;
            }

            // Covered-city modifier plays need real volume (the area page
            // already serves the head term); an UNCOVERED town is a genuine
            // gap, so the 30-impression floor from the query is enough.
            if ($cityIsCovered && (int) $q->impressions < (int) config('seo.autopilot.modifier_min_impressions', 30)) {
                continue;
            }

            $content = $generator->build($service, $city, $modifier, (string) $q->query);
            if ($content === null) {
                continue; // no proof — proof gate
            }

            // Skip if a page for this slug already exists in any state.
            if (\App\Models\LandingPage::where('slug', $content['slug'])->exists()) {
                continue;
            }

            $created += $this->upsertAction([
                'fingerprint' => $this->fp('demand_gap', 'create_page', $content['slug']),
                'source' => 'demand_gap',
                'category' => 'create_page',
                'risk' => SeoAction::RISK_SAFE,
                'target_url' => self::BASE_URL . '/remodeling/' . $content['slug'],
                'title' => 'Create landing page: ' . $content['h1'],
                'hypothesis' => sprintf(
                    'Query "%s" has %d impressions/28d (pos %.1f) and no dedicated page. Proof-backed landing page can capture it.',
                    $q->query, (int) $q->impressions, (float) $q->position
                ),
                'metric' => 'clicks',
                'payload' => ['content' => $content, 'query' => $q->query],
                'impact_score' => round((float) $q->impressions * 0.02, 1),
            ]);
            $budget--;
        }

        return $created;
    }

    /** Zero-click / striking-distance pages that map to a HasSEO model. */
    /**
     * Keyword research (seo_keywords, weekly from DataForSEO) → actions:
     *  - a researched phrase for a town we have no page for, or with a
     *    modifier angle, becomes a landing page (proof gate still applies);
     *  - a town page whose title lacks the town's top researched phrase gets a
     *    title/meta experiment built on that phrase;
     *  - a town page with thin local copy and real search volume gets a copy
     *    refresh written around the town's top phrases (reversible).
     */
    /**
     * A researched term that is really someone's brand ("kitchens and baths
     * unlimited glenview") must never become our title or our page: it is
     * misleading and it is their name. Navigational intent is the engine's
     * signal for that; the competitor name list is the belt to its braces.
     */
    private function isBrandedOrNavigational(object $row): bool
    {
        if (($row->intent ?? null) === 'navigational') {
            return true;
        }
        static $tokens = null;
        if ($tokens === null) {
            $names = collect();
            if (Schema::hasTable('map_pack_competitors')) {
                $names = $names->concat(\App\Support\Tenancy::table('map_pack_competitors')->pluck('name'));
            }
            $names = $names->concat(collect((array) config('competitors.competitors', []))->pluck('name'));
            $tokens = $names->map(fn ($n) => Str::lower(trim((string) $n)))
                ->map(fn ($n) => preg_replace('/\b(inc|llc|ltd|co|corp|the|of|and|&)\b\.?/', ' ', $n))
                ->map(fn ($n) => trim(preg_replace('/[^a-z0-9 ]+/', ' ', (string) $n) ?? ''))
                ->map(fn ($n) => trim(preg_replace('/\s+/', ' ', $n) ?? ''))
                // Keep the distinctive part: the first two words when there are
                // three or more ("kitchens baths", "chi renovation"). When those
                // two are generic ("kitchen village", "home remodeling pros"),
                // fall back to three words rather than dropping the brand.
                ->map(function ($n) {
                    $words = explode(' ', $n);
                    $two = implode(' ', array_slice($words, 0, 2));
                    $generic = '/^(kitchen|kitchens|bathroom|bath|home|basement|remodeling|renovation|construction|design|general|chicago|north shore)( |$)/';

                    return preg_match($generic, $two) && count($words) >= 3 ? implode(' ', array_slice($words, 0, 3)) : $two;
                })
                ->filter(fn ($n) => mb_strlen($n) >= 8 && ! preg_match('/^(kitchen|bathroom|home|basement|remodeling|renovation|construction|design|general) (remodeling|remodel|renovation|contractor|contractors|construction|design|remodelers)$/', $n))
                ->unique()->values()->all();
        }
        $kw = ' ' . preg_replace('/\s+/', ' ', Str::lower((string) $row->keyword)) . ' ';
        foreach ($tokens as $t) {
            if (str_contains($kw, ' ' . $t . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function synthesizeResearch(): int
    {
        if (! Schema::hasTable('seo_keywords')) {
            return 0;
        }
        $minVolume = (int) config('seo.autopilot.research_min_volume', 30);
        $rows = \App\Support\Tenancy::table('seo_keywords')
            ->where('volume', '>=', $minVolume)
            ->where('opportunity', '>', 0)
            ->whereNotNull('service')
            ->whereNotNull('city')
            ->orderByDesc('opportunity')
            ->limit(300)
            ->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $areaCities = $this->areaCityKeys();
        // Towns we declare as served on the Business Profile — the boundary
        // for research-driven pages in towns without an area page. Without
        // it the first run proposed Round Lake and Lake Villa, an hour north
        // of anywhere we work. (The Search Console rule stays as it was: an
        // impression is proof someone there searched for us.)
        $servedTowns = collect((array) config('gbp-services.service_areas', []))
            ->map(fn ($s) => Str::lower(trim((string) Str::before((string) $s, ','))))
            ->filter()->flip()->all();
        $generator = new LandingPageContentGenerator();
        $created = 0;
        $pageBudget = 6;
        $refreshBudget = (int) config('seo.autopilot.content_refresh_per_run', 2);
        $topByCity = []; // city|service => top row

        foreach ($rows as $r) {
            if ($this->isBrandedOrNavigational($r)) {
                continue;
            }
            $covered = isset($areaCities[Str::lower((string) $r->city)]);

            // (a) landing page: uncovered town, or a modifier angle on a covered one.
            if (! $covered && ! isset($servedTowns[Str::lower((string) $r->city)])) {
                continue; // outside the declared service area
            }
            if ($pageBudget > 0 && (! $covered || $r->modifier !== null) && ($r->our_position === null || (float) $r->our_position > 20)) {
                $content = $generator->build((string) $r->service, (string) $r->city, $r->modifier, (string) $r->keyword);
                if ($content !== null && ! \App\Models\LandingPage::where('slug', $content['slug'])->exists()) {
                    $created += $this->upsertAction([
                        'fingerprint' => $this->fp('keyword_research', 'create_page', $content['slug']),
                        'source' => 'keyword_research',
                        'category' => 'create_page',
                        'risk' => SeoAction::RISK_SAFE,
                        'target_url' => self::BASE_URL . '/remodeling/' . $content['slug'],
                        'title' => 'Create landing page: ' . $content['h1'],
                        'hypothesis' => sprintf('"%s" has %d searches/month%s and we %s. Proof-backed landing page can capture it.', $r->keyword, (int) $r->volume, $r->competitor_best_position ? " (a competitor ranks #{$r->competitor_best_position})" : '', $r->our_position === null ? 'do not rank' : 'rank ' . round((float) $r->our_position, 1)),
                        'metric' => 'clicks',
                        'payload' => ['content' => $content, 'query' => $r->keyword, 'volume' => (int) $r->volume],
                        'impact_score' => round((float) $r->volume * 0.05, 1),
                    ]);
                    $pageBudget--;
                }
                continue;
            }

            // Covered town, plain intent: remember the strongest phrase per town+service.
            if ($covered && $r->modifier === null) {
                $key = Str::lower((string) $r->city) . '|' . $r->service;
                if (! isset($topByCity[$key]) || (int) $r->volume > (int) $topByCity[$key]->volume) {
                    $topByCity[$key] = $r;
                }
            }
        }

        // (b) + (c): per covered town, title on the top phrase; copy refresh when thin.
        // Towns the AI answer engines never name get first call on the refresh budget.
        $unnamed = [];
        if (Schema::hasTable('seo_ai_mentions') && ($day = \App\Support\Tenancy::table('seo_ai_mentions')->max('asked_on'))) {
            $unnamed = \App\Support\Tenancy::table('seo_ai_mentions')->where('asked_on', $day)->get()->groupBy('town')
                ->filter(fn ($g) => (int) $g->where('mentioned', 1)->count() === 0)->keys()->map(fn ($t) => Str::lower($t))->flip()->all();
        }
        uasort($topByCity, fn ($a, $b) => [isset($unnamed[Str::lower((string) $b->city)]) ? 1 : 0, (int) $b->volume] <=> [isset($unnamed[Str::lower((string) $a->city)]) ? 1 : 0, (int) $a->volume]);
        $refreshed = [];
        foreach ($topByCity as $key => $r) {
            if ((int) $r->volume < 50) {
                continue;
            }
            $area = AreaServed::query()->whereRaw('LOWER(city) = ?', [Str::lower((string) $r->city)])->first();
            if (! $area) {
                continue;
            }
            $isHome = $r->service === 'home-remodeling';
            $url = self::BASE_URL . '/areas-served/' . $area->slug . ($isHome ? '' : '/services/' . $r->service);
            $serviceSlug = $isHome ? null : (string) $r->service;
            if (! AreaSeoPolicy::shouldIndex($area, $serviceSlug ? 'service' : 'home', $serviceSlug)) {
                continue;
            }

            // (b) title experiment on the phrase, when the live title lacks its head word.
            $current = \App\Models\SeoPathOverride::where('path', \App\Models\SeoPathOverride::normalizePath($url))->first()?->title
                ?? ($this->titles->forArea($area, $serviceSlug)['title'] ?? '');
            // The first distinctive word of the phrase the live title lacks
            // ("renovation" in "kenilworth home remodeling and renovation
            // services" against "Kenilworth Home Remodeling").
            $stop = [mb_strtolower((string) $r->city), 'illinois', 'services', 'service', 'near', 'with', 'from', 'that', 'this', 'best'];
            $head = collect(explode(' ', mb_strtolower((string) $r->keyword)))
                ->filter(fn ($w) => mb_strlen($w) > 3 && ! in_array($w, $stop, true) && ! str_contains(mb_strtolower((string) $current), $w))
                ->first();
            $inFlight = SeoAction::where('category', 'title_meta')->where('target_url', $url)->whereIn('status', [SeoAction::STATUS_PROPOSED, SeoAction::STATUS_APPLIED])->whereNull('measured_at')->exists();
            if ($head && ! $inFlight) {
                $generated = $this->titles->forArea($area, $serviceSlug, (string) $r->keyword);
                $created += $this->upsertAction([
                    'fingerprint' => $this->fp('keyword_research', 'title_meta', $url . ':' . $r->keyword),
                    'source' => 'keyword_research',
                    'category' => 'title_meta',
                    'risk' => SeoAction::RISK_SAFE,
                    'target_type' => AreaServed::class,
                    'target_id' => $area->getKey(),
                    'target_url' => $url,
                    'title' => 'Rewrite title/meta on the researched phrase: ' . Str::of($url)->after(self::BASE_URL),
                    'hypothesis' => sprintf('"%s" has %d searches/month; the title does not carry "%s". Title/meta built on the phrase.', $r->keyword, (int) $r->volume, $head),
                    'metric' => 'clicks',
                    'payload' => ['new_title' => $generated['title'], 'new_description' => $generated['description'], 'phrase' => $r->keyword, 'volume' => (int) $r->volume],
                    'impact_score' => round((float) $r->volume * 0.03, 1),
                ]);
            }

            // (c) copy refresh for a thin town page (once per area per 90 days, budget per run).
            if ($isHome && $refreshBudget > 0 && ! isset($refreshed[$area->getKey()]) && mb_strlen((string) $area->local_intro) < 1500) {
                $recent = SeoAction::where('category', 'content_refresh')->where('target_type', AreaServed::class)->where('target_id', $area->getKey())->where('created_at', '>=', now()->subDays(90))->exists();
                if (! $recent) {
                    $phrases = collect($topByCity)->filter(fn ($x) => Str::lower((string) $x->city) === Str::lower((string) $r->city))->sortByDesc('volume')->take(4)->pluck('keyword')->all();
                    $created += $this->upsertAction([
                        'fingerprint' => $this->fp('keyword_research', 'content_refresh', $area->slug . ':' . now()->format('Y-m')),
                        'source' => 'keyword_research',
                        'category' => 'content_refresh',
                        'risk' => SeoAction::RISK_SAFE,
                        'target_type' => AreaServed::class,
                        'target_id' => $area->getKey(),
                        'target_url' => self::BASE_URL . '/areas-served/' . $area->slug,
                        'title' => 'Deepen local copy around researched phrases: /areas-served/' . $area->slug,
                        'hypothesis' => sprintf('%s searches/month across "%s"; the town page carries only %d characters of local copy. A deeper, query-led intro should lift rank and clicks.', (int) $r->volume, implode('", "', $phrases), mb_strlen((string) $area->local_intro)),
                        'metric' => 'clicks',
                        'payload' => ['phrases' => $phrases, 'volume' => (int) $r->volume],
                        'impact_score' => round((float) $r->volume * 0.04, 1),
                    ]);
                    $refreshed[$area->getKey()] = true;
                    $refreshBudget--;
                }
            }
        }

        return $created;
    }

    private function synthesizeTitleMeta(): int
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return 0;
        }

        $end = Carbon::today();
        $start = $end->copy()->subDays(MetricProbe::WINDOW_DAYS - 1);

        $pages = \App\Support\Tenancy::table('gsc_query_metrics')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('page', 'like', self::BASE_URL . '/%')
            ->groupBy('page')
            ->havingRaw('SUM(impressions) >= ?', [self::MIN_IMPRESSIONS])
            ->selectRaw('page, SUM(clicks) clicks, SUM(impressions) impressions, AVG(position) position')
            ->get();

        $created = 0;
        foreach ($pages as $p) {
            $position = (float) $p->position;
            $impressions = (float) $p->impressions;
            $clicks = (float) $p->clicks;
            $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;

            // Striking distance: on page 1–2 but under-earning clicks.
            if ($position < 3.0 || $position > 20.0) {
                continue;
            }

            $expectedCtr = $this->expectedCtr($position);
            $headroom = max(0.0, $expectedCtr - $ctr);
            if ($headroom <= 0.005) {
                continue; // already earning its share
            }

            $target = $this->resolveTarget((string) $p->page);
            if ($target === null) {
                continue; // not a model-backed page we can safely rewrite
            }

            [$model, $serviceSlug] = $target;

            // Don't rewrite titles for pages we intentionally keep out of the index.
            if ($model instanceof AreaServed
                && ! AreaSeoPolicy::shouldIndex($model, $serviceSlug ? 'service' : 'home', $serviceSlug)) {
                continue;
            }

            $generated = $model instanceof AreaServed
                ? $this->titles->forArea($model, $serviceSlug)
                : $this->titles->forProject($model);

            $estUplift = round($impressions * $headroom, 1); // est. clicks/28d
            $source = $ctr <= 0.0001 ? 'zero_click' : 'striking_distance';

            // One experiment at a time per page. The fingerprint includes
            // $source, so a page drifting across the zero-click boundary
            // between runs got a SECOND concurrent title experiment (Wheeling
            // Jul 30 zero_click + Aug 13 striking_distance) — the new title
            // overwrites the old mid-measurement, corrupting both: the first
            // measures a page that stopped serving its title, and the
            // second's baseline was taken under the first's title.
            $inFlight = SeoAction::where('category', 'title_meta')
                ->where('target_url', (string) $p->page)
                ->whereIn('status', [SeoAction::STATUS_PROPOSED, SeoAction::STATUS_APPLIED])
                ->whereNull('measured_at')
                ->exists();
            if ($inFlight) {
                continue;
            }

            $created += $this->upsertAction([
                'fingerprint' => $this->fp($source, 'title_meta', $model::class . ':' . $model->getKey() . ':' . ($serviceSlug ?? '')),
                'source' => $source,
                'category' => 'title_meta',
                'risk' => SeoAction::RISK_SAFE,
                'target_type' => $model::class,
                'target_id' => $model->getKey(),
                'target_url' => (string) $p->page,
                'title' => 'Rewrite title/meta: ' . Str::of((string) $p->page)->after(self::BASE_URL),
                'hypothesis' => sprintf(
                    'Position %.1f with %d impressions but %.2f%% CTR (expected ~%.1f%%). A CTR-led title/meta could recover ~%s clicks/28d.',
                    $position, (int) $impressions, $ctr * 100, $expectedCtr * 100, $estUplift
                ),
                'metric' => 'clicks',
                'payload' => [
                    'new_title' => $generated['title'],
                    'new_description' => $generated['description'],
                    'observed' => [
                        'position' => round($position, 1),
                        'impressions' => (int) $impressions,
                        'clicks' => (int) $clicks,
                        'ctr_pct' => round($ctr * 100, 2),
                    ],
                ],
                'impact_score' => $estUplift,
            ]);
        }

        return $created;
    }

    /** Coverage-problem URLs worth nudging back into the crawl queue. */
    private function synthesizeReindex(): int
    {
        if (! Schema::hasTable('gsc_coverage_states')) {
            return 0;
        }

        $rows = GscCoverageState::query()
            ->where(function ($q) {
                $q->where('verdict', '!=', 'PASS')
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%crawled%']);
            })
            // Never chase URLs we deliberately noindexed.
            ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%excluded by%'])
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->limit(50)
            ->get(['url', 'coverage_state', 'verdict', 'last_crawl_time']);

        $created = 0;
        foreach ($rows as $row) {
            $url = (string) $row->url;
            if ($url === '') {
                continue;
            }

            $state = strtolower((string) $row->coverage_state);
            $isCrawledNotIndexed = str_contains($state, 'crawled') && str_contains($state, 'not indexed');

            // "Crawled – currently not indexed" is a quality judgment: Google
            // already fetched the page and declined it. Resubmitting the SAME
            // content does nothing — only act once the page actually changed
            // after Google's last crawl. Discovery states (unknown/discovered/
            // 404s) still benefit from a plain resubmit.
            $updatedAt = $this->contentUpdatedAt($url);
            $changedSinceCrawl = $updatedAt !== null
                && $row->last_crawl_time !== null
                && $updatedAt->gt(Carbon::parse($row->last_crawl_time));

            if ($isCrawledNotIndexed && ! $changedSinceCrawl) {
                continue;
            }

            // Version the fingerprint by content stamp so a URL becomes
            // actionable AGAIN each time its content is refreshed (a static
            // per-URL fingerprint meant one resubmit ever, then silence).
            // Unchanged URLs keep the LEGACY un-suffixed key so pre-existing
            // ledger rows still dedup instead of spawning "|initial" twins.
            $stamp = $changedSinceCrawl ? $updatedAt->format('Ymd') : 'initial';
            $fingerprint = $changedSinceCrawl
                ? $this->fp('coverage_error', 'reindex', $url . '|' . $stamp)
                : $this->fp('coverage_error', 'reindex', $url);

            // Collapse repeat edits: if a reindex for this URL was already
            // proposed/applied AFTER the content change, another action adds
            // nothing — one resubmission per content version is enough.
            if ($changedSinceCrawl) {
                $alreadyCovered = SeoAction::where('category', 'reindex')
                    ->where('target_url', $url)
                    ->whereIn('status', [SeoAction::STATUS_PROPOSED, SeoAction::STATUS_APPLIED])
                    ->where(function ($q) use ($updatedAt) {
                        $q->where('created_at', '>=', $updatedAt)
                            ->orWhere('applied_at', '>=', $updatedAt);
                    })
                    ->exists();
                if ($alreadyCovered) {
                    continue;
                }
            }

            $created += $this->upsertAction([
                'fingerprint' => $fingerprint,
                'source' => 'coverage_error',
                'category' => 'reindex',
                'risk' => SeoAction::RISK_SAFE,
                'target_url' => $url,
                'title' => 'Reindex: ' . Str::of($url)->after(self::BASE_URL),
                'hypothesis' => $changedSinceCrawl
                    ? sprintf(
                        'Content updated %s — after Google\'s last crawl (%s, state "%s"). Resubmit so the improved page gets re-evaluated.',
                        $updatedAt->toDateString(),
                        Carbon::parse($row->last_crawl_time)->toDateString(),
                        $row->coverage_state ?? '?'
                    )
                    : sprintf('Coverage verdict "%s" / state "%s" — resubmit to IndexNow to prompt a re-crawl.', $row->verdict ?? '?', $row->coverage_state ?? '?'),
                'metric' => 'impressions',
                'payload' => ['url' => $url, 'coverage_state' => $row->coverage_state, 'content_stamp' => $stamp],
                'impact_score' => $changedSinceCrawl ? 8.0 : 5.0,
            ]);
        }

        return $created;
    }

    /**
     * Best-known content timestamp for a public URL, so reindex actions can
     * distinguish "page improved since Google's crawl" from "same page again".
     */
    private function contentUpdatedAt(string $url): ?Carbon
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        if (preg_match('#^/areas-served/([^/]+)#', $path, $m)) {
            $ts = AreaServed::where('slug', $m[1])->value('updated_at');

            return $ts ? Carbon::parse($ts) : null;
        }

        if (preg_match('#^/projects/([^/]+)#', $path, $m)) {
            $ts = Project::where('slug', $m[1])->value('updated_at');

            return $ts ? Carbon::parse($ts) : null;
        }

        return null;
    }

    /**
     * Template-family index-rate alarm. When a large share of one URL family
     * (photo pages, area service combos…) is "Crawled – currently not
     * indexed", the family needs content differentiation, not resubmission —
     * exactly how the /services/kitchen-remodeling thin-content problem was
     * found by hand. Emits ONE review-risk action per family with the numbers.
     */
    private function synthesizeCoverageClusters(): int
    {
        if (! Schema::hasTable('gsc_coverage_states')) {
            return 0;
        }

        // Only count URLs still in the sitemap — retired rows (deliberately
        // de-indexed thin spokes) otherwise inflate a family's "not indexed"
        // share and raise alarms about pages we removed on purpose.
        $inSitemap = [];
        $sitemapPath = public_path('sitemap.xml');
        if (is_file($sitemapPath) && ($xml = @simplexml_load_string((string) file_get_contents($sitemapPath))) && isset($xml->url)) {
            foreach ($xml->url as $u) {
                $inSitemap[(string) $u->loc] = true;
            }
        }

        $families = [];
        GscCoverageState::query()
            ->select(['id', 'url', 'coverage_state'])
            ->chunkById(500, function ($rows) use (&$families, $inSitemap): void {
                foreach ($rows as $row) {
                    if ($inSitemap !== [] && ! isset($inSitemap[(string) $row->url])) {
                        continue;
                    }
                    $path = parse_url((string) $row->url, PHP_URL_PATH) ?: '/';
                    $family = $this->urlFamily($path);
                    $families[$family]['total'] = ($families[$family]['total'] ?? 0) + 1;
                    $state = strtolower((string) $row->coverage_state);
                    if (str_contains($state, 'not indexed')) {
                        $families[$family]['unindexed'] = ($families[$family]['unindexed'] ?? 0) + 1;
                    }
                }
            });

        // Self-expire: close open alarms whose family no longer breaches the
        // threshold — advisories must disappear when their condition heals.
        $openAlarms = SeoAction::where('source', 'coverage_cluster')
            ->where('status', SeoAction::STATUS_PROPOSED)
            ->get();
        foreach ($openAlarms as $alarm) {
            $family = (string) ($alarm->payload['family'] ?? '');
            $total = (int) ($families[$family]['total'] ?? 0);
            $unindexed = (int) ($families[$family]['unindexed'] ?? 0);
            $currentPct = $total > 0 ? (int) round(($unindexed / $total) * 100) : 0;
            $breaches = $total >= 10 && $total > 0 && $unindexed / $total >= 0.4;
            // Also retire alarms whose severity band no longer matches — the
            // current-band alarm is (re)created below, so an old "49%" row
            // must not linger next to the fresh "60%" one.
            $sameBand = (int) (floor($currentPct / 20) * 20) === (int) (floor((int) ($alarm->payload['pct'] ?? 0) / 20) * 20);
            if (! $breaches || ! $sameBand) {
                $alarm->status = SeoAction::STATUS_SKIPPED;
                $alarm->notes = trim(($alarm->notes ? $alarm->notes . ' ' : '')
                    . sprintf('Auto-resolved %s: family now %d/%d not indexed.', now()->toDateString(), $unindexed, $total));
                $alarm->save();
            }
        }

        $created = 0;
        foreach ($families as $family => $counts) {
            $total = (int) ($counts['total'] ?? 0);
            $unindexed = (int) ($counts['unindexed'] ?? 0);
            if ($total < 10 || $unindexed / $total < 0.4) {
                continue;
            }

            $pct = (int) round(($unindexed / $total) * 100);
            // Severity band (40s/60s/80s…) in the fingerprint: a new action
            // only appears when the problem meaningfully worsens or recurs.
            $band = (int) (floor($pct / 20) * 20);

            $created += $this->upsertAction([
                'fingerprint' => $this->fp('coverage_cluster', 'content_quality', $family . '|' . $band),
                'source' => 'coverage_cluster',
                'category' => 'content_quality',
                'risk' => SeoAction::RISK_REVIEW,
                'target_url' => self::BASE_URL . '/' . ltrim(str_replace('*', '', $family), '/'),
                'title' => "Index-rate alarm: {$family} ({$pct}% not indexed)",
                'hypothesis' => sprintf(
                    '%d of %d tracked URLs in the "%s" family are not indexed (%d%%). Google is declining this template as a group — it needs content differentiation (unique copy, proof elements, internal links), not resubmission.',
                    $unindexed,
                    $total,
                    $family,
                    $pct
                ),
                'metric' => 'impressions',
                'payload' => ['family' => $family, 'total' => $total, 'unindexed' => $unindexed, 'pct' => $pct],
                'impact_score' => 9.0,
            ]);
        }

        return $created;
    }

    /** Bucket a path into its template family for cluster analysis. */
    private function urlFamily(string $path): string
    {
        if (str_contains($path, '/photos/')) {
            return 'projects/*/photos';
        }
        if (preg_match('#^/areas-served/[^/]+/services/#', $path)) {
            return 'areas-served/*/services';
        }
        if (preg_match('#^/areas-served/[^/]+/(about|testimonials|projects|contact)#', $path)) {
            return 'areas-served/*/subpages';
        }
        if (preg_match('#^/service-area/#', $path)) {
            return 'service-area/*';
        }

        $seg = explode('/', trim($path, '/'))[0] ?? '';

        return $seg !== '' ? $seg : '(root)';
    }

    /** Refresh the AI-answer surface (llms.txt) when it goes stale. */
    private function synthesizeLlmsRefresh(): int
    {
        $path = public_path('llms.txt');
        $ageDays = is_file($path) ? (now()->timestamp - filemtime($path)) / 86400 : 999;
        if ($ageDays < 7) {
            return 0;
        }

        return $this->upsertAction([
            'fingerprint' => $this->fp('llms_stale', 'llms_regen', 'llms.txt:' . now()->format('oW')), // weekly bucket
            'source' => 'llms_stale',
            'category' => 'llms_regen',
            'risk' => SeoAction::RISK_SAFE,
            'target_url' => self::BASE_URL . '/llms.txt',
            'title' => 'Regenerate llms.txt / AI feed',
            'hypothesis' => sprintf('AI-answer surface is %d days old; regenerate so ChatGPT/Perplexity/AI Overviews cite current content.', (int) $ageDays),
            'metric' => 'impressions',
            'payload' => [],
            'impact_score' => 3.0,
        ]);
    }

    // ---------------------------------------------------------------------
    // Phase 2 — act
    // ---------------------------------------------------------------------

    /**
     * Apply the top-priority open actions whose category is on the safe
     * allowlist. Returns a summary of what happened.
     *
     * @return array{applied:int,failed:int,skipped:int,items:array<int,array<string,mixed>>}
     */
    public function act(bool $dryRun = false, int $maxApplies = 25): array
    {
        $this->rescoreOpenActions();

        // Reindex actions are single IndexNow pings — cheap, harmless, and
        // pointless to ration. They get their own generous cap so a content
        // batch (e.g. 67 town updates) drains in one run instead of trickling
        // out 25/day while the backlog reads as "needs manual apply" in admin.
        $categoryCaps = ['reindex' => 200];

        $candidates = SeoAction::open()
            ->whereIn('category', self::SAFE_ALLOWLIST)
            ->orderByDesc('priority')
            ->limit(500)
            ->get();

        $appliers = $this->appliers();
        $applied = $failed = 0;
        $items = [];
        $perCategory = [];
        $generalBudget = $maxApplies;

        foreach ($candidates as $action) {
            $applier = $appliers[$action->category] ?? null;
            if (! $applier) {
                continue;
            }

            $cap = $categoryCaps[$action->category] ?? null;
            if ($cap !== null) {
                if (($perCategory[$action->category] ?? 0) >= $cap) {
                    continue;
                }
            } else {
                if ($generalBudget <= 0) {
                    continue;
                }
                $generalBudget--;
            }
            $perCategory[$action->category] = ($perCategory[$action->category] ?? 0) + 1;

            if ($dryRun) {
                $items[] = ['id' => $action->id, 'title' => $action->title, 'priority' => $action->priority, 'result' => 'would-apply'];
                continue;
            }

            try {
                // Capture the "before" metric so we can judge the change later.
                $baseline = $this->probe->forPage((string) $action->target_url);
                $applier->apply($action);

                $action->status = SeoAction::STATUS_APPLIED;
                $action->auto_applied = true;
                $action->applied_at = now();
                $action->baseline_value = $this->probe->scalar($baseline, (string) $action->metric);
                $action->baseline_at = now();
                $action->measure_after = now()->addDays(MetricProbe::MEASURE_AFTER_DAYS);
                $action->outcome = SeoAction::OUTCOME_PENDING;
                $action->error = null;
                $action->save();

                $applied++;
                $items[] = ['id' => $action->id, 'title' => $action->title, 'priority' => $action->priority, 'result' => 'applied'];
            } catch (Throwable $e) {
                $action->status = SeoAction::STATUS_FAILED;
                $action->error = Str::limit($e->getMessage(), 480, '');
                $action->save();
                $failed++;
                $items[] = ['id' => $action->id, 'title' => $action->title, 'result' => 'failed: ' . $e->getMessage()];
            }
        }

        return ['applied' => $applied, 'failed' => $failed, 'skipped' => 0, 'items' => $items];
    }

    /**
     * Apply a single operator-chosen action (from the admin panel), capturing a
     * baseline exactly as the autonomous path does. Returns true on success.
     */
    public function applyOne(SeoAction $action): bool
    {
        $applier = $this->appliers()[$action->category] ?? null;
        if (! $applier || $action->status !== SeoAction::STATUS_PROPOSED) {
            return false;
        }

        try {
            $baseline = $this->probe->forPage((string) $action->target_url);
            $applier->apply($action);

            $action->status = SeoAction::STATUS_APPLIED;
            $action->auto_applied = false;
            $action->applied_at = now();
            $action->baseline_value = $this->probe->scalar($baseline, (string) $action->metric);
            $action->baseline_at = now();
            $action->measure_after = now()->addDays(MetricProbe::MEASURE_AFTER_DAYS);
            $action->outcome = SeoAction::OUTCOME_PENDING;
            $action->error = null;
            $action->save();

            return true;
        } catch (Throwable $e) {
            $action->status = SeoAction::STATUS_FAILED;
            $action->error = Str::limit($e->getMessage(), 480, '');
            $action->save();

            return false;
        }
    }

    /** Revert a single applied action (used by the admin panel and safety net). */
    public function revert(SeoAction $action): void
    {
        if (! $action->isRevertible()) {
            return;
        }
        $applier = $this->appliers()[$action->category] ?? null;
        if (! $applier) {
            return;
        }
        $applier->revert($action);
        $action->status = SeoAction::STATUS_REVERTED;
        $action->reverted_at = now();
        $action->save();
    }

    // ---------------------------------------------------------------------
    // Phase 3 — measure + learn
    // ---------------------------------------------------------------------

    /**
     * Re-measure applied actions past their window and record the outcome.
     *
     * @return array{measured:int,worked:int,regressed:int,no_effect:int}
     */
    public function measure(): array
    {
        $due = SeoAction::dueForMeasurement()->get();
        $worked = $regressed = $noEffect = 0;

        foreach ($due as $action) {
            $metric = (string) ($action->metric ?: 'clicks');
            $sample = $this->probe->forPage((string) $action->target_url);
            $after = $this->probe->scalar($sample, $metric);
            $before = (float) ($action->baseline_value ?? 0.0);

            $outcome = $this->judge($before, $after, $metric, $sample);
            $delta = $this->deltaPct($before, $after, $metric);

            $action->measured_value = $after;
            $action->measured_at = now();
            $action->delta_pct = $delta;
            $action->outcome = $outcome;
            $action->save();

            match ($outcome) {
                SeoAction::OUTCOME_WORKED => $worked++,
                SeoAction::OUTCOME_REGRESSED => $regressed++,
                default => $noEffect++,
            };
        }

        return ['measured' => $due->count(), 'worked' => $worked, 'regressed' => $regressed, 'no_effect' => $noEffect];
    }

    /**
     * Self-improving weight: categories that have historically WORKED on this
     * site are scored up, ones that REGRESSED are scored down. Neutral (1.0)
     * until there's enough measured history to trust.
     */
    public function learnedWeight(string $category): float
    {
        $rows = SeoAction::where('category', $category)
            ->whereIn('outcome', [SeoAction::OUTCOME_WORKED, SeoAction::OUTCOME_NO_EFFECT, SeoAction::OUTCOME_REGRESSED])
            ->get(['outcome']);

        $total = $rows->count();
        if ($total < 3) {
            return 1.0;
        }

        $worked = $rows->where('outcome', SeoAction::OUTCOME_WORKED)->count();
        $regressed = $rows->where('outcome', SeoAction::OUTCOME_REGRESSED)->count();
        $score = ($worked - $regressed) / $total; // -1..1

        return round(max(0.5, min(1.5, 1 + $score * 0.5)), 3);
    }

    // ---------------------------------------------------------------------
    // Scoring + helpers
    // ---------------------------------------------------------------------

    private function rescoreOpenActions(): void
    {
        foreach (SeoAction::open()->get() as $action) {
            $conf = $this->baseConfidence($action->category) * $this->learnedWeight($action->category);
            $ease = $this->baseEase($action->category);
            $action->confidence = round($conf, 3);
            $action->ease = $ease;
            // Priority = estimated impact × confidence × ease (interpretable).
            $action->priority = round(((float) $action->impact_score) * $conf * $ease, 3);
            $action->saveQuietly();
        }
    }

    private function baseConfidence(string $category): float
    {
        return match ($category) {
            'llms_regen' => 0.8,
            'reindex' => 0.5,
            'title_meta' => 0.55, // map-pack caps organic-CTR recovery
            'create_page' => 0.45, // new page; upside real but slower + needs review
            'content_refresh' => 0.45, // deeper, query-led copy on a page that already ranks
            default => 0.4,
        };
    }

    private function baseEase(string $category): float
    {
        return match ($category) {
            'reindex', 'llms_regen' => 1.0,
            'title_meta' => 0.9,
            'create_page' => 0.7,
            default => 0.5,
        };
    }

    private function expectedCtr(float $position): float
    {
        $p = (int) round($position);
        if ($p <= 10) {
            return self::CTR_CURVE[max(1, $p)] ?? 0.02;
        }
        return $p <= 15 ? 0.015 : 0.008;
    }

    /**
     * Resolve a full page URL to [HasSEO model, serviceSlug|null], or null when
     * the page isn't a model we can safely rewrite.
     *
     * @return array{0:\Illuminate\Database\Eloquent\Model,1:?string}|null
     */
    private function resolveTarget(string $url): ?array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        if (($segments[0] ?? null) === 'areas-served' && isset($segments[1])) {
            $area = AreaServed::where('slug', $segments[1])->first();
            if (! $area) {
                return null;
            }
            // /areas-served/{slug}/services/{service}
            if (($segments[2] ?? null) === 'services' && isset($segments[3])) {
                return isset(TitleMetaGenerator::SERVICES[$segments[3]]) ? [$area, $segments[3]] : null;
            }
            // /areas-served/{slug}
            if (count($segments) === 2) {
                return [$area, null];
            }
            return null;
        }

        if (($segments[0] ?? null) === 'projects' && isset($segments[1]) && count($segments) === 2) {
            $project = Project::where('slug', $segments[1])->first();
            return $project ? [$project, null] : null;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $attrs
     */
    private function upsertAction(array $attrs): int
    {
        $fingerprint = $attrs['fingerprint'];
        $existing = SeoAction::where('fingerprint', $fingerprint)->first();

        if (! $existing) {
            try {
                SeoAction::create($attrs);

                return 1;
            } catch (\Illuminate\Database\QueryException $e) {
                // Unique-key race: a concurrent run inserted the same fingerprint
                // between our check and create (production briefly ran the
                // scheduler twice). Fall through and treat it as existing.
                $existing = SeoAction::where('fingerprint', $fingerprint)->first();
                if (! $existing) {
                    throw $e;
                }
            }
        }

        // Only refresh still-open proposals; never disturb applied/measured
        // or human-decided (skipped/reverted) rows.
        if ($existing->status === SeoAction::STATUS_PROPOSED) {
            $existing->fill([
                'title' => $attrs['title'],
                'hypothesis' => $attrs['hypothesis'],
                'payload' => $attrs['payload'],
                'impact_score' => $attrs['impact_score'],
            ])->save();
        }

        return 0;
    }

    private function judge(float $before, float $after, string $metric, array $sample): string
    {
        // Nothing to compare against and still nothing now → inconclusive.
        if ($before <= 0 && $after <= 0) {
            return ($sample['impressions'] ?? 0) > 0 ? SeoAction::OUTCOME_NO_EFFECT : SeoAction::OUTCOME_INCONCLUSIVE;
        }

        // A page with almost no traffic cannot produce a trustworthy verdict.
        //
        // Without this floor, 0 impressions → 1 impression scored +100% and was
        // filed as WORKED, and 1 → 301 scored +30000%. Every one of the 59
        // measured "reindex" actions on production started from <= 1 impression,
        // 11 were recorded as wins, and the dashboard reported an average of
        // +2818% — while the MEDIAN effect across all of them was exactly 0%.
        // learnedWeight() then scored the category up on that noise, so the
        // autopilot kept proposing more of it: a loop that taught itself from
        // rounding error.
        if ($before < $this->minMeaningfulBaseline($metric)) {
            return SeoAction::OUTCOME_INCONCLUSIVE;
        }

        $delta = $this->deltaPct($before, $after, $metric);
        if ($delta >= 15) {
            return SeoAction::OUTCOME_WORKED;
        }
        if ($delta <= -15) {
            return SeoAction::OUTCOME_REGRESSED;
        }

        return SeoAction::OUTCOME_NO_EFFECT;
    }

    /**
     * Smallest "before" value at which a percentage change means anything.
     *
     * Chosen so a single stray impression or click cannot clear the ±15% bar in
     * judge(). Search Console rounds and samples low-volume data heavily, so
     * anything under these floors is indistinguishable from noise.
     */
    private function minMeaningfulBaseline(string $metric): float
    {
        return match ($metric) {
            'clicks' => 3.0,
            'ctr' => 3.0,          // a CTR built on 1-2 clicks swings wildly
            'position' => 10.0,    // average position over <10 impressions is unstable
            default => 10.0,       // impressions
        };
    }

    /** Signed % improvement (position is inverted so "up" always means better). */
    private function deltaPct(float $before, float $after, string $metric): float
    {
        if ($this->probe->lowerIsBetter($metric)) {
            [$before, $after] = [$after, $before]; // improvement = position went down
        }
        if ($before <= 0) {
            return $after > 0 ? 100.0 : 0.0;
        }

        return round((($after - $before) / $before) * 100, 1);
    }

    private function fp(string $source, string $category, string $key): string
    {
        return sha1($source . '|' . $category . '|' . $key);
    }

    /**
     * Parse a GSC query into [serviceSlug, cityDisplay, modifier|null], or null
     * when it doesn't clearly name both a service and a known city.
     *
     * @param array<string,string> $knownCities lower => Display
     * @return array{0:string,1:string,2:?string}|null
     */
    /**
     * Public face of the query parser: [service slug, City, modifier|null] or
     * null — the same rule the demand-gap and research loops use.
     *
     * @return array{0:string,1:string,2:?string}|null
     */
    public function classify(string $query): ?array
    {
        return $this->parseQuery($query, $this->knownCities());
    }

    private function parseQuery(string $query, array $knownCities): ?array
    {
        // Real queries arrive as "barrington, il", "mt. prospect", "park
        // ridge, illinois" — commas glued to the city name made every one of
        // them unmatchable, and "mt."/"ft." never equal "mount"/"fort".
        $q = Str::lower($query);
        $q = str_replace([',', '.'], ' ', $q);
        $q = preg_replace('/\bmt\b/', 'mount', $q);
        $q = preg_replace('/\bft\b/', 'fort', $q);
        $q = ' ' . preg_replace('/\s+/', ' ', trim($q)) . ' ';

        $service = null;
        foreach (self::SERVICE_KEYWORDS as $kw => $slug) {
            if (str_contains($q, $kw)) {
                $service = $slug;
                break;
            }
        }
        if ($service === null) {
            return null;
        }

        // Longest city names first so "arlington heights" wins over "heights".
        $city = null;
        foreach ($knownCities as $lower => $display) {
            if (str_contains($q, ' ' . $lower . ' ')) {
                $city = $display;
                break;
            }
        }
        if ($city === null) {
            return null;
        }

        $modifier = null;
        foreach (self::MODIFIER_KEYWORDS as $kw => $mod) {
            if (str_contains($q, $kw)) {
                $modifier = $mod;
                break;
            }
        }

        return [$service, $city, $modifier];
    }

    /** @return array<string,string> lower => Display, longest-first for matching */
    /**
     * Real towns bordering the service area that have no AreaServed row.
     * CURATED on purpose: parseQuery only ever matches against this list plus
     * our own tables, so a query like "bathroom remodel tarzana" (a far-away
     * town we accidentally rank #71 for) can never spawn a page. The proof
     * gate would pass it — proof falls back to nearby same-type projects —
     * so the lexicon is the geographic fence.
     */
    private const ADJACENT_CITIES = [
        'Wauconda', 'Island Lake', 'Cary', 'Fox River Valley Gardens', 'Grayslake',
        'Round Lake', 'Lake Villa', 'Lindenhurst', 'Waukegan', 'North Chicago',
        'Highwood', 'Golf', 'Bannockburn', 'Mettawa',
        'Itasca', 'Wood Dale', 'Bensenville', 'Addison', 'Villa Park', 'Lombard',
        'Elmhurst', 'Roselle', 'Bloomingdale', 'Medinah', 'Hanover Park', 'Bartlett',
        'Carol Stream', 'Franklin Park', 'Stone Park', 'Berkeley', 'Hillside',
        'Westchester', 'Bellwood', 'Maywood', 'Broadview', 'La Grange',
        'La Grange Park', 'Brookfield', 'Riverside', 'Berwyn',
    ];

    private function knownCities(): array
    {
        $cities = [];
        foreach (AreaServed::pluck('city') as $c) {
            $cities[Str::lower(trim((string) $c))] = trim((string) $c);
        }
        foreach (self::ADJACENT_CITIES as $c) {
            $cities[Str::lower($c)] = $cities[Str::lower($c)] ?? $c;
        }
        // Cities we have project proof in but that may not be AreaServed rows.
        foreach (\App\Models\Project::whereNotNull('location')->pluck('location') as $loc) {
            $cityPart = trim((string) Str::of((string) $loc)->before(','));
            if ($cityPart !== '') {
                $cities[Str::lower($cityPart)] = $cities[Str::lower($cityPart)] ?? $cityPart;
            }
        }
        uksort($cities, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $cities;
    }

    /** @return array<string,bool> set of AreaServed city names (lower) */
    private function areaCityKeys(): array
    {
        $out = [];
        foreach (AreaServed::pluck('city') as $c) {
            $out[Str::lower(trim((string) $c))] = true;
        }

        return $out;
    }
}
