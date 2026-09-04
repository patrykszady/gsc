<?php

namespace App\Services\Seo\Intel\Sources;

use App\Models\AreaServed;
use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\Snapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * Google Trends (Keywords Data API) for our core service phrases: whether
 * demand is currently above or below its own 12-month baseline, and which
 * related searches are rising — so content refreshes and ad timing follow
 * real seasonal demand instead of a hunch, and a rising "{service} near
 * {town}" query becomes a candidate town/service page before a competitor
 * writes it first.
 *
 * Endpoint: POST /keywords_data/google_trends/explore/live, one task per
 * phrase (~$0.011/task — DataForSEO's Keywords Data pricing page). The docs
 * for this endpoint cap topics_list/queries_list to a single keyword per
 * task ("To obtain topics_list and queries_list, specify no more than 1
 * keyword"), so phrases are NOT batched 5-to-a-task even though the graph
 * item alone would allow it — each call asks for graph + queries_list +
 * topics_list together for one phrase. location_code 2840 (United States):
 * the Trends locations list is country/city grained, not state-level, so
 * "Illinois" alone is not an accepted location — see caveats.
 *
 * Action hints: seasonal_upswing's content_refresh only ever points at
 * '/areas-served/{slug}' — the one shape SeoAutopilotService::resolveTarget()
 * resolves for that action — and only when the phrase itself names a served
 * town that has an AreaServed row (areaForTown()); a phrase with no town in
 * it (the common case — "kitchen remodel" names no town) carries no action
 * at all rather than a path resolveTarget() can never resolve. Earlier this
 * pointed at '/services/{slug}', a shape resolveTarget() never recognizes,
 * so the hint silently never produced an SeoAction; fixed here. create_page
 * (on rising_local_query) is unaffected by that bug — SeoAutopilotService's
 * create_page case takes town/service strings, not a path, though it validates
 * the town against a narrower list (config('gbp-services.service_areas'),
 * 18 towns) than serviceTowns() matches against (that list unioned with
 * AreaServed::coreTowns(6)), so a core town outside the GBP list can still
 * open a create_page finding whose action quietly never becomes an SeoAction.
 */
class TrendsSource extends IntelSource
{
    /** phrase substring => [service slug, its named route], for the create_page action hint (serviceFor()'s 'path' is no longer used — see class docblock). */
    private const SERVICE_ROUTES = [
        'kitchen' => ['slug' => 'kitchen-remodeling', 'route' => 'services.kitchen'],
        'bathroom' => ['slug' => 'bathroom-remodeling', 'route' => 'services.bathroom'],
        'basement' => ['slug' => 'basement-remodeling', 'route' => 'services.basement'],
        'addition' => ['slug' => 'home-additions', 'route' => 'services.additions'],
    ];

    public function family(): string
    {
        return 'trends';
    }

    public function label(): string
    {
        return 'Google Trends';
    }

    public function estimateCost(): float
    {
        return round(count($this->phrases()) * 0.011, 4);
    }

    /** @return Snapshot[] */
    public function collect(): array
    {
        $spentAtStart = $this->dfs->spent();
        $maxCost = (float) $this->config('max_cost', 0.06);
        $location = (int) $this->config('location_code', 2840);
        $snapshots = [];

        foreach ($this->phrases() as $phrase) {
            if (($this->dfs->spent() - $spentAtStart) >= $maxCost) {
                break;
            }

            $envelope = $this->dfs->request('POST', '/keywords_data/google_trends/explore/live', [[
                'keywords' => [$phrase],
                'location_code' => $location,
                'language_code' => 'en',
                'type' => 'web',
                'time_range' => 'past_12_months',
                'item_types' => ['google_trends_graph', 'google_trends_queries_list', 'google_trends_topics_list'],
            ]]);
            $items = (array) (DataForSeoService::resultOf($envelope)[0]['items'] ?? []);
            if ($items === []) {
                continue;
            }

            $snapshots[] = $this->snapshotFor($phrase, $items);
        }

        if ($snapshots === [] && $this->dfs->getLastError()) {
            throw new \RuntimeException('Google Trends: '.$this->dfs->getLastError());
        }

        return $snapshots;
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $max = (int) $this->config('max_findings', 15);
        $towns = $this->serviceTowns();
        $out = [];

        foreach ($this->latestSet('phrase') as $subject => $snap) {
            if (count($out) >= $max) {
                break;
            }
            $metrics = $snap['metrics'];
            $avg12 = (float) ($metrics['interest_avg_12m'] ?? 0);
            $avg4 = (float) ($metrics['interest_4w_avg'] ?? 0);
            $service = $this->serviceFor($subject);
            $refreshArea = $this->areaForTown($this->matchTown($subject, $towns));

            if ($avg12 > 0 && $avg4 >= $avg12 * 1.25) {
                $out[] = $this->finding(
                    'seasonal_upswing', Finding::INFO, "Demand rising for \"{$subject}\"",
                    sprintf('4-week average interest (%.0f) is %.0f%% above the 12-month average (%.0f).', $avg4, (($avg4 / $avg12) - 1) * 100, $avg12),
                    $subject, null, ['interest_4w_avg' => ['prev' => $avg12, 'now' => $avg4]],
                    $refreshArea ? ['type' => 'content_refresh', 'path' => '/areas-served/'.$refreshArea->slug, 'phrases' => [$subject]] : null,
                );
            } elseif ($avg12 > 0 && $avg4 <= $avg12 * 0.75) {
                $out[] = $this->finding(
                    'seasonal_downswing', Finding::INFO, "Demand cooling for \"{$subject}\"",
                    sprintf('4-week average interest (%.0f) is %.0f%% below the 12-month average (%.0f).', $avg4, (1 - $avg4 / $avg12) * 100, $avg12),
                    $subject, null, ['interest_4w_avg' => ['prev' => $avg12, 'now' => $avg4]],
                );
            }

            foreach ((array) ($snap['payload']['rising_queries'] ?? []) as $rq) {
                if (count($out) >= $max) {
                    break;
                }
                $query = trim((string) ($rq['query'] ?? ''));
                if ($query === '') {
                    continue;
                }
                $value = (string) ($rq['value'] ?? '');
                $town = $this->matchTown($query, $towns);
                $isBreakout = strcasecmp($value, 'Breakout') === 0;

                // "detox centers near me" rises next to "home addition" too — local intent alone is not enough.
                if (($town !== null || stripos($query, 'near me') !== false) && self::isTradeQuery($query)) {
                    $action = ($town !== null && $service) ? ['type' => 'create_page', 'town' => $town, 'service' => $service['slug']] : null;
                    $out[] = $this->finding(
                        'rising_local_query', Finding::INFO, "Rising local search: \"{$query}\"",
                        "Related to \"{$subject}\", currently rising".($isBreakout ? ' (breakout)' : " ({$value}% increase)").'.',
                        $subject, $query, [], $action,
                    );
                    if (count($out) >= $max) {
                        break;
                    }
                }
                if ($isBreakout) {
                    $out[] = $this->finding(
                        'breakout_query', Finding::INFO, "Breakout query: \"{$query}\"",
                        "New breakout related search for \"{$subject}\" — went from little to no prior volume to a surge.",
                        $subject, $query,
                    );
                }
            }
        }

        return $out;
    }

    public function report(): array
    {
        $set = $this->latestSet('phrase');
        if ($set->isEmpty()) {
            return ['tiles' => [], 'tables' => [], 'note' => 'No Google Trends data collected yet.'];
        }

        $rising = 0;
        $breakout = 0;
        $rows = [];
        foreach ($set as $subject => $snap) {
            $metrics = $snap['metrics'];
            $avg12 = (float) ($metrics['interest_avg_12m'] ?? 0);
            $avg4 = (float) ($metrics['interest_4w_avg'] ?? 0);
            if ($avg12 > 0 && $avg4 >= $avg12 * 1.25) {
                $rising++;
            }
            foreach ((array) ($snap['payload']['rising_queries'] ?? []) as $rq) {
                if (strcasecmp((string) ($rq['value'] ?? ''), 'Breakout') === 0) {
                    $breakout++;
                }
            }
            $arrow = $avg12 > 0 ? ($avg4 >= $avg12 * 1.1 ? '↑' : ($avg4 <= $avg12 * 0.9 ? '↓' : '→')) : '—';
            $rows[] = [$subject, (int) round($metrics['interest_now'] ?? 0), round($avg12, 1), $arrow, $snap['payload']['rising_queries'][0]['query'] ?? '—'];
        }

        $day = $this->store->latestDay($this->family(), 'phrase');

        return [
            'tiles' => [
                ['label' => 'Phrases tracked', 'value' => $set->count()],
                ['label' => 'Rising phrases', 'value' => $rising, 'good' => 'up'],
                ['label' => 'Breakout queries', 'value' => $breakout, 'good' => 'up'],
            ],
            'tables' => [[
                'title' => 'Search interest by phrase (US, 12 months)',
                'columns' => ['Phrase', 'Interest now', '12-mo avg', 'Trend', 'Top rising query'],
                'rows' => $rows,
            ]],
            'note' => "Google Trends, United States, past 12 months, as of {$day}.",
        ];
    }

    /** @return string[] */
    protected function phrases(): array
    {
        return array_values(array_filter(array_map('trim', (array) $this->config(
            'phrases', ['kitchen remodel', 'bathroom remodel', 'basement finishing', 'home addition', 'kitchen remodeling contractor']
        ))));
    }

    /** One Snapshot from a phrase's graph + queries_list + topics_list items. */
    protected function snapshotFor(string $phrase, array $items): Snapshot
    {
        $points = [];
        $topQueries = [];
        $risingQueries = [];
        $topTopics = [];

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type === 'google_trends_graph') {
                foreach ((array) ($item['data'] ?? []) as $point) {
                    if (($point['missing_data'] ?? false) === true) {
                        continue;
                    }
                    $value = $point['values'][0] ?? null;
                    if (! is_numeric($value)) {
                        continue;
                    }
                    $points[] = ['date' => (string) ($point['date_from'] ?? ''), 'value' => (float) $value];
                }
            } elseif ($type === 'google_trends_queries_list') {
                $data = (array) ($item['data'] ?? []);
                foreach ((array) ($data['top'] ?? []) as $q) {
                    $topQueries[] = ['query' => (string) ($q['query'] ?? ''), 'value' => (string) ($q['value'] ?? '')];
                }
                foreach ((array) ($data['rising'] ?? []) as $q) {
                    $risingQueries[] = ['query' => (string) ($q['query'] ?? ''), 'value' => (string) ($q['value'] ?? '')];
                }
            } elseif ($type === 'google_trends_topics_list') {
                foreach ((array) (($item['data'] ?? [])['top'] ?? []) as $t) {
                    $topTopics[] = (string) ($t['topic_title'] ?? '');
                }
            }
        }

        // The docs describe each point's fields but don't explicitly guarantee
        // array order; the example response is chronological ascending, but
        // sort defensively rather than trust it — end()/array_slice(-4) below
        // both assume oldest-to-newest.
        usort($points, fn ($a, $b) => $a['date'] <=> $b['date']);

        $now = $points === [] ? 0.0 : end($points)['value'];
        $avg12 = $points === [] ? 0.0 : array_sum(array_column($points, 'value')) / count($points);
        $last4 = array_slice($points, -4);
        $avg4 = $last4 === [] ? 0.0 : array_sum(array_column($last4, 'value')) / count($last4);
        $peak = null;
        foreach ($points as $p) {
            if ($peak === null || $p['value'] > $peak['value']) {
                $peak = $p;
            }
        }
        $peakIndex = $peak['value'] ?? 0.0;
        $peakMonth = $peak && $peak['date'] !== '' ? Carbon::parse($peak['date'])->format('M Y') : null;

        return new Snapshot('phrase', $phrase, [
            'interest_now' => round($now, 1),
            'interest_avg_12m' => round($avg12, 1),
            'interest_4w_avg' => round($avg4, 1),
            'peak_index' => round($peakIndex, 1),
        ], [
            'peak_month' => $peakMonth,
            'top_queries' => array_slice($topQueries, 0, 5),
            'rising_queries' => array_slice($risingQueries, 0, 10),
            'top_topics' => array_slice(array_values(array_filter($topTopics)), 0, 5),
        ]);
    }

    /** The service a phrase is about, when one exists — for the create_page action hint. */
    protected function serviceFor(string $phrase): ?array
    {
        $p = mb_strtolower($phrase);
        foreach (self::SERVICE_ROUTES as $needle => $info) {
            if (str_contains($p, $needle) && Route::has($info['route'])) {
                return ['slug' => $info['slug'], 'path' => '/services/'.$info['slug']];
            }
        }

        return null;
    }

    /** Core towns plus GBP service-area cities (counties excluded — they aren't a page-able town). */
    /** Mentions what we do — remodeling, renovation, contracting, the rooms we build. */
    public static function isTradeQuery(string $query): bool
    {
        return (bool) preg_match('/remodel|renovat|contractor|construction|builder|kitchen|bath|basement|addition|home improvement|design.build|general contract/i', $query);
    }

    protected function serviceTowns(): array
    {
        $towns = AreaServed::coreTowns(6);
        foreach ((array) config('gbp-services.service_areas', []) as $entry) {
            if (stripos((string) $entry, 'county') !== false) {
                continue;
            }
            $name = trim(explode(',', (string) $entry)[0] ?? '');
            if ($name !== '') {
                $towns[] = $name;
            }
        }

        return array_values(array_unique(array_filter($towns)));
    }

    protected function matchTown(string $query, array $towns): ?string
    {
        foreach ($towns as $town) {
            if ($town !== '' && stripos($query, $town) !== false) {
                return $town;
            }
        }

        return null;
    }

    /**
     * The AreaServed row for a matched town, when one actually exists — for
     * the seasonal_upswing content_refresh action hint. SeoAutopilotService's
     * content_refresh case (resolveTarget()) only ever resolves a bare
     * '/areas-served/{slug}' path to an AreaServed model with no service
     * segment, so a hint is only worth emitting when the row exists to slug
     * from; a guessed slug (e.g. Str::slug($town)) could point at nothing and
     * silently no-op downstream same as the bug this replaces.
     */
    protected function areaForTown(?string $town): ?AreaServed
    {
        return $town === null ? null : AreaServed::where('city', $town)->first();
    }
}
