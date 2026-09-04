<?php

namespace Tests\Feature\SeoIntel;

use App\Services\Seo\Intel\Sources\BacklinksSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacklinksSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [BacklinksSource::class],
            'seo-intel.families.backlinks.competitors' => 1,
            'seo-intel.families.backlinks.max_referring_domains' => 300,
        ]);
        // One competitor, discovered the same way SeoDomainOverview finds them.
        DB::table('map_pack_competitors')->insert([
            'site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Domain A',
            'host' => 'domaina.com', 'pack_points' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{summary: array, referring: array, broken: array, anchors: array, history: array, comp_summary: array} */
    protected function day1(): array
    {
        return [
            'summary' => ['target' => 'gs.construction', 'backlinks' => 500, 'referring_domains' => 100, 'referring_main_domains' => 95, 'rank' => 300, 'broken_backlinks' => 0, 'broken_pages' => 0, 'referring_domains_nofollow' => 10, 'referring_ips' => 80],
            'referring' => [
                ['domain' => 'partner-a.com', 'rank' => 40, 'backlinks' => 3, 'first_seen' => '2024-01-01 00:00:00 +00:00', 'backlinks_spam_score' => 2],
                ['domain' => 'partner-b.com', 'rank' => 20, 'backlinks' => 1, 'first_seen' => '2024-02-01 00:00:00 +00:00', 'backlinks_spam_score' => 1],
                ['domain' => 'chamber.org', 'rank' => 55, 'backlinks' => 2, 'first_seen' => '2023-06-01 00:00:00 +00:00', 'backlinks_spam_score' => 0],
            ],
            'broken' => [],
            'anchors' => [
                ['anchor' => 'gs construction', 'backlinks' => 50],
                ['anchor' => 'kitchen remodeling', 'backlinks' => 20],
                ['anchor' => 'click here', 'backlinks' => 10],
            ],
            'history' => [
                ['date' => '2026-08-01 00:00:00 +00:00', 'backlinks' => 480, 'referring_domains' => 98, 'rank' => 295],
            ],
            'comp_summary' => ['target' => 'domaina.com', 'backlinks' => 200, 'referring_domains' => 50, 'referring_main_domains' => 48, 'rank' => 250, 'broken_backlinks' => 1, 'broken_pages' => 1, 'referring_domains_nofollow' => 5, 'referring_ips' => 40],
        ];
    }

    protected function day2(): array
    {
        return [
            // partner-b.com is gone, newsite.com is new.
            'summary' => ['target' => 'gs.construction', 'backlinks' => 505, 'referring_domains' => 102, 'referring_main_domains' => 97, 'rank' => 305, 'broken_backlinks' => 1, 'broken_pages' => 1, 'referring_domains_nofollow' => 10, 'referring_ips' => 81],
            'referring' => [
                ['domain' => 'partner-a.com', 'rank' => 40, 'backlinks' => 3, 'first_seen' => '2024-01-01 00:00:00 +00:00', 'backlinks_spam_score' => 2],
                ['domain' => 'chamber.org', 'rank' => 55, 'backlinks' => 2, 'first_seen' => '2023-06-01 00:00:00 +00:00', 'backlinks_spam_score' => 0],
                ['domain' => 'newsite.com', 'rank' => 45, 'backlinks' => 1, 'first_seen' => '2026-09-10 00:00:00 +00:00', 'backlinks_spam_score' => 3],
            ],
            'broken' => [
                ['url_from' => 'https://newsite.com/blog', 'domain_from' => 'newsite.com', 'url_to' => 'https://gs.construction/kitchen-remodeling-old', 'is_broken' => true, 'url_to_status_code' => 404],
                ['url_from' => 'https://chamber.org/members', 'domain_from' => 'chamber.org', 'url_to' => 'https://gs.construction/kitchen-remodeling-old', 'is_broken' => true, 'url_to_status_code' => 404],
                ['url_from' => 'https://partner-a.com/links', 'domain_from' => 'partner-a.com', 'url_to' => 'https://gs.construction/kitchen-remodeling-old', 'is_broken' => true, 'url_to_status_code' => 404],
            ],
            // Money-keyword anchors now dominate: (40+20)/100 = 60% > the 30% warn threshold.
            'anchors' => [
                ['anchor' => 'gs construction', 'backlinks' => 30],
                ['anchor' => 'kitchen remodeling', 'backlinks' => 40],
                ['anchor' => 'bathroom contractor', 'backlinks' => 20],
                ['anchor' => 'click here', 'backlinks' => 10],
            ],
            'history' => [
                ['date' => '2026-08-01 00:00:00 +00:00', 'backlinks' => 480, 'referring_domains' => 98, 'rank' => 295],
                ['date' => '2026-09-01 00:00:00 +00:00', 'backlinks' => 505, 'referring_domains' => 102, 'rank' => 305],
            ],
            // Grew from 50 to 90 (+40) while we grew from 100 to 102 (+2) — well past +10 ahead of us.
            'comp_summary' => ['target' => 'domaina.com', 'backlinks' => 260, 'referring_domains' => 90, 'referring_main_domains' => 85, 'rank' => 260, 'broken_backlinks' => 1, 'broken_pages' => 1, 'referring_domains_nofollow' => 5, 'referring_ips' => 60],
        ];
    }

    /**
     * Http::fake() merges stub callbacks across calls rather than replacing
     * them (the earliest-registered one wins), so a second call mid-test
     * never takes effect. Register the fake once and swap the fixtures it
     * reads from instead.
     */
    protected array $fixtures = [];

    protected bool $fakeRegistered = false;

    protected function fakeFor(array $fixtures): void
    {
        $this->fixtures = $fixtures;
        if ($this->fakeRegistered) {
            return;
        }
        $this->fakeRegistered = true;

        Http::fake(function ($request) {
            $fixtures = $this->fixtures;
            $url = $request->url();
            if (str_contains($url, 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }
            $body = $request->data();
            $target = $body[0]['target'] ?? null;

            $envelope = fn ($result) => Http::response(['tasks' => [['cost' => 0.024, 'status_code' => 20000, 'result' => $result]]]);

            if (str_contains($url, 'backlinks/summary/live')) {
                $row = $target === 'gs.construction' ? $fixtures['summary'] : $fixtures['comp_summary'];

                return $envelope([$row]);
            }
            if (str_contains($url, 'backlinks/referring_domains/live')) {
                return $envelope([['items' => $fixtures['referring']]]);
            }
            if (str_contains($url, 'backlinks/backlinks/live')) {
                return $envelope([['items' => $fixtures['broken']]]);
            }
            if (str_contains($url, 'backlinks/anchors/live')) {
                return $envelope([['items' => $fixtures['anchors']]]);
            }
            if (str_contains($url, 'backlinks/history/live')) {
                return $envelope([['items' => $fixtures['history']]]);
            }

            return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => []]]]);
        });
    }

    public function test_two_runs_open_lost_new_broken_anchor_and_velocity_findings(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor($this->day1());
        $this->artisan('seo:intel', ['family' => ['backlinks'], '--budget' => 1])->assertExitCode(0);

        $this->assertSame(5, DB::table('seo_intel_snapshots')->count(), '1 our domain + 3 referring domains + 1 competitor domain, no broken targets day 1');
        $this->assertSame(0, DB::table('seo_intel_findings')->count(), 'nothing to compare against yet');

        Carbon::setTestNow('2026-09-12 06:00:00');
        $this->fakeFor($this->day2());
        $this->artisan('seo:intel', ['family' => ['backlinks'], '--budget' => 1])->assertExitCode(0);

        $findings = DB::table('seo_intel_findings')->orderBy('code')->get()->keyBy('code');

        $lost = $findings['backlinks.referring_domain_lost'];
        $this->assertSame('warn', $lost->severity, 'partner-b.com rank 20 is under the critical threshold of 30');
        $this->assertSame('partner-b.com', $lost->subject);
        $this->assertSame(['rank' => ['prev' => 20, 'now' => null]], json_decode((string) $lost->delta, true));

        $new = $findings['backlinks.referring_domain_new'];
        $this->assertSame('win', $new->severity);
        $this->assertSame('newsite.com', $new->subject);

        $broken = $findings['backlinks.broken_target'];
        $this->assertSame('critical', $broken->severity);
        $this->assertSame('/kitchen-remodeling-old', $broken->subject);
        $this->assertStringContainsString('Redirect', $broken->detail);
        $this->assertSame(['links' => ['prev' => null, 'now' => 3]], json_decode((string) $broken->delta, true));
        $this->assertNull($broken->action, 'redirect is not in the autopilot action allowlist, so no action hint is attached');

        $anchor = $findings['backlinks.money_anchor_ratio'];
        $this->assertSame('info', $anchor->severity);
        $delta = json_decode((string) $anchor->delta, true);
        $this->assertEquals(60.0, $delta['money_anchor_pct']['now']);
        $this->assertEquals(25.0, $delta['money_anchor_pct']['prev']);

        $velocity = $findings['backlinks.competitor_velocity'];
        $this->assertSame('info', $velocity->severity);
        $this->assertSame('domaina.com', $velocity->subject);
        $this->assertEquals(['referring_domains' => ['prev' => 50, 'now' => 90]], json_decode((string) $velocity->delta, true));

        $this->assertSame(5, $findings->count(), 'exactly the five findings above, no extras');
    }

    public function test_lost_referring_domain_at_high_rank_is_critical(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor($this->day1());
        $this->artisan('seo:intel', ['family' => ['backlinks'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-12 06:00:00');
        $fixtures = $this->day2();
        // Now chamber.org (rank 55) is the one that disappears instead of partner-b.com.
        $fixtures['referring'] = [
            ['domain' => 'partner-a.com', 'rank' => 40, 'backlinks' => 3],
            ['domain' => 'partner-b.com', 'rank' => 20, 'backlinks' => 1],
        ];
        $this->fakeFor($fixtures);
        $this->artisan('seo:intel', ['family' => ['backlinks'], '--budget' => 1])->assertExitCode(0);

        $lost = DB::table('seo_intel_findings')->where('code', 'backlinks.referring_domain_lost')->first();
        $this->assertSame('critical', $lost->severity);
        $this->assertSame('chamber.org', $lost->subject);
    }

    public function test_report_has_the_promised_tiles_and_tables(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor($this->day1());
        $this->artisan('seo:intel', ['family' => ['backlinks'], '--budget' => 1])->assertExitCode(0);

        Carbon::setTestNow('2026-09-12 06:00:00');
        $this->fakeFor($this->day2());
        $this->artisan('seo:intel', ['family' => ['backlinks'], '--budget' => 1])->assertExitCode(0);

        $source = app(BacklinksSource::class);
        $report = $source->report();

        $labels = array_column($report['tiles'], 'label');
        foreach (['Referring domains', 'Backlinks', 'Domain rank', 'Broken targets', 'New referring domains', 'Lost referring domains'] as $expected) {
            $this->assertContains($expected, $labels);
        }
        $refTile = collect($report['tiles'])->firstWhere('label', 'Referring domains');
        $this->assertSame(102, $refTile['value']);
        $this->assertSame(100, $refTile['prev']);

        $tableTitles = array_column($report['tables'], 'title');
        $this->assertContains('Lost referring domains', $tableTitles);
        $this->assertContains('Broken targets', $tableTitles);
        $lostTable = collect($report['tables'])->firstWhere('title', 'Lost referring domains');
        $this->assertSame(['partner-b.com', 20], $lostTable['rows'][0]);
        $brokenTable = collect($report['tables'])->firstWhere('title', 'Broken targets');
        $this->assertSame(['/kitchen-remodeling-old', 3], $brokenTable['rows'][0]);
        $this->assertNotEmpty($report['note']);
    }

    public function test_report_is_empty_before_anything_is_collected(): void
    {
        $source = app(BacklinksSource::class);
        $report = $source->report();
        $this->assertSame([], $report['tiles']);
        $this->assertSame([], $report['tables']);
        $this->assertNotEmpty($report['note']);
    }

    public function test_cost_cap_stops_making_calls_but_keeps_what_it_collected(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor($this->day1());
        // Under the cost of even the first summary call, so nothing after it should fire.
        config(['seo-intel.families.backlinks.max_cost' => 0.001]);
        $this->artisan('seo:intel', ['family' => ['backlinks'], '--budget' => 1])->assertExitCode(0);

        $calls = Http::recorded(fn ($r) => ! str_contains($r->url(), 'user_data'))->count();
        $this->assertSame(1, $calls, 'only the first call (our domain summary) is made once the cap is already exceeded');

        $snapshots = DB::table('seo_intel_snapshots')->get();
        $this->assertSame(1, $snapshots->count());
        $this->assertSame('domain', $snapshots->first()->kind);
        $this->assertSame('gs.construction', $snapshots->first()->subject);
    }

    public function test_estimate_cost_is_positive_and_reasonable(): void
    {
        $source = app(BacklinksSource::class);
        $this->assertGreaterThan(0, $source->estimateCost());
        $this->assertLessThan(1.0, $source->estimateCost());
    }

    public function test_broken_backlinks_filter_is_sent_as_a_flat_single_condition(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->fakeFor($this->day1());
        $this->artisan('seo:intel', ['family' => ['backlinks'], '--budget' => 1])->assertExitCode(0);

        // Per https://docs.dataforseo.com/v3/backlinks/filters/, a single filter
        // condition is a flat 3-element array — not an array wrapping one.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'backlinks/backlinks/live')) {
                return true;
            }

            return $request->data()[0]['filters'] === ['is_broken', '=', true];
        });
    }
}
