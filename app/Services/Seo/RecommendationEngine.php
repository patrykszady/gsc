<?php

namespace App\Services\Seo;

use App\Models\GscCoverageState;
use App\Models\SeoAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Generates the SEO report's "Priority Actions" and "Where the clicks come
 * from next" from LIVE data instead of hardcoded strings, and performs safe,
 * idempotent self-healing (dispatching stale syncs, pruning retired coverage
 * rows) so the advice list shrinks on its own as problems resolve.
 *
 * Every rule is individually try/caught: one broken data source degrades one
 * line, never the whole report. Output is persisted to
 * storage/app/seo/recommendations.json by the seo:recommendations-refresh
 * command (scheduled daily) and read by the admin SeoReports page.
 */
class RecommendationEngine
{
    public const STORAGE_PATH = 'seo/recommendations.json';

    /** @var array<int, string> */
    private array $healed = [];

    /**
     * @return array{
     *   generated_at:string,
     *   healed:array<int,string>,
     *   action_items:array<int,string>,
     *   recommendations:array<int,array{t:string,d:string,p:string}>
     * }
     */
    public function refresh(bool $allowHealing = true): array
    {
        $this->healed = [];

        if ($allowHealing) {
            $this->rule(fn () => $this->healStaleInspection());
            $this->rule(fn () => $this->healRetiredCoverageRows());
            $this->rule(fn () => $this->healStaleReports());
            // Same enablement gates as the scheduled sync entries in
            // routes/console.php — never queue a sync whose API is not set up.
            if (config('services.google.search_console.enabled')) {
                $this->rule(fn () => $this->healStaleChannelSync('gsc_daily_totals', 'seo:gsc-sync', ['--days' => 7]));
            }
            if (! empty(config('services.bing.webmaster_api_key'))) {
                $this->rule(fn () => $this->healStaleChannelSync('bing_daily_totals', 'seo:bing-sync', []));
            }
        }

        $actionItems = [];
        $this->rule(function () use (&$actionItems): void {
            $actionItems = array_merge($actionItems, $this->coverageActionItems());
        });
        $this->rule(function () use (&$actionItems): void {
            $actionItems = array_merge($actionItems, $this->brokenPageActionItems());
        });
        $this->rule(function () use (&$actionItems): void {
            $actionItems = array_merge($actionItems, $this->clickDropActionItems());
        });
        $this->rule(function () use (&$actionItems): void {
            $actionItems = array_merge($actionItems, $this->channelFreshnessActionItems());
        });

        if ($actionItems === []) {
            $actionItems[] = 'No urgent anomalies. Nightly inspection, change-aware reindex, and weekly pruning are all running — the ledger is working through improvements automatically.';
        }

        $recommendations = [];
        $this->rule(function () use (&$recommendations): void {
            $recommendations = array_merge($recommendations, $this->strikingDistanceRecs());
        });
        $this->rule(function () use (&$recommendations): void {
            $recommendations = array_merge($recommendations, $this->demandNoClickRecs());
        });
        $this->rule(function () use (&$recommendations): void {
            $recommendations = array_merge($recommendations, $this->clusterAlarmRecs());
        });
        $this->rule(function () use (&$recommendations): void {
            $recommendations = array_merge($recommendations, $this->decayRecs());
        });
        $this->rule(function () use (&$recommendations): void {
            $recommendations = array_merge($recommendations, $this->provenWinRecs());
        });
        $this->rule(function () use (&$recommendations): void {
            $recommendations = array_merge($recommendations, $this->reviewVelocityRecs());
        });
        $this->rule(function () use (&$recommendations): void {
            $recommendations = array_merge($recommendations, $this->liveFaqCheckRecs());
        });

        if ($recommendations === []) {
            $recommendations[] = [
                't' => 'Keep compounding what works',
                'd' => 'No open strategic gaps detected this cycle. Review volume, local content depth, and citations remain the long-term levers.',
                'p' => 'next',
            ];
        }

        // "Do now" first, cap the list so the panel stays scannable.
        usort($recommendations, fn ($a, $b) => ($a['p'] === 'now' ? 0 : 1) <=> ($b['p'] === 'now' ? 0 : 1));
        $recommendations = array_slice($recommendations, 0, 8);
        $actionItems = array_slice($actionItems, 0, 8);

        // Publish the striking-distance + decaying pages as a machine-readable
        // list; the <x-priority-area-links /> component renders it on
        // high-authority pages, so "deepen internal links on these first"
        // happens by itself and refreshes daily.
        $this->rule(fn () => $this->publishPriorityPages());

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'healed' => $this->healed,
            'action_items' => $actionItems,
            'recommendations' => $recommendations,
        ];

        Storage::disk('local')->put(self::STORAGE_PATH, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($this->healed !== []) {
            Log::info('seo:recommendations-refresh self-healed', ['healed' => $this->healed]);
        }

        return $payload;
    }

    /**
     * @return array{generated_at:string,healed:array<int,string>,action_items:array<int,string>,recommendations:array<int,array{t:string,d:string,p:string}>}|null
     */
    public static function latest(): ?array
    {
        if (! Storage::disk('local')->exists(self::STORAGE_PATH)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get(self::STORAGE_PATH), true);

        return is_array($decoded) && isset($decoded['action_items'], $decoded['recommendations']) ? $decoded : null;
    }

    /** Run one rule; a failing rule logs and degrades to nothing. */
    private function rule(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('RecommendationEngine rule failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Self-healing ────────────────────────────────────────────────────────

    private function healStaleInspection(): void
    {
        if (! Schema::hasTable('gsc_coverage_states') || ! config('services.google.search_console.enabled')) {
            return;
        }

        $latest = GscCoverageState::query()->max('inspected_at');
        if ($latest && Carbon::parse($latest)->gt(now()->subHours(48))) {
            return;
        }

        \App\Jobs\RunGscInspectBulkJob::dispatch();
        $this->healed[] = 'URL inspection data was stale (>48h) — queued a background sweep.';
    }

    private function healRetiredCoverageRows(): void
    {
        if (! Schema::hasTable('gsc_coverage_states')) {
            return;
        }

        $path = public_path('sitemap.xml');
        if (! is_file($path)) {
            return;
        }
        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml || ! isset($xml->url) || count($xml->url) === 0) {
            return;
        }
        $inSitemap = [];
        foreach ($xml->url as $u) {
            $inSitemap[(string) $u->loc] = true;
        }

        $retired = 0;
        GscCoverageState::query()->select(['id', 'url'])->chunkById(500, function ($rows) use ($inSitemap, &$retired): void {
            foreach ($rows as $r) {
                if (! isset($inSitemap[(string) $r->url])) {
                    $retired++;
                }
            }
        });

        if ($retired >= 100) {
            Artisan::call('seo:gsc-prune-retired');
            $this->healed[] = "Pruned {$retired} retired coverage rows (URLs no longer in the sitemap).";
        }
    }

    /**
     * Write the internal-link boost list: striking-distance pages (positions
     * 8–20 with real demand) plus fresh decay losers, as path + label pairs
     * for the priority-area-links component.
     */
    private function publishPriorityPages(): void
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return;
        }

        $since = now()->subDays(28)->toDateString();
        $pages = DB::table('gsc_query_metrics')
            ->where('date', '>=', $since)
            ->whereNotNull('page')
            ->where('query', 'not like', '%gs construction%')
            ->where('page', 'like', '%/areas-served/%')
            ->selectRaw('page, SUM(impressions) imp, SUM(position*impressions)/NULLIF(SUM(impressions),0) pos, MAX(impressions) peak_day')
            ->groupBy('page')
            ->havingRaw('imp >= 80 AND pos BETWEEN 8 AND 20')
            ->havingRaw('peak_day < imp * 0.4')
            ->orderByDesc('imp')
            ->limit(8)
            ->pluck('page');

        $items = [];
        foreach ($pages as $page) {
            $path = parse_url((string) $page, PHP_URL_PATH);
            if (! is_string($path) || ! preg_match('#^/areas-served/([^/]+)(?:/services/([^/]+))?$#', $path, $m)) {
                continue;
            }
            $city = ucwords(str_replace('-', ' ', $m[1]));
            $service = isset($m[2]) ? ucwords(str_replace('-', ' ', $m[2])) : null;
            $items[] = [
                'path' => $path,
                'label' => $service ? "{$city} {$service}" : "{$city} Remodeling",
            ];
        }

        // GBP focus towns: page-1 visibility with zero organic clicks — the
        // social-post picker biases photo freshness toward these towns so the
        // Business Profile shows recent local work where the Maps pack is
        // taking our clicks.
        $gbpFocusTowns = DB::table('gsc_query_metrics')
            ->where('date', '>=', $since)
            ->where('page', 'like', '%/areas-served/%')
            ->where('query', 'not like', '%gs construction%')
            ->selectRaw('page, SUM(impressions) imp, SUM(clicks) clk, SUM(position*impressions)/NULLIF(SUM(impressions),0) pos, MAX(impressions) peak_day')
            ->groupBy('page')
            ->havingRaw('imp >= 80 AND clk = 0 AND pos <= 12')
            ->havingRaw('peak_day < imp * 0.4')
            ->orderByDesc('imp')
            ->limit(6)
            ->pluck('page')
            ->map(fn ($p) => ucwords(str_replace('-', ' ', (string) preg_replace('#^/areas-served/([^/]+).*$#', '$1', (string) parse_url((string) $p, PHP_URL_PATH)))))
            ->filter()
            ->unique()
            ->values()
            ->all();

        Storage::disk('local')->put('seo/priority-pages.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'items' => array_slice($items, 0, 6),
            'gbp_focus_towns' => $gbpFocusTowns,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        \Illuminate\Support\Facades\Cache::forget('priority_area_links');
    }

    /**
     * Regenerate any registered markdown report that has gone stale — the
     * safety net when a schedule entry silently breaks (and the freshness
     * path for machines that don't run the scheduler at all).
     */
    private function healStaleReports(): void
    {
        $queued = [];
        foreach ((array) config('seo-reports.reports', []) as $key => $meta) {
            $path = "reports/{$key}.md";
            $mtime = Storage::disk('local')->exists($path) ? Storage::disk('local')->lastModified($path) : null;
            if ($mtime !== null && $mtime >= now()->subHours(30)->getTimestamp()) {
                continue;
            }
            \App\Jobs\RunSeoChannelSyncJob::dispatch((string) $meta['command']);
            $queued[] = $key;
        }

        if ($queued !== []) {
            $this->healed[] = 'Queued regeneration of ' . count($queued) . ' stale report(s): ' . implode(', ', $queued) . '.';
        }
    }

    /** @param array<string, mixed> $options */
    private function healStaleChannelSync(string $table, string $command, array $options): void
    {
        if (! Schema::hasTable($table) || ! collect(Artisan::all())->has($command)) {
            return;
        }

        $maxDate = DB::table($table)->max('date');
        if ($maxDate && Carbon::parse($maxDate)->gt(now()->subDays(4))) {
            return;
        }

        // Dedicated job with a realistic timeout — Artisan::queue()'s generic
        // wrapper dies at the worker's 60s limit mid-pull and retries 5×.
        \App\Jobs\RunSeoChannelSyncJob::dispatch($command, $options);
        $this->healed[] = "{$table} was stale (latest: " . ($maxDate ?: 'never') . ") — queued {$command}.";
    }

    // ── Priority Actions (ops layer) ────────────────────────────────────────

    /** @return array<int, string> */
    private function coverageActionItems(): array
    {
        if (! Schema::hasTable('gsc_coverage_states')) {
            return [];
        }

        $problem = (int) GscCoverageState::query()
            ->where(fn ($q) => $q->where('verdict', '!=', 'PASS')->orWhereNull('verdict'))
            ->count();
        if ($problem === 0) {
            return [];
        }

        $openReindex = 0;
        $appliedWeek = 0;
        $openClusters = 0;
        if (Schema::hasTable('seo_actions')) {
            $openReindex = (int) SeoAction::where('category', 'reindex')->where('status', SeoAction::STATUS_PROPOSED)->count();
            $appliedWeek = (int) SeoAction::where('category', 'reindex')->where('applied_at', '>=', now()->subDays(7))->count();
            $openClusters = (int) SeoAction::where('source', 'coverage_cluster')->where('status', SeoAction::STATUS_PROPOSED)->count();
        }

        $items = [];
        $items[] = "{$problem} URLs have non-pass coverage. Autopilot handles resubmission automatically ({$appliedWeek} resubmitted this week, {$openReindex} queued) — pages are re-nudged whenever their content changes.";
        if ($openClusters > 0) {
            $items[] = "{$openClusters} template-family index alarm(s) open in the ledger — those families need content differentiation (a human call), not resubmission.";
        }

        return $items;
    }

    /** @return array<int, string> */
    private function brokenPageActionItems(): array
    {
        if (! Schema::hasTable('gsc_coverage_states')) {
            return [];
        }

        $path = public_path('sitemap.xml');
        if (! is_file($path)) {
            return [];
        }
        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml || ! isset($xml->url)) {
            return [];
        }
        $inSitemap = [];
        foreach ($xml->url as $u) {
            $inSitemap[(string) $u->loc] = true;
        }

        $broken = GscCoverageState::query()
            ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not found%'])
            ->pluck('url')
            ->filter(fn ($u) => isset($inSitemap[(string) $u]))
            ->map(fn ($u) => parse_url((string) $u, PHP_URL_PATH) ?: (string) $u)
            ->values();

        if ($broken->isEmpty()) {
            return [];
        }

        return [
            'URGENT: ' . $broken->count() . ' sitemap URL(s) return 404 to Google: '
            . $broken->take(3)->implode(', ')
            . ($broken->count() > 3 ? '…' : '')
            . ' — a live sitemap URL should never 404; fix the route or remove it from the sitemap.',
        ];
    }

    /** @return array<int, string> */
    private function clickDropActionItems(): array
    {
        if (! Schema::hasTable('gsc_daily_totals')) {
            return [];
        }

        $maxDate = DB::table('gsc_daily_totals')->max('date');
        if (! $maxDate) {
            return [];
        }
        $end = Carbon::parse($maxDate);
        $recent = (int) DB::table('gsc_daily_totals')->whereBetween('date', [(clone $end)->subDays(6)->toDateString(), $end->toDateString()])->sum('clicks');
        $prior = (int) DB::table('gsc_daily_totals')->whereBetween('date', [(clone $end)->subDays(13)->toDateString(), (clone $end)->subDays(7)->toDateString()])->sum('clicks');

        if ($prior < 20 || $recent >= $prior * 0.85) {
            return [];
        }

        $losing = DB::table('gsc_query_metrics')
            ->whereBetween('date', [(clone $end)->subDays(13)->toDateString(), $end->toDateString()])
            ->selectRaw('query, SUM(CASE WHEN date >= ? THEN clicks ELSE 0 END) rc, SUM(CASE WHEN date < ? THEN clicks ELSE 0 END) pc', [
                (clone $end)->subDays(6)->toDateString(),
                (clone $end)->subDays(6)->toDateString(),
            ])
            ->groupBy('query')
            ->havingRaw('pc >= 3 AND rc < pc')
            ->orderByRaw('(pc - rc) DESC')
            ->limit(3)
            ->pluck('query');

        $pct = (int) round((($prior - $recent) / $prior) * 100);

        return [
            "Google clicks down {$pct}% week-over-week ({$prior} → {$recent})."
            . ($losing->isNotEmpty() ? ' Biggest losing queries: ' . $losing->implode(', ') . '.' : '')
            . ' Decay monitoring flags the affected pages; title reverts happen automatically if an experiment caused it.',
        ];
    }

    /** @return array<int, string> */
    private function channelFreshnessActionItems(): array
    {
        $items = [];
        foreach (['gsc_daily_totals' => 'Google', 'bing_daily_totals' => 'Bing', 'gbp_daily_metrics' => 'GBP'] as $table => $label) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $maxDate = DB::table($table)->max('date');
            if (! $maxDate || Carbon::parse($maxDate)->lt(now()->subDays(4))) {
                $items[] = "{$label} metrics are stale (latest: " . ($maxDate ?: 'never') . '). The daily refresh re-queues the sync when stale — if this persists more than a day, check API credentials.';
            }
        }

        return $items;
    }

    // ── Recommendations (strategy layer) ────────────────────────────────────

    /**
     * Striking-distance pages: positions 8–20 with real, non-burst demand.
     * Rank-tracker bots produce one-week impression bursts; requiring clicks
     * OR a low peak-day share filters them out.
     *
     * @return array<int, array{t:string,d:string,p:string}>
     */
    private function strikingDistanceRecs(): array
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return [];
        }

        $since = now()->subDays(28)->toDateString();
        $pages = DB::table('gsc_query_metrics')
            ->where('date', '>=', $since)
            ->whereNotNull('page')
            ->where('query', 'not like', '%gs construction%')
            ->selectRaw('page, SUM(impressions) imp, SUM(clicks) clk, SUM(position*impressions)/NULLIF(SUM(impressions),0) pos, MAX(impressions) peak_day')
            ->groupBy('page')
            ->havingRaw('imp >= 100 AND pos BETWEEN 8 AND 20')
            ->havingRaw('(clk > 0 OR peak_day < imp * 0.4)')
            ->orderByDesc('imp')
            ->limit(3)
            ->get();

        if ($pages->isEmpty()) {
            return [];
        }

        $titleTests = Schema::hasTable('seo_actions')
            ? (int) SeoAction::where('category', 'title_meta')
                ->whereIn('status', [SeoAction::STATUS_PROPOSED, SeoAction::STATUS_APPLIED])
                ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', SeoAction::OUTCOME_PENDING))
                ->count()
            : 0;

        $list = $pages->map(function ($p) {
            $path = parse_url((string) $p->page, PHP_URL_PATH) ?: (string) $p->page;

            return sprintf('%s (pos %.1f, %d impr)', $path, (float) $p->pos, (int) $p->imp);
        })->implode('; ');

        return [[
            't' => 'Push striking-distance pages to page 1',
            'd' => "Real demand at positions 8–20: {$list}. Deepen content and internal links on these first — autopilot has {$titleTests} title experiment(s) in flight.",
            'p' => 'now',
        ]];
    }

    /** @return array<int, array{t:string,d:string,p:string}> */
    private function demandNoClickRecs(): array
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return [];
        }

        $since = now()->subDays(28)->toDateString();
        $towns = DB::table('gsc_query_metrics')
            ->where('date', '>=', $since)
            ->where('page', 'like', '%/areas-served/%')
            ->where('query', 'not like', '%gs construction%')
            ->selectRaw('page, SUM(impressions) imp, SUM(clicks) clk, SUM(position*impressions)/NULLIF(SUM(impressions),0) pos, MAX(impressions) peak_day')
            ->groupBy('page')
            ->havingRaw('imp >= 80 AND clk = 0 AND pos <= 12')
            ->havingRaw('peak_day < imp * 0.4')
            ->orderByDesc('imp')
            ->limit(4)
            ->get()
            ->map(function ($r) {
                $path = parse_url((string) $r->page, PHP_URL_PATH) ?: '';

                return ucwords(str_replace('-', ' ', (string) preg_replace('#^/areas-served/([^/]+).*$#', '$1', $path)));
            })
            ->filter()
            ->unique()
            ->values();

        if ($towns->isEmpty()) {
            return [];
        }

        return [[
            't' => 'Win the local pack where we rank but get no clicks',
            'd' => 'Page-1 visibility with zero organic clicks in ' . $towns->implode(', ') . ' — the Maps 3-pack takes those clicks. Push GBP review volume, weekly posts, and photo freshness for these towns.',
            'p' => 'now',
        ]];
    }

    /** @return array<int, array{t:string,d:string,p:string}> */
    private function clusterAlarmRecs(): array
    {
        if (! Schema::hasTable('seo_actions')) {
            return [];
        }

        return SeoAction::where('source', 'coverage_cluster')
            ->where('status', SeoAction::STATUS_PROPOSED)
            ->orderByDesc('impact_score')
            ->limit(2)
            ->get()
            ->map(fn ($a) => [
                't' => (string) $a->title,
                'd' => (string) $a->hypothesis,
                'p' => 'now',
            ])
            ->all();
    }

    /** @return array<int, array{t:string,d:string,p:string}> */
    private function decayRecs(): array
    {
        if (! Schema::hasTable('gsc_query_metrics')) {
            return [];
        }

        $maxDate = DB::table('gsc_query_metrics')->max('date');
        if (! $maxDate) {
            return [];
        }
        $end = Carbon::parse($maxDate);
        $agg = fn ($from, $to) => DB::table('gsc_query_metrics')
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('page')
            ->selectRaw('page, SUM(impressions) imp')
            ->groupBy('page')
            ->pluck('imp', 'page');
        $prior = $agg((clone $end)->subDays(13)->toDateString(), (clone $end)->subDays(7)->toDateString());
        $recent = $agg((clone $end)->subDays(6)->toDateString(), $end->toDateString());

        $losers = collect($prior)
            ->map(function ($imp, $page) use ($recent) {
                return ['path' => parse_url((string) $page, PHP_URL_PATH) ?: (string) $page, 'drop' => (int) $imp - (int) ($recent[$page] ?? 0), 'prior' => (int) $imp];
            })
            ->filter(fn ($r) => $r['drop'] >= 50 && $r['drop'] >= $r['prior'] * 0.5)
            ->sortByDesc('drop')
            ->take(3)
            ->values();

        if ($losers->isEmpty()) {
            return [];
        }

        return [[
            't' => 'Refresh pages losing visibility',
            'd' => 'Impressions halved week-over-week on: '
                . $losers->map(fn ($l) => "{$l['path']} (−{$l['drop']})")->implode('; ')
                . '. Any content update automatically triggers a re-crawl request via the change-aware reindexer.',
            'p' => 'next',
        ]];
    }

    /** @return array<int, array{t:string,d:string,p:string}> */
    private function provenWinRecs(): array
    {
        if (! Schema::hasTable('seo_actions')) {
            return [];
        }

        $wins = SeoAction::where('outcome', SeoAction::OUTCOME_WORKED)
            ->where('measured_at', '>=', now()->subDays(45))
            ->selectRaw('category, COUNT(*) n, AVG(COALESCE(delta_pct, 0)) avg_delta')
            ->groupBy('category')
            ->orderByDesc('n')
            ->first();

        if (! $wins || (int) $wins->n < 2) {
            return [];
        }

        return [[
            't' => 'Double down on what measurably worked',
            'd' => sprintf(
                '%d "%s" action(s) measured as wins recently (avg %+.0f%% on their metric). The autopilot keeps generating these — approve open proposals of this type first.',
                (int) $wins->n,
                (string) $wins->category,
                (float) $wins->avg_delta
            ),
            'p' => 'next',
        ]];
    }

    /** @return array<int, array{t:string,d:string,p:string}> */
    private function reviewVelocityRecs(): array
    {
        if (! Schema::hasTable('testimonials')) {
            return [];
        }

        $recent = (int) DB::table('testimonials')->where('review_date', '>=', now()->subDays(60)->toDateString())->count();
        $prior = (int) DB::table('testimonials')
            ->whereBetween('review_date', [now()->subDays(120)->toDateString(), now()->subDays(61)->toDateString()])
            ->count();

        if ($prior === 0 || $recent >= $prior) {
            return [];
        }

        $missingEmails = Schema::hasColumn('projects', 'client_email')
            ? (int) DB::table('projects')->where('is_published', 1)->whereNull('client_email')->count()
            : null;

        return [[
            't' => 'Review velocity is slowing',
            'd' => "{$recent} new reviews in the last 60 days vs {$prior} in the prior 60. Review volume is the #1 local-pack lever."
                . ($missingEmails !== null && $missingEmails > 0
                    ? " The automated review-request emails can cover more: {$missingEmails} published project(s) have no client email on file."
                    : ' Automated review-request emails are running — consider a personal follow-up round.'),
            'p' => 'now',
        ]];
    }

    /**
     * Live replacement for the old hardcoded "/faq is 410'd" advice — only
     * appears while the condition is actually true.
     *
     * @return array<int, array{t:string,d:string,p:string}>
     */
    private function liveFaqCheckRecs(): array
    {
        $status = Http::timeout(8)->get(url('/faq'))->status();
        if ($status < 400) {
            return [];
        }

        return [[
            't' => 'Restore a real FAQ page for AI answers',
            'd' => "/faq currently returns HTTP {$status}, yet Q&A content is what AI Overviews, ChatGPT and Perplexity cite most. Rebuild it with FAQPage schema.",
            'p' => 'next',
        ]];
    }
}
