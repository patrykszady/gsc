<?php

namespace Tests\Feature\SeoIntel;

use App\Models\AreaServed;
use App\Models\Project;
use App\Services\Seo\Intel\Sources\SerpSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SerpSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Toggled between the two artisan runs so the same Http::fake serves both weeks. */
    protected static string $run = 'a';

    protected function setUp(): void
    {
        parent::setUp();
        self::$run = 'a';
        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [SerpSource::class],
            'seo-intel.families.serp.queries' => [
                'kitchen remodeling Arlington Heights IL',
                'bathroom remodeling Buffalo Grove IL',
                'walk-in shower installation Prospect Heights IL',
                'basement remodeling Wheeling IL',
            ],
            'seo-intel.families.serp.depth' => 20,
            'seo-intel.families.serp.max_findings' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function organic(int $rank, string $domain, ?string $url = null): array
    {
        return ['type' => 'organic', 'rank_absolute' => $rank, 'rank_group' => $rank, 'domain' => $domain, 'url' => $url ?? "https://{$domain}/page"];
    }

    protected function fakeTrackedQueries(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }
            $body = json_decode($request->body(), true)[0] ?? [];
            $keyword = $body['keyword'] ?? '';
            $items = [];

            if ($keyword === 'kitchen remodeling Arlington Heights IL') {
                // Position drop #4 -> #9 (not from top 3, so warn not critical); local pack present, we're absent from it.
                $items[] = $this->organic(1, 'prismkitchenbath.com');
                $items[] = $this->organic(2, 'dreamlineremodel.com');
                $items[] = $this->organic(3, 'yellosquare.com');
                if (self::$run === 'a') {
                    $items[] = $this->organic(4, 'gs.construction');
                } else {
                    $items[] = $this->organic(9, 'gs.construction');
                    $items[] = ['type' => 'local_pack', 'rank_group' => 1, 'rank_absolute' => 20, 'domain' => 'prismkitchenbath.com', 'title' => 'Prism Kitchen & Bath'];
                    $items[] = ['type' => 'local_pack', 'rank_group' => 2, 'rank_absolute' => 21, 'domain' => 'dreamlineremodel.com', 'title' => 'Dreamline Remodeling'];
                }
            } elseif ($keyword === 'bathroom remodeling Buffalo Grove IL') {
                // Position gain #9 -> #3; a new domain takes the #2 spot above us.
                if (self::$run === 'a') {
                    $items[] = $this->organic(1, 'a.com');
                    $items[] = $this->organic(2, 'b.com');
                    $items[] = $this->organic(3, 'c.com');
                    $items[] = $this->organic(9, 'gs.construction');
                } else {
                    $items[] = $this->organic(1, 'a.com');
                    $items[] = $this->organic(2, 'newcompetitor.com');
                    $items[] = $this->organic(3, 'gs.construction');
                }
            } elseif ($keyword === 'walk-in shower installation Prospect Heights IL') {
                // Stable position; an AI Overview appears in week 2 that does not cite us, with PAA questions.
                $items[] = $this->organic(5, 'gs.construction', 'https://gs.construction/services/walk-in-shower-installation');
                if (self::$run === 'b') {
                    $items[] = [
                        'type' => 'ai_overview', 'rank_absolute' => 1, 'asynchronous_ai_overview' => false,
                        'items' => [[
                            'type' => 'ai_overview_element',
                            'links' => [['type' => 'link_element', 'domain' => 'competitor.com', 'url' => 'https://competitor.com/showers']],
                            'references' => [['type' => 'ai_overview_reference', 'domain' => 'competitor.com', 'url' => 'https://competitor.com/showers']],
                        ]],
                    ];
                    $items[] = [
                        'type' => 'people_also_ask', 'rank_absolute' => 2,
                        'items' => [
                            ['type' => 'people_also_ask_element', 'title' => 'How much does a walk-in shower cost?'],
                            ['type' => 'people_also_ask_element', 'title' => 'Do you need a permit for a walk-in shower?'],
                        ],
                    ];
                }
            } elseif ($keyword === 'basement remodeling Wheeling IL') {
                // Falls from #2 (top 3) to entirely outside the tracked depth: critical.
                if (self::$run === 'a') {
                    $items[] = $this->organic(1, 'other1.com');
                    $items[] = $this->organic(2, 'gs.construction');
                    $items[] = $this->organic(3, 'other3.com');
                } else {
                    $items[] = $this->organic(1, 'other1.com');
                    $items[] = $this->organic(2, 'other2.com');
                    $items[] = $this->organic(3, 'other3.com');
                }
            }

            return Http::response(['tasks' => [['cost' => 0.004, 'status_code' => 20000, 'result' => [['keyword' => $keyword, 'items' => $items]]]]]);
        });
    }

    public function test_two_runs_open_the_expected_findings_with_deltas_and_actions(): void
    {
        $this->fakeTrackedQueries();

        Carbon::setTestNow('2026-09-05 06:00:00');
        self::$run = 'a';
        $this->artisan('seo:intel', ['family' => ['serp'], '--budget' => 1])->assertExitCode(0);
        $this->assertSame(4, DB::table('seo_intel_snapshots')->where('family', 'serp')->count());
        $this->assertSame(0, DB::table('seo_intel_findings')->count(), 'first run has no previous snapshot to compare against');

        Carbon::setTestNow('2026-09-12 06:00:00');
        self::$run = 'b';
        $this->artisan('seo:intel', ['family' => ['serp'], '--budget' => 1])->assertExitCode(0);

        $findings = DB::table('seo_intel_findings')->get()->keyBy(fn ($f) => $f->code.'|'.$f->subject.'|'.($f->key ?? ''));

        $drop = $findings->get('serp.position_drop|kitchen remodeling Arlington Heights IL|');
        $this->assertNotNull($drop);
        $this->assertSame('warn', $drop->severity);
        $this->assertSame(['position' => ['prev' => 4, 'now' => 9]], json_decode((string) $drop->delta, true));
        $this->assertSame(['type' => 'content_refresh', 'path' => '/page'], json_decode((string) $drop->action, true));

        $criticalDrop = $findings->get('serp.position_drop|basement remodeling Wheeling IL|');
        $this->assertNotNull($criticalDrop);
        $this->assertSame('critical', $criticalDrop->severity, 'dropping from top 3 to outside top 10 is critical');
        $this->assertSame(['position' => ['prev' => 2, 'now' => null]], json_decode((string) $criticalDrop->delta, true));

        $gain = $findings->get('serp.position_gain|bathroom remodeling Buffalo Grove IL|');
        $this->assertNotNull($gain);
        $this->assertSame('win', $gain->severity);
        $this->assertSame(['position' => ['prev' => 9, 'now' => 3]], json_decode((string) $gain->delta, true));

        $competitor = $findings->get('serp.competitor_top3|bathroom remodeling Buffalo Grove IL|newcompetitor.com');
        $this->assertNotNull($competitor, 'a domain new to the top 3 that outranks us should be flagged');
        $this->assertSame('info', $competitor->severity);

        $localPack = $findings->get('serp.local_pack_absent|kitchen remodeling Arlington Heights IL|');
        $this->assertNotNull($localPack);
        $this->assertSame('warn', $localPack->severity);

        $ai = $findings->get('serp.ai_overview_appeared|walk-in shower installation Prospect Heights IL|');
        $this->assertNotNull($ai);
        $this->assertSame('info', $ai->severity);
        $this->assertStringContainsString('does not cite us', $ai->detail);
        $this->assertSame(['type' => 'content_refresh', 'path' => '/services/walk-in-shower-installation'], json_decode((string) $ai->action, true));

        $paa = $findings->get('serp.paa_gap|walk-in shower installation Prospect Heights IL|');
        $this->assertNotNull($paa);
        $this->assertStringContainsString('walk-in shower cost', $paa->detail);

        // report() reflects the second run.
        $report = app(SerpSource::class)->report();
        $tiles = collect($report['tiles'])->keyBy('label');
        $this->assertSame(4, $tiles['Tracked queries']['value']);
        $this->assertSame(3, $tiles['In top 10']['value']); // positions 9, 3, 5 (basement is out)
        $this->assertSame(0, $tiles['In local pack']['value']);
        $this->assertSame(1, $tiles['AI Overview present']['value']);
        $this->assertCount(4, $report['tables'][0]['rows']);
        $this->assertSame(
            [
                'bathroom remodeling Buffalo Grove IL',   // #3
                'walk-in shower installation Prospect Heights IL', // #5
                'kitchen remodeling Arlington Heights IL', // #9
                'basement remodeling Wheeling IL', // not ranked — sorts last
            ],
            array_column($report['tables'][0]['rows'], 0),
            'rows must be ordered best-position-first, not by the formatted "pos (prev)" string'
        );
        $this->assertNotEmpty($report['note']);
    }

    public function test_recovering_a_dropped_query_resolves_its_finding(): void
    {
        $this->fakeTrackedQueries();

        Carbon::setTestNow('2026-09-05 06:00:00');
        self::$run = 'a';
        $this->artisan('seo:intel', ['family' => ['serp']])->assertExitCode(0);
        Carbon::setTestNow('2026-09-12 06:00:00');
        self::$run = 'b';
        $this->artisan('seo:intel', ['family' => ['serp']])->assertExitCode(0);
        $this->assertNull(DB::table('seo_intel_findings')->where('code', 'serp.position_drop')->where('subject', 'kitchen remodeling Arlington Heights IL')->value('resolved_at'));

        // Week 3: back to #4 — the drop finding must resolve, not linger.
        Carbon::setTestNow('2026-09-19 06:00:00');
        self::$run = 'a';
        $this->artisan('seo:intel', ['family' => ['serp']])->assertExitCode(0);
        $this->assertNotNull(DB::table('seo_intel_findings')->where('code', 'serp.position_drop')->where('subject', 'kitchen remodeling Arlington Heights IL')->value('resolved_at'));
    }

    public function test_collect_stops_once_the_cost_cap_is_exceeded(): void
    {
        config(['seo-intel.families.serp.max_cost' => 0.005]);
        $this->fakeTrackedQueries();

        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['serp'], '--budget' => 1])->assertExitCode(0);

        // $0.004/query: after 2 calls spent is $0.008, over the $0.005 cap — the 3rd and 4th queries never run.
        $this->assertSame(2, DB::table('seo_intel_snapshots')->where('family', 'serp')->count());
        $calls = Http::recorded(fn ($r) => str_contains($r->url(), '/serp/google/organic/live/advanced'));
        $this->assertCount(2, $calls);
    }

    public function test_derived_query_list_prioritises_core_town_anchors_dedupes_and_caps(): void
    {
        config(['seo-intel.families.serp.queries' => [], 'seo-intel.families.serp.tracked' => 5, 'gbp-services.service_areas' => ['Arlington Heights, IL, USA', 'Buffalo Grove, IL, USA']]);

        // Chicago has an area page but is not a Business Profile service area.
        AreaServed::create(['city' => 'Chicago', 'slug' => 'chicago']);
        foreach (['Arlington Heights', 'Buffalo Grove'] as $town) {
            $area = AreaServed::create(['city' => $town, 'slug' => Str::slug($town)]);
            Project::create(['title' => "{$town} kitchen", 'slug' => Str::slug($town).'-kitchen', 'project_type' => 'kitchen', 'location' => "{$town}, IL", 'is_published' => true, 'completed_at' => now()]);
        }

        // A researched keyword that duplicates an anchor (different case, higher opportunity) must not double the list.
        DB::table('seo_keywords')->insert([
            ['keyword' => 'Kitchen Remodeling Arlington Heights IL', 'city' => 'Arlington Heights', 'opportunity' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['keyword' => 'custom shower remodel', 'city' => 'Arlington Heights', 'opportunity' => 90, 'created_at' => now(), 'updated_at' => now()],
            ['keyword' => 'granite countertop installer', 'city' => 'Buffalo Grove', 'opportunity' => 80, 'created_at' => now(), 'updated_at' => now()],
            ['keyword' => 'walk in tub install', 'city' => 'Arlington Heights', 'opportunity' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['keyword' => 'attic conversion cost', 'city' => 'Buffalo Grove', 'opportunity' => 60, 'created_at' => now(), 'updated_at' => now()],
            // Highest opportunity of all, but Chicago is not a town we serve: never tracked.
            ['keyword' => 'chicago kitchen renovation', 'city' => 'Chicago', 'opportunity' => 500, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }
            $keyword = json_decode($request->body(), true)[0]['keyword'] ?? '';

            return Http::response(['tasks' => [['cost' => 0.004, 'status_code' => 20000, 'result' => [['keyword' => $keyword, 'items' => []]]]]]);
        });

        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['serp'], '--budget' => 1])->assertExitCode(0);

        $subjects = DB::table('seo_intel_snapshots')->where('family', 'serp')->pluck('subject');
        $this->assertCount(5, $subjects, 'capped at tracked=5 even though 4 anchors + 5 researched rows were candidates');
        $lower = $subjects->map(fn ($s) => mb_strtolower($s));
        $this->assertSame($lower->count(), $lower->unique()->count(), 'no case-insensitive duplicates');
        $this->assertTrue($subjects->contains('kitchen remodeling Arlington Heights IL'), 'the anchor keeps its own casing over the duplicate researched row');
        $this->assertFalse($subjects->contains('chicago kitchen renovation'), 'towns outside the service area are not tracked');
        $this->assertFalse($subjects->contains('Kitchen Remodeling Arlington Heights IL'), 'the duplicate researched row was deduped away');
        foreach (['kitchen remodeling Arlington Heights IL', 'bathroom remodeling Arlington Heights IL', 'kitchen remodeling Buffalo Grove IL', 'bathroom remodeling Buffalo Grove IL'] as $anchor) {
            $this->assertTrue($subjects->contains($anchor), "core-town anchor \"{$anchor}\" should always be tracked");
        }
    }

    public function test_report_and_estimate_are_safe_before_any_run(): void
    {
        $report = app(SerpSource::class)->report();
        $this->assertSame([], $report['tables'][0]['rows']);
        $this->assertNotEmpty($report['note']);
        $this->assertGreaterThan(0, app(SerpSource::class)->estimateCost());
    }
}
