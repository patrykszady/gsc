<?php

namespace Tests\Feature\SeoIntel;

use App\Services\Seo\Intel\Sources\DomainAnalyticsSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainAnalyticsSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var array<string, array> domain => technologies tree, mutated between runs */
    protected static array $tech = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://gs.construction',
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [DomainAnalyticsSource::class],
            'seo-intel.families.domain_analytics.competitors' => 4,
            'seo-intel.families.domain_analytics.whois_chunk' => 10,
            'seo-intel.families.domain_analytics.max_cost' => 5,
        ]);

        // Four competitors seeded via map_pack_competitors, the shared discovery table.
        foreach (['alpha.com', 'beta.com', 'gamma.com', 'delta.com'] as $i => $host) {
            DB::table('map_pack_competitors')->insert([
                'place_id' => 'p' . $i, 'keyword' => 'kitchen remodeling', 'name' => ucfirst(explode('.', $host)[0]),
                'host' => $host, 'pack_points' => 5, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        static::$tech = [
            'gs.construction' => ['content' => ['cms' => ['Joomla']]],
            'alpha.com' => ['web_development' => ['javascript_libraries' => ['Intercom']]],
            'beta.com' => ['web_development' => ['javascript_libraries' => ['Drift']]],
            'gamma.com' => ['add_ons' => ['review_plugins' => ['Yotpo']]],
            'delta.com' => ['content' => ['cms' => ['WordPress']]],
        ];

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 50]]]]]]);
            }
            $body = json_decode($request->body(), true);
            $task = $body[0] ?? [];

            if (str_contains($url, 'domain_technologies')) {
                $domain = (string) ($task['target'] ?? '');

                return Http::response(['tasks' => [['cost' => 0.012, 'status_code' => 20000, 'result' => [[
                    'type' => 'domain_technology_item', 'domain' => $domain, 'title' => ucfirst($domain), 'domain_rank' => 100,
                    'technologies' => static::$tech[$domain] ?? [],
                ]]]]]);
            }

            if (str_contains($url, 'whois/overview')) {
                $domains = $task['filters'][0][2] ?? [];
                $now = Carbon::now();
                $items = [];
                foreach ($domains as $domain) {
                    // gamma.com's registration is about to lapse; everyone else is safely old.
                    $expiresInDays = $domain === 'gamma.com' ? 30 : 400;
                    $items[] = [
                        'domain' => $domain,
                        'created_datetime' => $now->copy()->subYears(5)->toIso8601String(),
                        'expiration_datetime' => $now->copy()->addDays($expiresInDays)->toIso8601String(),
                        'registrar' => 'GoDaddy.com, LLC',
                        'registered' => true,
                        'metrics' => ['organic' => ['count' => 12, 'etv' => 34.5]],
                        'backlinks_info' => ['referring_domains' => 7, 'backlinks' => 40],
                    ];
                }

                return Http::response(['tasks' => [['cost' => 0.1, 'status_code' => 20000, 'result' => [[
                    'total_count' => count($items), 'items_count' => count($items), 'items' => $items,
                ]]]]]);
            }

            return Http::response(['tasks' => [['cost' => 0, 'status_code' => 20000, 'result' => []]]]);
        });
    }

    public function test_first_run_stores_tech_and_whois_snapshots_and_the_domain_expiring_finding(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['domain_analytics'], '--budget' => 5])->assertExitCode(0);

        $this->assertSame(5, DB::table('seo_intel_snapshots')->where('kind', 'tech')->count());
        $this->assertSame(4, DB::table('seo_intel_snapshots')->where('kind', 'whois')->count());

        $alpha = json_decode((string) DB::table('seo_intel_snapshots')->where('kind', 'tech')->where('subject', 'alpha.com')->value('metrics'), true);
        $this->assertSame(1, $alpha['has_live_chat']);
        $this->assertSame(0, $alpha['has_review_widget']);

        $ours = json_decode((string) DB::table('seo_intel_snapshots')->where('kind', 'tech')->where('subject', 'gs.construction')->value('metrics'), true);
        $this->assertSame(0, $ours['has_live_chat']);

        // Only 2/4 competitors have live chat on run one — below the 60% threshold, no gap finding yet.
        $this->assertSame(0, DB::table('seo_intel_findings')->where('code', 'domain_analytics.capability_gap')->count());

        // gamma.com's domain is 30 days from expiring — the finding opens on the very first run.
        $expiring = DB::table('seo_intel_findings')->where('code', 'domain_analytics.domain_expiring')->first();
        $this->assertNotNull($expiring);
        $this->assertSame('gamma.com', $expiring->subject);
        $this->assertSame(30, json_decode((string) $expiring->delta, true)['days_until_expiration']['now']);

        $run = DB::table('seo_intel_runs')->first();
        $this->assertEqualsWithDelta(5 * 0.012 + 0.1, (float) $run->cost, 0.001);
    }

    public function test_second_run_finds_a_competitor_adopting_live_chat_and_the_resulting_capability_gap(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['domain_analytics'], '--budget' => 5])->assertExitCode(0);

        // gamma.com adopts live chat; we also change our own stack, one week later.
        Carbon::setTestNow('2026-09-12 06:00:00');
        static::$tech['gamma.com'] = ['add_ons' => ['review_plugins' => ['Yotpo']], 'web_development' => ['javascript_libraries' => ['Intercom']]];
        static::$tech['gs.construction'] = ['content' => ['cms' => ['Joomla']], 'add_ons' => ['wordpress_plugins' => ['Yoast SEO']]];
        $this->artisan('seo:intel', ['family' => ['domain_analytics'], '--budget' => 5])->assertExitCode(0);

        $added = DB::table('seo_intel_findings')->where('code', 'domain_analytics.tech_added')->first();
        $this->assertNotNull($added);
        $this->assertSame('gamma.com', $added->subject);
        $this->assertSame('Intercom', $added->key);

        // Now 3 of 4 competitors (alpha, beta, gamma) have live chat — >=60%, we still don't: the gap opens.
        $gap = DB::table('seo_intel_findings')->where('code', 'domain_analytics.capability_gap')->where('key', 'has_live_chat')->first();
        $this->assertNotNull($gap);
        $this->assertSame('gs.construction', $gap->subject);
        $this->assertSame('info', $gap->severity);
        $this->assertSame(3, json_decode((string) $gap->delta, true)['has_live_chat']['now']);

        $ourChange = DB::table('seo_intel_findings')->where('code', 'domain_analytics.our_tech_changed')->first();
        $this->assertNotNull($ourChange);
        $this->assertStringContainsString('Yoast SEO', (string) $ourChange->detail);

        // gamma.com's expiry finding survives across the run too (still <90 days out from "now").
        $expiring = DB::table('seo_intel_findings')->where('code', 'domain_analytics.domain_expiring')->whereNull('resolved_at')->first();
        $this->assertNotNull($expiring);
        $this->assertSame('gamma.com', $expiring->subject);
    }

    public function test_cost_cap_stops_calls_once_max_cost_is_crossed(): void
    {
        config(['seo-intel.families.domain_analytics.max_cost' => 0.03]);
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['domain_analytics'], '--budget' => 5])->assertExitCode(0);

        // 0.03 / 0.012 per tech call affords 2 calls before the guard trips; whois never runs.
        $techCalls = DB::table('seo_intel_snapshots')->where('kind', 'tech')->count();
        $this->assertLessThan(5, $techCalls);
        $this->assertGreaterThan(0, $techCalls);
        $this->assertSame(0, DB::table('seo_intel_snapshots')->where('kind', 'whois')->count());
        $run = DB::table('seo_intel_runs')->first();
        $this->assertLessThanOrEqual(0.03 + 0.012, (float) $run->cost, 'the guard checks before each call, so it can cross by at most one call');
    }

    public function test_report_shape_has_tiles_and_a_competitor_table(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['domain_analytics'], '--budget' => 5])->assertExitCode(0);

        $source = app(DomainAnalyticsSource::class);
        $report = $source->report();

        $this->assertSame(4, $report['tiles'][0]['value']);
        $this->assertSame('Competitors profiled', $report['tiles'][0]['label']);
        $this->assertSame(['Domain', 'CMS', 'Live chat', 'Reviews widget', 'Domain age'], $report['tables'][0]['columns']);
        $this->assertCount(4, $report['tables'][0]['rows']);
        $this->assertStringContainsString('gs.construction', $report['note']);
    }

    public function test_report_is_empty_but_does_not_throw_before_any_run(): void
    {
        $source = app(DomainAnalyticsSource::class);
        $report = $source->report();
        $this->assertSame([], $report['tiles']);
        $this->assertSame([], $report['tables']);
        $this->assertNotEmpty($report['note']);
    }
}
