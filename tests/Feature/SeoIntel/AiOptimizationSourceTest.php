<?php

namespace Tests\Feature\SeoIntel;

use App\Services\Seo\Intel\Sources\AiOptimizationSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiOptimizationSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'brand.name' => 'GS Construction & Remodeling',
            'seo-intel.sources' => [AiOptimizationSource::class],
            // A custom keyword naming an unserved-but-known town, so it always
            // wins the "no area page" finding regardless of the seo_keywords table.
            'seo-intel.families.ai_optimization.keywords' => ['bathroom remodeling newtown'],
            'seo-intel.families.ai_optimization.competitors' => 1,
            'gbp-services.service_areas' => ['Newtown, IL, USA', 'Wheeling, IL, USA'],
        ]);
        // Wheeling already has an area page; Newtown does not.
        DB::table('areas_served')->insert(['site_id' => null, 'city' => 'Wheeling', 'slug' => 'wheeling', 'created_at' => now(), 'updated_at' => now()]);
        // One map-pack competitor for the mentions comparison.
        DB::table('map_pack_competitors')->insert([
            'site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Prism Kitchen & Bath',
            'host' => 'prismkb.com', 'pack_points' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Http::fake() merges stub callbacks across calls rather than replacing
     * them (the earliest-registered one wins), so a second Http::fake() mid-
     * test never takes effect. Register the fake once and swap the fixtures
     * it reads from instead (see BacklinksSourceTest for the same pattern).
     */
    protected array $volumes = [];

    protected array $mentionItems = [];

    protected bool $fakeRegistered = false;

    /** @param  array<string,int>  $volumes  keyword => ai_search_volume  @param  list<array>  $mentionItems */
    protected function fakeFor(array $volumes, array $mentionItems): void
    {
        $this->volumes = $volumes;
        $this->mentionItems = $mentionItems;
        if ($this->fakeRegistered) {
            return;
        }
        $this->fakeRegistered = true;

        Http::fake(function ($request) {
            $volumes = $this->volumes;
            $mentionItems = $this->mentionItems;
            $url = $request->url();
            if (str_contains($url, 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }
            $body = $request->data();

            if (str_contains($url, 'ai_keyword_data/keywords_search_volume')) {
                $items = [];
                foreach ((array) ($body[0]['keywords'] ?? []) as $kw) {
                    $items[] = ['keyword' => $kw, 'ai_search_volume' => $volumes[$kw] ?? 50, 'ai_monthly_searches' => []];
                }

                return Http::response(['tasks' => [['cost' => 0.0106, 'status_code' => 20000, 'result' => [['items' => $items]]]]]);
            }

            if (str_contains($url, 'llm_mentions/multi_target_metrics')) {
                return Http::response(['tasks' => [['cost' => 0.102, 'status_code' => 20000, 'result' => [['items' => $mentionItems]]]]]);
            }

            return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => []]]]);
        });
    }

    protected function day1Mentions(): array
    {
        return [
            [
                'key' => 'GS Construction & Remodeling',
                'total' => ['mentions' => 40, 'ai_search_volume' => 9000],
                'platform' => [['key' => 'chat_gpt', 'mentions' => 25], ['key' => 'google', 'mentions' => 15]],
                'sources_domain' => [['key' => 'reddit.com', 'mentions' => 5]],
            ],
            [
                'key' => 'Prism Kitchen & Bath',
                'total' => ['mentions' => 60, 'ai_search_volume' => 12000],
                'platform' => [['key' => 'chat_gpt', 'mentions' => 40], ['key' => 'google', 'mentions' => 20]],
                'sources_domain' => [['key' => 'reddit.com', 'mentions' => 8], ['key' => 'houzz.com', 'mentions' => 12]],
            ],
        ];
    }

    protected function day2Mentions(): array
    {
        $items = $this->day1Mentions();
        $items[0]['total'] = ['mentions' => 30, 'ai_search_volume' => 8000]; // our mentions fell 40 -> 30

        return $items;
    }

    public function test_two_runs_open_rising_volume_underserved_town_mentions_drop_and_citation_gap_findings(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor(['kitchen remodel cost' => 1000, 'bathroom remodeling newtown' => 200], $this->day1Mentions());
        $this->artisan('seo:intel', ['family' => ['ai_optimization'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(2, DB::table('seo_intel_snapshots')->where('kind', 'mentions')->count());
        $this->assertGreaterThanOrEqual(6, DB::table('seo_intel_snapshots')->where('kind', 'keyword')->count());

        // First run: no previous snapshot yet for the rising-volume comparison, but
        // the underserved-town finding needs no history and should open immediately.
        $day1Findings = DB::table('seo_intel_findings')->pluck('code')->all();
        $this->assertContains('ai_optimization.underserved_town_keyword', $day1Findings);
        $this->assertNotContains('ai_optimization.ai_volume_rising', $day1Findings);
        $this->assertContains('ai_optimization.citation_gap', $day1Findings);

        $underserved = DB::table('seo_intel_findings')->where('code', 'ai_optimization.underserved_town_keyword')->first();
        $this->assertSame('bathroom remodeling newtown', $underserved->subject);
        $this->assertSame('Newtown', $underserved->key);
        $action = json_decode((string) $underserved->action, true);
        $this->assertSame(['type' => 'create_page', 'town' => 'Newtown', 'service' => 'bathroom-remodeling'], $action);

        $gap = DB::table('seo_intel_findings')->where('code', 'ai_optimization.citation_gap')->first();
        $this->assertSame('houzz.com', $gap->subject);
        $this->assertSame('llms_regen', json_decode((string) $gap->action, true)['type']);

        Carbon::setTestNow('2026-09-12 06:00:00');
        $this->fakeFor(['kitchen remodel cost' => 1500, 'bathroom remodeling newtown' => 210], $this->day2Mentions());
        $this->artisan('seo:intel', ['family' => ['ai_optimization'], '--budget' => 1])->assertExitCode(0);

        $findings = DB::table('seo_intel_findings')->orderBy('code')->get()->keyBy('code');

        $rising = $findings['ai_optimization.ai_volume_rising'];
        $this->assertSame('info', $rising->severity);
        $this->assertSame('kitchen remodel cost', $rising->subject);
        $this->assertEquals(['ai_search_volume' => ['prev' => 1000, 'now' => 1500]], json_decode((string) $rising->delta, true));

        $drop = $findings['ai_optimization.mentions_drop'];
        $this->assertSame('warn', $drop->severity);
        $this->assertSame('GS Construction & Remodeling', $drop->subject);
        $this->assertEquals(['mentions_total' => ['prev' => 40, 'now' => 30]], json_decode((string) $drop->delta, true));
        $this->assertSame('llms_regen', json_decode((string) $drop->action, true)['type']);

        // Still no page for Newtown: the finding stays open, unresolved.
        $this->assertNull(DB::table('seo_intel_findings')->where('code', 'ai_optimization.underserved_town_keyword')->value('resolved_at'));
        // Wheeling never triggers it: it already has an area page.
        $this->assertSame(0, DB::table('seo_intel_findings')->where('subject', 'like', '%wheeling%')->count());
    }

    public function test_report_has_the_promised_tiles_and_tables(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor(['kitchen remodel cost' => 1000, 'bathroom remodeling newtown' => 200], $this->day1Mentions());
        $this->artisan('seo:intel', ['family' => ['ai_optimization'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-12 06:00:00');
        $this->fakeFor(['kitchen remodel cost' => 1500, 'bathroom remodeling newtown' => 210], $this->day2Mentions());
        $this->artisan('seo:intel', ['family' => ['ai_optimization'], '--budget' => 1])->assertExitCode(0);

        $report = app(AiOptimizationSource::class)->report();

        $labels = array_column($report['tiles'], 'label');
        foreach (['AI search volume', 'Keywords tracked', 'Our LLM mentions', 'Citation gap domains'] as $expected) {
            $this->assertContains($expected, $labels);
        }
        $mentionsTile = collect($report['tiles'])->firstWhere('label', 'Our LLM mentions');
        $this->assertSame(30, $mentionsTile['value']);
        $this->assertSame(40, $mentionsTile['prev']);

        $tableTitles = array_column($report['tables'], 'title');
        $this->assertContains('Top AI-volume keywords', $tableTitles);
        $this->assertContains('Citation sources', $tableTitles);
        $sources = collect($report['tables'])->firstWhere('title', 'Citation sources');
        $this->assertSame(['houzz.com', 1, '✗'], $sources['rows'][0]);
        $this->assertNotEmpty($report['note']);
    }

    public function test_report_is_empty_before_anything_is_collected(): void
    {
        $report = app(AiOptimizationSource::class)->report();
        $this->assertSame([], $report['tiles']);
        $this->assertSame([], $report['tables']);
        $this->assertNotEmpty($report['note']);
    }

    public function test_cost_cap_stops_making_calls_but_keeps_what_it_collected(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor(['kitchen remodel cost' => 1000, 'bathroom remodeling newtown' => 200], $this->day1Mentions());
        config(['seo-intel.families.ai_optimization.max_cost' => 0.001]);
        $this->artisan('seo:intel', ['family' => ['ai_optimization'], '--budget' => 1])->assertExitCode(0);

        $calls = Http::recorded(fn ($r) => ! str_contains($r->url(), 'user_data'))->count();
        $this->assertSame(1, $calls, 'only the keyword-volume call is made once the cap is already exceeded');
        $this->assertSame(0, DB::table('seo_intel_snapshots')->where('kind', 'mentions')->count());
        $this->assertGreaterThan(0, DB::table('seo_intel_snapshots')->where('kind', 'keyword')->count());
    }

    public function test_estimate_cost_is_positive_and_reasonable(): void
    {
        $source = app(AiOptimizationSource::class);
        $this->assertGreaterThan(0, $source->estimateCost());
        $this->assertLessThan(1.0, $source->estimateCost());
    }

    /**
     * DataForSEO's llm_mentions/multi_target_metrics/live requires 2-10 targets
     * ("not less than 2"). With no map-pack competitors on record, $names would
     * be just [$brand] — a single target the API would reject. collectMentions()
     * must bail out before making that call at all: no HTTP request, no spend,
     * no crash, and the keyword-volume snapshots collected in the same run must
     * still be kept.
     */
    public function test_no_mentions_call_is_made_when_there_are_no_competitors_to_compare(): void
    {
        DB::table('map_pack_competitors')->truncate();
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor(['kitchen remodel cost' => 1000, 'bathroom remodeling newtown' => 200], $this->day1Mentions());
        $this->artisan('seo:intel', ['family' => ['ai_optimization'], '--budget' => 1])->assertExitCode(0);

        $mentionsCalls = Http::recorded(fn ($r) => str_contains($r->url(), 'llm_mentions'))->count();
        $this->assertSame(0, $mentionsCalls, 'no llm_mentions request should be made with fewer than 2 targets');

        $this->assertSame(0, DB::table('seo_intel_snapshots')->where('kind', 'mentions')->count());
        $this->assertGreaterThan(0, DB::table('seo_intel_snapshots')->where('kind', 'keyword')->count());
    }
}
