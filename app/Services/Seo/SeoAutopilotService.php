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
    public const SAFE_ALLOWLIST = ['title_meta', 'reindex', 'llms_regen', 'create_page'];

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
        $created += $this->synthesizeLlmsRefresh();
        $created += $this->synthesizeCreatePage();

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

        $queries = DB::table('gsc_query_metrics')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('query')
            ->havingRaw('SUM(impressions) >= 60')
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
    private function synthesizeTitleMeta(): int
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return 0;
        }

        $end = Carbon::today();
        $start = $end->copy()->subDays(MetricProbe::WINDOW_DAYS - 1);

        $pages = DB::table('gsc_query_metrics')
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
            ->limit(15)
            ->get(['url', 'coverage_state', 'verdict']);

        $created = 0;
        foreach ($rows as $row) {
            $url = (string) $row->url;
            if ($url === '') {
                continue;
            }

            $created += $this->upsertAction([
                'fingerprint' => $this->fp('coverage_error', 'reindex', $url),
                'source' => 'coverage_error',
                'category' => 'reindex',
                'risk' => SeoAction::RISK_SAFE,
                'target_url' => $url,
                'title' => 'Reindex: ' . Str::of($url)->after(self::BASE_URL),
                'hypothesis' => sprintf('Coverage verdict "%s" / state "%s" — resubmit to IndexNow to prompt a re-crawl.', $row->verdict ?? '?', $row->coverage_state ?? '?'),
                'metric' => 'impressions',
                'payload' => ['url' => $url, 'coverage_state' => $row->coverage_state],
                'impact_score' => 5.0,
            ]);
        }

        return $created;
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

        $candidates = SeoAction::open()
            ->whereIn('category', self::SAFE_ALLOWLIST)
            ->orderByDesc('priority')
            ->limit($maxApplies)
            ->get();

        $appliers = $this->appliers();
        $applied = $failed = 0;
        $items = [];

        foreach ($candidates as $action) {
            $applier = $appliers[$action->category] ?? null;
            if (! $applier) {
                continue;
            }

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

        if ($existing) {
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

        SeoAction::create($attrs);

        return 1;
    }

    private function judge(float $before, float $after, string $metric, array $sample): string
    {
        // Nothing to compare against and still nothing now → inconclusive.
        if ($before <= 0 && $after <= 0) {
            return ($sample['impressions'] ?? 0) > 0 ? SeoAction::OUTCOME_NO_EFFECT : SeoAction::OUTCOME_INCONCLUSIVE;
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
    private function parseQuery(string $query, array $knownCities): ?array
    {
        $q = ' ' . Str::lower($query) . ' ';

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
    private function knownCities(): array
    {
        $cities = [];
        foreach (AreaServed::pluck('city') as $c) {
            $cities[Str::lower(trim((string) $c))] = trim((string) $c);
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
