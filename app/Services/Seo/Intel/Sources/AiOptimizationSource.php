<?php

namespace App\Services\Seo\Intel\Sources;

use App\Models\AreaServed;
use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GEO layer: how much demand for our services runs through AI answer engines
 * (ChatGPT, Google AI) and whether they cite us instead of a competitor.
 * Classic SERP tracking says nothing about this — a homeowner who asks
 * ChatGPT "best kitchen remodeler near me" never touches Google's ten blue
 * links, so this is the only signal we have on that channel.
 *
 * Two DataForSEO calls per run:
 *  - ai_optimization/ai_keyword_data/keywords_search_volume/live ($0.01/task
 *    + $0.0001/keyword): our tracked keyword universe's estimated monthly
 *    AI-search volume, one task (up to 1,000 keywords).
 *  - ai_optimization/llm_mentions/multi_target_metrics/live ($0.1/task +
 *    ~$0.001/row): our brand plus the top map-pack competitors, one task
 *    (up to 10 targets) — total mentions, per-platform split, and the
 *    domains AI answers cite as sources for each.
 *
 * Findings: an AI keyword's volume jumping (demand shift worth a page/content
 * refresh), a rising-volume keyword naming a town we serve that has no area
 * page yet (a page to build), our own mentions falling or recovering, and
 * "citation gap" domains — sources AI engines cite for a competitor but
 * never for us, the GEO equivalent of a backlink gap.
 */
class AiOptimizationSource extends IntelSource
{
    private const CORE_PHRASES = [
        'kitchen remodeling near me',
        'bathroom remodeling near me',
        'remodeling contractor near me',
        'kitchen remodel cost',
        'bathroom remodel cost',
    ];

    private const TOWN_SERVICES = ['kitchen remodeling', 'bathroom remodeling'];

    public function family(): string
    {
        return 'ai_optimization';
    }

    public function label(): string
    {
        return 'AI Optimization (GEO)';
    }

    public function estimateCost(): float
    {
        $keywords = count($this->keywordSet());
        $kwCost = $keywords > 0 ? 0.01 + $keywords * 0.0001 : 0.0;
        $targets = 1 + (int) $this->config('competitors', 5); // us + competitors
        // $0.001/row billed per row DataForSEO returns per target, not per target
        // itself — actual row counts are unbounded and unknown ahead of the call,
        // so this is a structural approximation (not just data staleness). Only
        // used for the pre-run balance guard; collect() enforces max_cost against
        // $this->dfs->spent() from the real response cost, not this estimate.
        $mentionsCost = 0.1 + $targets * 0.001;

        return round($kwCost + $mentionsCost, 4);
    }

    public function collect(): array
    {
        $spentAtStart = $this->dfs->spent();
        $maxCost = (float) $this->config('max_cost', 0.4);

        $snapshots = $this->collectKeywordVolume($spentAtStart, $maxCost);

        if (($this->dfs->spent() - $spentAtStart) < $maxCost) {
            $snapshots = array_merge($snapshots, $this->collectMentions());
        }

        if ($snapshots === [] && $this->dfs->getLastError()) {
            throw new \RuntimeException('AI Optimization collect failed: ' . $this->dfs->getLastError());
        }

        return $snapshots;
    }

    public function findings(): array
    {
        return array_merge($this->keywordFindings(), $this->mentionFindings());
    }

    public function report(): array
    {
        $latestKw = $this->latestSet('keyword');
        $prevKw = $this->previousSet('keyword');
        $volNow = $latestKw->sum(fn ($s) => $s['metrics']['ai_search_volume'] ?? 0);
        $volPrev = $prevKw->isNotEmpty() ? $prevKw->sum(fn ($s) => $s['metrics']['ai_search_volume'] ?? 0) : null;

        $brand = (string) config('brand.name');
        $latestMentions = $this->latestSet('mentions');
        $prevMentions = $this->previousSet('mentions');
        $ourNow = $latestMentions->get($brand)['metrics']['mentions_total'] ?? null;
        $ourPrev = $prevMentions->get($brand)['metrics']['mentions_total'] ?? null;

        $gap = $this->citationGap($latestMentions, $brand);

        $keywordRows = $latestKw->sortByDesc(fn ($s) => $s['metrics']['ai_search_volume'] ?? 0)->take(12)
            ->map(function (array $s, string $keyword) use ($prevKw) {
                $now = (int) ($s['metrics']['ai_search_volume'] ?? 0);
                $prevVal = $prevKw->get($keyword)['metrics']['ai_search_volume'] ?? null;
                $trend = ($prevVal !== null && $prevVal > 0) ? sprintf('%+d%%', round((($now - $prevVal) / $prevVal) * 100)) : '—';

                return [$keyword, $now, $trend];
            })->values()->all();

        $sourceRows = $gap->sortByDesc('competitor_cites')->take(12)
            ->map(fn ($e) => [$e['domain'], $e['competitor_cites'], $e['cites_us'] ? '✓' : '✗'])->values()->all();

        $day = $this->store->latestDay($this->family());
        $note = $day
            ? sprintf('AI Optimization last measured %s: %d keywords tracked, %d mention targets.', $day, $latestKw->count(), $latestMentions->count())
            : 'No AI Optimization data collected yet.';

        if ($latestKw->isEmpty() && $latestMentions->isEmpty()) {
            return ['tiles' => [], 'tables' => [], 'note' => $note];
        }

        return [
            'tiles' => [
                ['label' => 'AI search volume', 'value' => $latestKw->isNotEmpty() ? (int) $volNow : null, 'prev' => $volPrev !== null ? (int) $volPrev : null, 'good' => 'up'],
                ['label' => 'Keywords tracked', 'value' => $latestKw->isNotEmpty() ? $latestKw->count() : null],
                ['label' => 'Our LLM mentions', 'value' => $ourNow !== null ? (int) $ourNow : null, 'prev' => $ourPrev !== null ? (int) $ourPrev : null, 'good' => 'up'],
                ['label' => 'Citation gap domains', 'value' => $gap->isNotEmpty() ? $gap->count() : null],
            ],
            'tables' => array_values(array_filter([
                $keywordRows !== [] ? ['title' => 'Top AI-volume keywords', 'columns' => ['Keyword', 'AI volume', 'Trend'], 'rows' => $keywordRows] : null,
                $sourceRows !== [] ? ['title' => 'Citation sources', 'columns' => ['Domain', 'Cites competitors', 'Cites us'], 'rows' => $sourceRows] : null,
            ])),
            'note' => $note,
        ];
    }

    // --- collect helpers ------------------------------------------------

    protected function collectKeywordVolume(float $spentAtStart, float $maxCost): array
    {
        $keywords = $this->keywordSet();
        if ($keywords === []) {
            return [];
        }
        $towns = $this->servedTowns();
        $out = [];
        foreach (array_chunk($keywords, 1000) as $chunk) {
            if (($this->dfs->spent() - $spentAtStart) >= $maxCost) {
                break;
            }
            $data = $this->dfs->request('POST', '/ai_optimization/ai_keyword_data/keywords_search_volume/live', [[
                'keywords' => $chunk,
                'location_code' => 2840,
                'language_code' => 'en',
            ]]);
            $items = (array) (DataForSeoService::resultOf($data)[0]['items'] ?? []);
            foreach ($items as $item) {
                $keyword = mb_strtolower(trim((string) ($item['keyword'] ?? '')));
                if ($keyword === '') {
                    continue;
                }
                $out[] = new Snapshot('keyword', $keyword, [
                    'ai_search_volume' => (int) ($item['ai_search_volume'] ?? 0),
                ], [
                    'ai_monthly_searches' => array_slice((array) ($item['ai_monthly_searches'] ?? []), -6),
                    'town' => $this->matchTown($keyword, $towns),
                    'service' => $this->matchService($keyword),
                ]);
            }
        }

        return $out;
    }

    protected function collectMentions(): array
    {
        $brand = (string) config('brand.name');
        if ($brand === '') {
            return [];
        }
        $names = array_values(array_unique(array_filter(array_merge(
            [$brand],
            $this->competitorNames((int) $this->config('competitors', 5))
        ))));
        // DataForSEO requires 2-10 targets ("not less than 2"); with no map-pack
        // competitors on record (fresh site, empty table, competitors=>0) $names
        // would be just [$brand], a single target the API would reject. Bail out
        // before spending the $0.1 base request cost on a call that can't work.
        if (count($names) < 2) {
            return [];
        }
        $targets = [];
        foreach (array_slice($names, 0, 10) as $name) {
            $targets[] = [
                'key' => mb_substr($name, 0, 191),
                'target' => [['keyword' => $name, 'search_scope' => ['answer'], 'search_filter' => 'include']],
            ];
        }
        $data = $this->dfs->request('POST', '/ai_optimization/llm_mentions/multi_target_metrics/live', [[
            'targets' => $targets,
            'location_code' => 2840,
            'language_code' => 'en',
        ]]);
        $items = (array) (DataForSeoService::resultOf($data)[0]['items'] ?? []);
        $out = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $metrics = [
                'mentions_total' => (int) ($item['total']['mentions'] ?? 0),
                'ai_search_volume_total' => (int) ($item['total']['ai_search_volume'] ?? 0),
            ];
            foreach ((array) ($item['platform'] ?? []) as $p) {
                $slug = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower((string) ($p['key'] ?? ''))) ?? '', '_');
                if ($slug !== '') {
                    $metrics['mentions_platform_' . $slug] = (int) ($p['mentions'] ?? 0);
                }
            }
            $sources = [];
            foreach ((array) ($item['sources_domain'] ?? []) as $s) {
                $domain = mb_strtolower((string) ($s['key'] ?? ''));
                if ($domain === '') {
                    continue;
                }
                $sources[] = ['domain' => $domain, 'mentions' => (int) ($s['mentions'] ?? 0)];
            }
            $out[] = new Snapshot('mentions', $key, $metrics, ['sources_domain' => $sources]);
        }

        return $out;
    }

    /** Brand-name candidates for the tracked keyword universe. */
    protected function keywordSet(): array
    {
        $configured = array_values(array_filter(array_map('trim', (array) $this->config('keywords', []))));
        $base = $configured !== [] ? $configured : $this->topOpportunityKeywords();

        $townPhrases = [];
        foreach ($this->towns() as $town) {
            foreach (self::TOWN_SERVICES as $service) {
                $townPhrases[] = "{$service} {$town}";
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($k) => mb_strtolower(trim((string) $k)),
            array_merge($base, self::CORE_PHRASES, $townPhrases)
        ))));
    }

    protected function topOpportunityKeywords(): array
    {
        if (! Schema::hasTable('seo_keywords')) {
            return [];
        }

        return Tenancy::table('seo_keywords')->orderByDesc('opportunity')
            ->limit((int) $this->config('keyword_limit', 100))->pluck('keyword')->all();
    }

    /**
     * The six towns with the most projects — AreaServed::coreTowns() called
     * directly, not $this->coreTowns(): the shared helper maps each entry
     * through ->name, but AreaServed::coreTowns() already returns plain city
     * strings, so ->name silently reads as null on every town. See
     * shared_changes_needed.
     */
    protected function towns(): array
    {
        return Schema::hasTable('areas_served') ? AreaServed::coreTowns(6) : [];
    }

    /** Every town we serve, whether or not it has an area page: for matching AI keywords, not for page-existence checks. */
    protected function servedTowns(): array
    {
        $fromAreas = Schema::hasTable('areas_served') ? Tenancy::table('areas_served')->pluck('city') : collect();
        $fromConfig = collect((array) config('gbp-services.service_areas', []))
            ->map(fn ($s) => trim(explode(',', (string) $s)[0] ?? ''))
            ->filter(fn ($s) => $s !== '' && ! str_contains(mb_strtolower($s), 'county'));

        return $fromAreas->concat($fromConfig)->map(fn ($c) => trim((string) $c))->filter()->unique()->values()->all();
    }

    /** Top competitor names by map-pack strength (pack_points), for the mentions comparison. */
    protected function competitorNames(int $limit): array
    {
        if (! Schema::hasTable('map_pack_competitors')) {
            return [];
        }

        return Tenancy::table('map_pack_competitors')->whereNotNull('name')->where('name', '!=', '')
            ->select('name', DB::raw('SUM(pack_points) as w'))->groupBy('name')->orderByDesc('w')
            ->limit($limit)->pluck('name')->all();
    }

    protected function areaExists(string $town): bool
    {
        if (! Schema::hasTable('areas_served')) {
            return false;
        }
        $needle = mb_strtolower(trim($town));

        return Tenancy::table('areas_served')->get('city')
            ->contains(fn ($row) => mb_strtolower(trim((string) $row->city)) === $needle);
    }

    /** The longest-matching served town whose name appears in the keyword, or null. */
    protected function matchTown(string $keyword, array $towns): ?string
    {
        $kw = mb_strtolower($keyword);
        usort($towns, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        foreach ($towns as $town) {
            if ($town !== '' && str_contains($kw, mb_strtolower((string) $town))) {
                return (string) $town;
            }
        }

        return null;
    }

    /** Which of our four core service pages a keyword is about, if determinable. */
    protected function matchService(string $keyword): ?string
    {
        $kw = mb_strtolower($keyword);

        return match (true) {
            str_contains($kw, 'kitchen') => 'kitchen-remodeling',
            str_contains($kw, 'bathroom') || str_contains($kw, 'bath') => 'bathroom-remodeling',
            str_contains($kw, 'basement') => 'basement-remodeling',
            str_contains($kw, 'addition') => 'home-additions',
            default => null,
        };
    }

    // --- findings helpers -------------------------------------------------

    protected function keywordFindings(): array
    {
        $latest = $this->latestSet('keyword');
        $previous = $this->previousSet('keyword');
        $out = [];

        foreach ($latest as $subject => $snap) {
            $prevSnap = $previous->get($subject);
            if ($prevSnap === null) {
                continue;
            }
            $now = (float) ($snap['metrics']['ai_search_volume'] ?? 0);
            $prev = (float) ($prevSnap['metrics']['ai_search_volume'] ?? 0);
            if ($prev > 0 && $now >= $prev * 1.3) {
                $out[] = $this->finding(
                    'ai_volume_rising', Finding::INFO, "AI search volume rising for \"{$subject}\"",
                    sprintf('AI search volume rose from %d/mo to %d/mo.', $prev, $now), $subject, null,
                    ['ai_search_volume' => ['prev' => $prev, 'now' => $now]]
                );
            }
        }

        $maxFindings = (int) $this->config('max_findings', 10);
        $townsWeServe = $this->servedTowns();
        $n = 0;
        foreach ($latest->sortByDesc(fn ($s) => $s['metrics']['ai_search_volume'] ?? 0) as $subject => $snap) {
            if ($n >= $maxFindings) {
                break;
            }
            $town = (string) ($snap['payload']['town'] ?? $this->matchTown($subject, $townsWeServe) ?? '');
            if ($town === '' || $this->areaExists($town)) {
                continue;
            }
            $service = (string) ($snap['payload']['service'] ?? $this->matchService($subject) ?? '');
            $action = $service !== '' ? ['type' => 'create_page', 'town' => $town, 'service' => $service] : null;
            $out[] = $this->finding(
                'underserved_town_keyword', Finding::INFO, "No page for \"{$subject}\" ({$town})",
                sprintf('AI search volume %d/mo for a keyword naming %s, a town we serve with no area page yet.', (int) ($snap['metrics']['ai_search_volume'] ?? 0), $town),
                $subject, $town, [], $action
            );
            $n++;
        }

        return $out;
    }

    protected function mentionFindings(): array
    {
        $brand = (string) config('brand.name');
        $latest = $this->latestSet('mentions');
        $previous = $this->previousSet('mentions');
        $out = [];

        $ourLatest = $latest->get($brand);
        $ourPrev = $previous->get($brand);
        if ($ourLatest && $ourPrev) {
            $now = (int) ($ourLatest['metrics']['mentions_total'] ?? 0);
            $prev = (int) ($ourPrev['metrics']['mentions_total'] ?? 0);
            if ($now < $prev) {
                $out[] = $this->finding('mentions_drop', Finding::WARN, 'Our LLM mentions fell', "From {$prev} to {$now}.", $brand, null,
                    ['mentions_total' => ['prev' => $prev, 'now' => $now]], ['type' => 'llms_regen']);
            } elseif ($now > $prev) {
                $out[] = $this->finding('mentions_up', Finding::WIN, 'Our LLM mentions rose', "From {$prev} to {$now}.", $brand, null,
                    ['mentions_total' => ['prev' => $prev, 'now' => $now]]);
            }
        }

        $maxFindings = min(10, (int) $this->config('max_findings', 10));
        $n = 0;
        foreach ($this->citationGap($latest, $brand)->sortByDesc('competitor_cites') as $domain => $entry) {
            if ($n >= $maxFindings) {
                break;
            }
            $out[] = $this->finding(
                'citation_gap', Finding::INFO, "AI engines cite {$domain} for competitors, never us",
                "Cited by {$entry['competitor_cites']} competitor mention(s) in AI answers, never by ours.", $domain, null, [],
                ['type' => 'llms_regen']
            );
            $n++;
        }

        return $out;
    }

    /**
     * Domains AI answers cite as a source for a competitor but never for us,
     * keyed by domain: ['domain' => ..., 'competitor_cites' => int, 'cites_us' => bool].
     */
    protected function citationGap(\Illuminate\Support\Collection $mentionsSet, string $brand): \Illuminate\Support\Collection
    {
        $ourDomains = collect($mentionsSet->get($brand)['payload']['sources_domain'] ?? [])
            ->pluck('domain')->map(fn ($d) => mb_strtolower((string) $d));

        $gap = collect();
        foreach ($mentionsSet as $name => $snap) {
            if ($name === $brand) {
                continue;
            }
            foreach ((array) ($snap['payload']['sources_domain'] ?? []) as $s) {
                $domain = mb_strtolower((string) ($s['domain'] ?? ''));
                if ($domain === '' || $ourDomains->contains($domain)) {
                    continue;
                }
                $entry = $gap->get($domain, ['domain' => $domain, 'competitor_cites' => 0, 'cites_us' => false]);
                $entry['competitor_cites']++;
                $gap->put($domain, $entry);
            }
        }

        return $gap;
    }
}
