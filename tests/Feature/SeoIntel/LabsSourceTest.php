<?php

namespace Tests\Feature\SeoIntel;

use App\Models\AreaServed;
use App\Services\Seo\Intel\Sources\LabsSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LabsSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [LabsSource::class],
            'gbp-services.service_areas' => ['Palatine, IL, USA', 'Arlington Heights, IL, USA'],
            'seo-intel.families.labs' => [
                'competitor_limit' => 5,
                'gap_competitors' => 1,
                'gap_limit_per_pair' => 50,
                'historical_months' => 3,
                'relevant_pages_limit' => 10,
                'traffic_targets' => 3,
                'max_cost' => 2,
                'max_gap_findings' => 15,
                'new_competitor_top_n' => 10,
                'etv_swing_pct' => 0.15,
                'page_drop_pct' => 0.3,
            ],
        ]);

        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine']);
        AreaServed::create(['city' => 'Arlington Heights', 'slug' => 'arlington-heights']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(array $result, float $cost = 0.02): array
    {
        return ['tasks' => [['cost' => $cost, 'status_code' => 20000, 'result' => [$result]]]];
    }

    private function fakeTwoRuns(): void
    {
        Http::fake([
            '*appendix/user_data*' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 500]]]]]]),

            '*dataforseo_labs/google/competitors_domain/live*' => Http::sequence()
                ->push($this->task(['items' => [
                    ['domain' => 'bright-stone.com', 'intersections' => 40, 'avg_position' => 8.2, 'full_domain_metrics' => ['organic' => ['count' => 500, 'etv' => 1200.0]]],
                    ['domain' => 'suburbanremodel.com', 'intersections' => 15, 'avg_position' => 12.0, 'full_domain_metrics' => ['organic' => ['count' => 300, 'etv' => 700.0]]],
                ]]))
                ->push($this->task(['items' => [
                    ['domain' => 'bright-stone.com', 'intersections' => 40, 'avg_position' => 8.2, 'full_domain_metrics' => ['organic' => ['count' => 500, 'etv' => 1200.0]]],
                    ['domain' => 'suburbanremodel.com', 'intersections' => 15, 'avg_position' => 12.0, 'full_domain_metrics' => ['organic' => ['count' => 300, 'etv' => 700.0]]],
                    ['domain' => 'newrivalco.com', 'intersections' => 45, 'avg_position' => 6.5, 'full_domain_metrics' => ['organic' => ['count' => 600, 'etv' => 1600.0]]],
                ]])),

            '*dataforseo_labs/google/domain_intersection/live*' => Http::sequence()
                ->push($this->task(['items' => [
                    // Local trade keyword, town + service both matched — create_page.
                    ['keyword_data' => ['keyword' => 'kitchen remodeling palatine', 'keyword_info' => ['search_volume' => 150]], 'first_domain_serp_element' => ['type' => 'organic', 'rank_absolute' => 5], 'second_domain_serp_element' => null],
                    // Local trade keyword, town matched but not in AreaServed and no recognized service — no action.
                    ['keyword_data' => ['keyword' => 'remodeling contractor arlington heights', 'keyword_info' => ['search_volume' => 90]], 'first_domain_serp_element' => ['type' => 'organic', 'rank_absolute' => 8], 'second_domain_serp_element' => null],
                    // Contains a competitor brand token ("bright"/"stone") — must be excluded even though it matches trade + geo.
                    ['keyword_data' => ['keyword' => 'bright stone kitchen palatine', 'keyword_info' => ['search_volume' => 300]], 'first_domain_serp_element' => ['type' => 'organic', 'rank_absolute' => 3], 'second_domain_serp_element' => null],
                    // National keyword, no town/geo — must be excluded.
                    ['keyword_data' => ['keyword' => 'kitchen cabinets', 'keyword_info' => ['search_volume' => 1000]], 'first_domain_serp_element' => ['type' => 'organic', 'rank_absolute' => 2], 'second_domain_serp_element' => null],
                    // Local trade keyword via "near me", no recognized town or service — no action.
                    ['keyword_data' => ['keyword' => 'general contractor near me', 'keyword_info' => ['search_volume' => 70]], 'first_domain_serp_element' => ['type' => 'organic', 'rank_absolute' => 9], 'second_domain_serp_element' => null],
                    // Ranks outside the top 20 — must be excluded by the client-side rank filter.
                    ['keyword_data' => ['keyword' => 'bathroom remodeling palatine', 'keyword_info' => ['search_volume' => 60]], 'first_domain_serp_element' => ['type' => 'organic', 'rank_absolute' => 34], 'second_domain_serp_element' => null],
                ]]))
                ->push($this->task(['items' => [
                    ['keyword_data' => ['keyword' => 'kitchen remodeling palatine', 'keyword_info' => ['search_volume' => 160]], 'first_domain_serp_element' => ['type' => 'organic', 'rank_absolute' => 4], 'second_domain_serp_element' => null],
                    // A brand-new gap keyword this run.
                    ['keyword_data' => ['keyword' => 'basement remodeling arlington heights', 'keyword_info' => ['search_volume' => 120]], 'first_domain_serp_element' => ['organic' => ['rank_absolute' => 6]], 'second_domain_serp_element' => null],
                ]])),

            '*dataforseo_labs/google/historical_rank_overview/live*' => Http::sequence()
                ->push($this->task(['items' => [
                    ['year' => 2026, 'month' => 8, 'metrics' => ['organic' => ['count' => 300, 'etv' => 1500.0, 'pos_1' => 8, 'pos_2_3' => 20, 'pos_4_10' => 50]]],
                    ['year' => 2026, 'month' => 7, 'metrics' => ['organic' => ['count' => 280, 'etv' => 1400.0, 'pos_1' => 7, 'pos_2_3' => 18, 'pos_4_10' => 48]]],
                ]]))
                ->push($this->task(['items' => [
                    // ETV falls 20% month over month — should open a WARN.
                    ['year' => 2026, 'month' => 9, 'metrics' => ['organic' => ['count' => 250, 'etv' => 1200.0, 'pos_1' => 6, 'pos_2_3' => 15, 'pos_4_10' => 40]]],
                    ['year' => 2026, 'month' => 8, 'metrics' => ['organic' => ['count' => 300, 'etv' => 1500.0, 'pos_1' => 8, 'pos_2_3' => 20, 'pos_4_10' => 50]]],
                ]])),

            '*dataforseo_labs/google/relevant_pages/live*' => Http::sequence()
                ->push($this->task(['items' => [
                    ['page_address' => 'https://gs.construction/kitchen-remodeling', 'metrics' => ['organic' => ['count' => 40, 'etv' => 200.0, 'pos_1' => 2, 'pos_2_3' => 5, 'pos_4_10' => 10]]],
                ]]))
                ->push($this->task(['items' => [
                    // Loses more than 30% of its ranking keywords — should open a WARN with content_refresh.
                    ['page_address' => 'https://gs.construction/kitchen-remodeling', 'metrics' => ['organic' => ['count' => 15, 'etv' => 90.0, 'pos_1' => 1, 'pos_2_3' => 2, 'pos_4_10' => 4]]],
                ]])),

            '*dataforseo_labs/google/bulk_traffic_estimation/live*' => Http::sequence()
                ->push($this->task(['items' => [
                    ['target' => 'gs.construction', 'metrics' => ['organic' => ['etv' => 1500.0, 'count' => 300]]],
                    ['target' => 'bright-stone.com', 'metrics' => ['organic' => ['etv' => 1200.0, 'count' => 500]]],
                ]]))
                ->push($this->task(['items' => [
                    ['target' => 'gs.construction', 'metrics' => ['organic' => ['etv' => 1200.0, 'count' => 250]]],
                    ['target' => 'bright-stone.com', 'metrics' => ['organic' => ['etv' => 1300.0, 'count' => 520]]],
                ]])),
        ]);
    }

    public function test_two_runs_store_snapshots_apply_the_local_trade_filter_and_open_the_expected_findings(): void
    {
        $this->fakeTwoRuns();

        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['labs'], '--budget' => 5])->assertExitCode(0);

        // Snapshots for run 1.
        $this->assertSame(2, DB::table('seo_intel_snapshots')->where('kind', 'competitor')->count());
        $this->assertSame(1, DB::table('seo_intel_snapshots')->where('kind', 'domain')->count());
        $this->assertSame(1, DB::table('seo_intel_snapshots')->where('kind', 'page')->count());

        $gapSubjects = DB::table('seo_intel_snapshots')->where('kind', 'gap_keyword')->pluck('subject')->all();
        $this->assertContains('kitchen remodeling palatine', $gapSubjects);
        $this->assertNotContains('kitchen cabinets', $gapSubjects, 'a national keyword with no town must be dropped');
        $this->assertNotContains('bright stone kitchen palatine', $gapSubjects, 'a keyword containing the competitor\'s own brand must be dropped');
        $this->assertContains('remodeling contractor arlington heights', $gapSubjects);
        $this->assertContains('general contractor near me', $gapSubjects);
        $this->assertNotContains('bathroom remodeling palatine', $gapSubjects, 'ranked outside the top 20, must be dropped by the client-side rank filter');

        $domainRow = DB::table('seo_intel_snapshots')->where('kind', 'domain')->first();
        $domainMetrics = json_decode((string) $domainRow->metrics, true);
        $this->assertEquals(1500.0, $domainMetrics['etv']);
        $this->assertArrayHasKey('bulk_etv', $domainMetrics);

        // Keyword-gap findings need no history — they are true the moment the gap is seen.
        $this->assertSame(3, DB::table('seo_intel_findings')->where('code', 'labs.keyword_gap')->count());
        $this->assertSame(0, DB::table('seo_intel_findings')->whereNotIn('code', ['labs.keyword_gap'])->count(), 'delta-based findings need a previous run');
        $noMatchGap = DB::table('seo_intel_findings')->where('code', 'labs.keyword_gap')->where('subject', 'general contractor near me')->first();
        $this->assertNotNull($noMatchGap);
        $this->assertNull(json_decode((string) $noMatchGap->action, true), 'no town and no recognized service means no action hint');

        // Second run a week later.
        Carbon::setTestNow('2026-09-12 06:00:00');
        $this->artisan('seo:intel', ['family' => ['labs'], '--budget' => 5])->assertExitCode(0);

        $findings = DB::table('seo_intel_findings')->get()->keyBy('code');

        $this->assertArrayHasKey('labs.new_competitor', $findings);
        $this->assertSame('newrivalco.com', $findings['labs.new_competitor']->subject);
        $this->assertSame('info', $findings['labs.new_competitor']->severity);

        $this->assertArrayHasKey('labs.etv_drop', $findings);
        $this->assertSame('warn', $findings['labs.etv_drop']->severity);
        $delta = json_decode((string) $findings['labs.etv_drop']->delta, true);
        $this->assertEquals(1500.0, $delta['etv']['prev']);
        $this->assertEquals(1200.0, $delta['etv']['now']);

        $this->assertArrayHasKey('labs.page_keyword_loss', $findings);
        $pageAction = json_decode((string) $findings['labs.page_keyword_loss']->action, true);
        $this->assertSame(['type' => 'content_refresh', 'path' => '/kitchen-remodeling'], $pageAction);

        $gapFindings = DB::table('seo_intel_findings')->where('code', 'labs.keyword_gap')->get()->keyBy('subject');
        $this->assertArrayHasKey('kitchen remodeling palatine', $gapFindings, 'the persisting gap keyword stays open');
        $this->assertArrayHasKey('basement remodeling arlington heights', $gapFindings, 'a brand-new gap keyword opens a finding');

        $kitchenAction = json_decode((string) $gapFindings['kitchen remodeling palatine']->action, true);
        $this->assertSame(['type' => 'create_page', 'town' => 'Palatine', 'service' => 'kitchen-remodeling'], $kitchenAction);

        $basementAction = json_decode((string) $gapFindings['basement remodeling arlington heights']->action, true);
        $this->assertSame(['type' => 'create_page', 'town' => 'Arlington Heights', 'service' => 'basement-remodeling'], $basementAction);

        $noServiceGap = DB::table('seo_intel_findings')->where('code', 'labs.keyword_gap')->where('subject', 'remodeling contractor arlington heights')->first();
        $this->assertNotNull($noServiceGap);
        $this->assertSame(['type' => 'content_refresh', 'path' => '/areas/arlington-heights'], json_decode((string) $noServiceGap->action, true), 'a matched town with no recognized service gets a refresh hint on its area page');

        // report() shape.
        $source = app(LabsSource::class);
        $report = $source->report();
        $this->assertCount(4, $report['tiles']);
        $this->assertEquals(1200.0, $report['tiles'][1]['value']);
        $this->assertEquals(1500.0, $report['tiles'][1]['prev']);
        $gapTable = collect($report['tables'])->firstWhere('title', 'Top keyword gaps');
        $this->assertNotEmpty($gapTable['rows']);
        $this->assertTrue(collect($gapTable['rows'])->contains(fn ($r) => $r[0] === 'kitchen remodeling palatine'));
        $competitorTable = collect($report['tables'])->firstWhere('title', 'Organic competitors');
        $this->assertNotEmpty($competitorTable['rows']);
        $this->assertNotEmpty($report['note']);
    }

    public function test_cost_cap_stops_further_calls_and_still_returns_partial_snapshots(): void
    {
        config(['seo-intel.families.labs.max_cost' => 0.001]);
        $this->fakeTwoRuns();

        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['labs'], '--budget' => 5])->assertExitCode(0);

        $this->assertSame(1, Http::recorded(fn ($r) => str_contains($r->url(), 'competitors_domain'))->count());
        $this->assertSame(0, Http::recorded(fn ($r) => str_contains($r->url(), 'domain_intersection'))->count());
        $this->assertSame(0, Http::recorded(fn ($r) => str_contains($r->url(), 'historical_rank_overview'))->count());
        $this->assertSame(0, Http::recorded(fn ($r) => str_contains($r->url(), 'relevant_pages'))->count());
        $this->assertSame(0, Http::recorded(fn ($r) => str_contains($r->url(), 'bulk_traffic_estimation'))->count());

        // The one call that was made is still stored.
        $this->assertSame(2, DB::table('seo_intel_snapshots')->where('kind', 'competitor')->count());
        $run = DB::table('seo_intel_runs')->first();
        $this->assertNull($run->error);
    }

    public function test_report_is_safe_before_anything_has_been_collected(): void
    {
        $source = app(LabsSource::class);
        $report = $source->report();
        $this->assertCount(4, $report['tiles']);
        foreach ($report['tables'] as $table) {
            $this->assertSame([], $table['rows']);
        }
        $this->assertNotEmpty($report['note']);
    }

    public function test_estimate_cost_is_positive_and_findings_are_empty_with_no_data(): void
    {
        $source = app(LabsSource::class);
        $this->assertGreaterThan(0, $source->estimateCost());
        $this->assertSame([], $source->findings());
    }
}
