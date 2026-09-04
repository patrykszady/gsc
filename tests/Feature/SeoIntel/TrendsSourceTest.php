<?php

namespace Tests\Feature\SeoIntel;

use App\Models\AreaServed;
use App\Services\Seo\Intel\Sources\TrendsSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrendsSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Mutated between artisan runs; the one Http::fake() closure below reads it live. */
    protected static bool $kitchenSpiking = false;

    protected static string $graphEndDate = '2026-09-01';

    protected static array $kitchenRising = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$kitchenSpiking = false;
        self::$graphEndDate = '2026-09-01';
        self::$kitchenRising = [];
        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [TrendsSource::class],
            'seo-intel.families.trends.phrases' => ['kitchen remodel', 'bathroom remodel'],
            'brand.name' => 'GS Construction',
        ]);

        // One fake for the whole test: which response it returns is driven by
        // the static properties above, mutated between artisan calls — a
        // second Http::fake() call stacks rather than replaces (both
        // closures run, first non-null wins), so re-faking per run is not
        // safe here (see IntelRunnerTest's FakeScoreSource for the same idiom).
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }

            $body = json_decode($request->body(), true)[0] ?? [];
            $keyword = $body['keywords'][0] ?? '';
            $isKitchen = str_contains($keyword, 'kitchen');
            $spiking = $isKitchen && self::$kitchenSpiking;

            $items = [
                [
                    'type' => 'google_trends_graph',
                    'title' => 'graph',
                    'keywords' => [$keyword],
                    'data' => $this->graphData($spiking, self::$graphEndDate),
                    'averages' => [],
                ],
                [
                    'type' => 'google_trends_queries_list',
                    'title' => 'queries',
                    'keywords' => [$keyword],
                    'data' => [
                        'top' => [['query' => $keyword.' cost', 'value' => '100']],
                        'rising' => $isKitchen ? self::$kitchenRising : [],
                    ],
                ],
                [
                    'type' => 'google_trends_topics_list',
                    'title' => 'topics',
                    'keywords' => [$keyword],
                    'data' => ['top' => [['topic_id' => 't1', 'topic_title' => 'Kitchen', 'topic_type' => 'Topic', 'value' => '100']], 'rising' => []],
                ],
            ];

            return Http::response(['tasks' => [[
                'cost' => 0.011, 'status_code' => 20000,
                'result' => [['keywords' => [$keyword], 'type' => 'web', 'items_count' => count($items), 'items' => $items]],
            ]]]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Weekly graph points, flat baseline of 30 with the last 4 weeks spiking to 90 when $spiking. */
    private function graphData(bool $spiking, string $endDate): array
    {
        $points = [];
        $end = Carbon::parse($endDate);
        for ($i = 15; $i >= 0; $i--) {
            $date = $end->clone()->subWeeks($i);
            $value = $spiking && $i <= 3 ? 90 : 30;
            $points[] = [
                'date_from' => $date->toDateString(),
                'date_to' => $date->clone()->addDays(6)->toDateString(),
                'timestamp' => $date->timestamp,
                'missing_data' => false,
                'values' => [$value],
            ];
        }

        return $points;
    }

    public function test_first_run_stores_snapshots_with_no_findings_yet(): void
    {
        Carbon::setTestNow('2026-09-04 06:00:00');
        self::$graphEndDate = '2026-09-01';

        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(2, DB::table('seo_intel_snapshots')->count());
        $this->assertSame(0, DB::table('seo_intel_findings')->count());
        $row = DB::table('seo_intel_snapshots')->where('subject', 'kitchen remodel')->first();
        $metrics = json_decode((string) $row->metrics, true);
        $this->assertEqualsWithDelta(30.0, $metrics['interest_avg_12m'], 0.1);
        $this->assertEqualsWithDelta(30.0, $metrics['interest_4w_avg'], 0.1);
        $this->assertEqualsWithDelta(30.0, $metrics['interest_now'], 0.1);
        $run = DB::table('seo_intel_runs')->first();
        $this->assertEqualsWithDelta(0.022, (float) $run->cost, 0.001);
    }

    public function test_second_run_opens_seasonal_rising_local_and_breakout_findings_with_actions(): void
    {
        Carbon::setTestNow('2026-09-04 06:00:00');
        self::$graphEndDate = '2026-09-01';
        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-11 06:00:00');
        self::$graphEndDate = '2026-09-08';
        self::$kitchenSpiking = true;
        self::$kitchenRising = [
            ['query' => 'kitchen remodel Arlington Heights', 'value' => '250'],
            ['query' => 'kitchen remodel near me', 'value' => '80'],
            ['query' => 'kitchen remodel financing', 'value' => 'Breakout'],
        ];
        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        $findings = DB::table('seo_intel_findings')->get()->groupBy('code');

        $this->assertArrayHasKey('trends.seasonal_upswing', $findings->toArray(), 'kitchen remodel jumped well above its 12-month baseline');
        $upswing = $findings['trends.seasonal_upswing']->first();
        $this->assertSame('kitchen remodel', $upswing->subject);
        $this->assertSame('info', $upswing->severity);
        $delta = json_decode((string) $upswing->delta, true);
        $this->assertEqualsWithDelta(45.0, $delta['interest_4w_avg']['prev'], 1, '12-month average pulled up by the 4-week spike');
        $this->assertEqualsWithDelta(90.0, $delta['interest_4w_avg']['now'], 1);
        $this->assertNull(json_decode((string) $upswing->action, true), '"kitchen remodel" names no served town, so no content_refresh action — a path resolveTarget() cannot resolve would be worse than none');

        // Both "Arlington Heights" and "near me" qualify as local-intent rising queries.
        $this->assertArrayHasKey('trends.rising_local_query', $findings->toArray());
        $this->assertCount(2, $findings['trends.rising_local_query']);
        $rising = $findings['trends.rising_local_query']->firstWhere(fn ($f) => str_contains((string) $f->title, 'Arlington Heights'));
        $this->assertNotNull($rising, 'the town-mentioning rising query opened its own finding');
        $action = json_decode((string) $rising->action, true);
        $this->assertSame('create_page', $action['type']);
        $this->assertSame('Arlington Heights', $action['town']);
        $this->assertSame('kitchen-remodeling', $action['service']);
        $nearMe = $findings['trends.rising_local_query']->firstWhere(fn ($f) => str_contains((string) $f->title, 'near me'));
        $this->assertNotNull($nearMe);
        $this->assertNull(json_decode((string) $nearMe->action, true), 'no town parsed from "near me" alone, so no create_page action');

        $this->assertArrayHasKey('trends.breakout_query', $findings->toArray());
        $this->assertStringContainsString('financing', (string) $findings['trends.breakout_query']->first()->title);

        // Recovery: back to baseline resolves the seasonal finding but the
        // rising-local/breakout findings (keyed by their own query text) stay
        // resolved too since that exact rising query is no longer reported.
        Carbon::setTestNow('2026-09-18 06:00:00');
        self::$graphEndDate = '2026-09-15';
        self::$kitchenSpiking = false;
        self::$kitchenRising = [];
        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);
        $this->assertNotNull(DB::table('seo_intel_findings')->where('code', 'trends.seasonal_upswing')->value('resolved_at'));
        $this->assertNotNull(DB::table('seo_intel_findings')->where('code', 'trends.breakout_query')->value('resolved_at'));
    }

    public function test_seasonal_upswing_content_refresh_action_targets_the_named_towns_area_page(): void
    {
        // "kitchen remodel Arlington Heights" names a served town that has an
        // AreaServed row — the one case content_refresh's action hint can
        // actually resolve (SeoAutopilotService::resolveTarget() only ever
        // resolves a bare '/areas-served/{slug}' path for that action).
        config(['seo-intel.families.trends.phrases' => ['kitchen remodel Arlington Heights', 'bathroom remodel']]);
        AreaServed::create(['city' => 'Arlington Heights', 'slug' => 'arlington-heights']);

        Carbon::setTestNow('2026-09-04 06:00:00');
        self::$graphEndDate = '2026-09-01';
        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-11 06:00:00');
        self::$graphEndDate = '2026-09-08';
        self::$kitchenSpiking = true;
        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        $upswing = DB::table('seo_intel_findings')->where('code', 'trends.seasonal_upswing')
            ->where('subject', 'kitchen remodel Arlington Heights')->first();
        $this->assertNotNull($upswing);
        $action = json_decode((string) $upswing->action, true);
        $this->assertSame('content_refresh', $action['type']);
        $this->assertSame('/areas-served/arlington-heights', $action['path']);
        $this->assertSame(['kitchen remodel Arlington Heights'], $action['phrases']);
    }

    public function test_seasonal_upswing_has_no_action_when_the_matched_town_has_no_area_row(): void
    {
        // "Arlington Heights" is in the GBP service-areas list (so it matches
        // as a town) but no AreaServed row exists for it here — a guessed
        // slug could point content_refresh at a page that doesn't exist, so
        // the honest thing is no action at all rather than a dead one.
        config(['seo-intel.families.trends.phrases' => ['kitchen remodel Arlington Heights', 'bathroom remodel']]);

        Carbon::setTestNow('2026-09-04 06:00:00');
        self::$graphEndDate = '2026-09-01';
        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-11 06:00:00');
        self::$graphEndDate = '2026-09-08';
        self::$kitchenSpiking = true;
        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        $upswing = DB::table('seo_intel_findings')->where('code', 'trends.seasonal_upswing')
            ->where('subject', 'kitchen remodel Arlington Heights')->first();
        $this->assertNotNull($upswing);
        $this->assertNull(json_decode((string) $upswing->action, true));
    }

    public function test_cost_cap_stops_calls_before_the_last_phrase(): void
    {
        config(['seo-intel.families.trends.max_cost' => 0.011]);
        Carbon::setTestNow('2026-09-04 06:00:00');
        self::$graphEndDate = '2026-09-01';

        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(1, DB::table('seo_intel_snapshots')->count(), 'the cap stops after the first $0.011 call');
        $calls = Http::recorded(fn ($r) => str_contains($r->url(), 'google_trends'))->count();
        $this->assertSame(1, $calls);
        $run = DB::table('seo_intel_runs')->first();
        $this->assertEqualsWithDelta(0.011, (float) $run->cost, 0.001);
    }

    public function test_report_has_tiles_and_table_and_is_safe_before_any_run(): void
    {
        $source = app(TrendsSource::class);
        $empty = $source->report();
        $this->assertSame([], $empty['tiles']);
        $this->assertSame([], $empty['tables']);
        $this->assertNotEmpty($empty['note']);

        Carbon::setTestNow('2026-09-04 06:00:00');
        self::$graphEndDate = '2026-09-01';
        self::$kitchenSpiking = true;
        self::$kitchenRising = [['query' => 'kitchen remodel near me', 'value' => '80']];
        $this->artisan('seo:intel', ['family' => ['trends'], '--budget' => 1])->assertExitCode(0);

        $report = app(TrendsSource::class)->report();
        $labels = array_column($report['tiles'], 'label');
        $this->assertSame(['Phrases tracked', 'Rising phrases', 'Breakout queries'], $labels);
        $this->assertSame(2, $report['tiles'][0]['value']);
        $this->assertSame(1, $report['tiles'][1]['value'], 'kitchen remodel is spiking');
        $this->assertSame(['Phrase', 'Interest now', '12-mo avg', 'Trend', 'Top rising query'], $report['tables'][0]['columns']);
        $this->assertCount(2, $report['tables'][0]['rows']);
    }
}
