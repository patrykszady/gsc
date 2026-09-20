<?php

namespace Tests\Feature;

use App\Console\Commands\SeoAiMentions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoDataForSeoIntelTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p', 'app.url' => 'https://gs.construction']);
        // These tests seed their competitors through the map pack. The
        // weekly footprint (SeoDomainOverview::shareOfVoiceDomains) leads
        // with the owner-curated /compare list and then the discovery
        // report, so isolate both — otherwise the real config and this dev
        // box's real discovery file fill every slot.
        config(['competitors.competitors' => []]);
        Storage::fake('local');
        DB::table('map_pack_competitors')->insert(['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Prism', 'url' => 'https://prism.test/', 'host' => 'prism.test', 'pack_points' => 9, 'seen_points' => 12, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_domain_overview_records_us_and_competitors_with_backlink_profile(): void
    {
        Http::fake([
            '*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]),
            '*/domain_rank_overview/live' => Http::response(['tasks' => [['cost' => 0.012, 'status_code' => 20000, 'result' => [['items' => [['metrics' => ['organic' => ['pos_1' => 2, 'pos_2_3' => 3, 'pos_4_10' => 10, 'pos_11_20' => 7, 'count' => 60, 'etv' => 150.5, 'is_new' => 4, 'is_lost' => 1]]]]]]]]]),
            '*/backlinks/summary/live' => Http::response(['tasks' => [['cost' => 0.024, 'status_code' => 20000, 'result' => [['rank' => 59, 'backlinks' => 42, 'referring_domains' => 33, 'backlinks_spam_score' => 5]]]]]),
        ]);

        $this->artisan('seo:domain-overview', ['--competitors' => 3])->assertExitCode(0);

        $us = DB::table('seo_domain_overviews')->where('is_us', true)->first();
        $this->assertSame('gs.construction', $us->domain);
        $this->assertSame(15, (int) $us->pos_1 + (int) $us->pos_2_3 + (int) $us->pos_4_10);
        $this->assertSame(33, (int) $us->referring_domains);
        $this->assertNotNull(DB::table('seo_domain_overviews')->where('domain', 'prism.test')->first());
    }

    public function test_domain_overview_refuses_a_low_budget_before_any_api_call(): void
    {
        // Unlike its siblings, domain-overview used to check balance only,
        // never estimate-vs-budget — so a --budget too small to cover even
        // one domain's ~$0.04 would sail through and spend anyway.
        Http::fake(['*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 100]]]]]])]);

        $this->artisan('seo:domain-overview', ['--competitors' => 3, '--budget' => 0.01])
            ->expectsOutputToContain('exceeds --budget')
            ->assertExitCode(1);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'domain_rank_overview') || str_contains($r->url(), 'backlinks/summary'));
        $this->assertSame(0, DB::table('seo_domain_overviews')->count());
    }

    public function test_domain_overview_fails_closed_when_the_balance_check_itself_fails(): void
    {
        // A failed balance() call (network/auth/outage) returns null. The
        // old guard (`$balance !== null && $balance < $estimate`) treated
        // null as "unlimited" and ran anyway; this must refuse instead.
        Http::fake(['*/appendix/user_data' => Http::response('', 500)]);

        $this->artisan('seo:domain-overview', ['--competitors' => 3])
            ->expectsOutputToContain('balance unknown')
            ->assertExitCode(1);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'domain_rank_overview') || str_contains($r->url(), 'backlinks/summary'));
        $this->assertSame(0, DB::table('seo_domain_overviews')->count());
    }

    public function test_domain_overview_exits_non_zero_when_every_domain_fails(): void
    {
        // The balance precheck passes (account is fine), but every per-domain
        // call then fails (a mid-run outage, a bad location code, etc.) — the
        // old code just `continue`d past each one and exited SUCCESS having
        // written nothing.
        Http::fake([
            '*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]),
            '*/domain_rank_overview/live' => Http::response('', 500),
            '*/backlinks/summary/live' => Http::response('', 500),
        ]);

        $this->artisan('seo:domain-overview', ['--competitors' => 3])
            ->expectsOutputToContain('Every domain failed')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('seo_domain_overviews')->count());
    }

    public function test_backlink_gap_fails_closed_when_the_balance_check_itself_fails(): void
    {
        Http::fake(['*/appendix/user_data' => Http::response('', 500)]);

        $this->artisan('seo:backlink-gap', ['--competitors' => 2])
            ->expectsOutputToContain('balance unknown')
            ->assertExitCode(1);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'referring_domains'));
        $this->assertSame(0, DB::table('seo_backlink_prospects')->count());
    }

    public function test_ai_mentions_fails_closed_when_the_balance_check_itself_fails(): void
    {
        config(['seo.ai_mentions.towns' => ['Kenilworth'], 'seo.ai_mentions.services' => ['kitchen remodeling' => 'kitchen-remodeling']]);
        Http::fake(['*/appendix/user_data' => Http::response('', 500)]);

        $this->artisan('seo:ai-mentions', ['--platforms' => 'chat_gpt', '--budget' => 2])
            ->expectsOutputToContain('balance unknown')
            ->assertExitCode(1);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'llm_responses'));
        $this->assertSame(0, DB::table('seo_ai_mentions')->count());
    }

    public function test_backlink_gap_lists_domains_linking_to_competitors_but_not_us(): void
    {
        DB::table('map_pack_competitors')->insert(['site_id' => null, 'place_id' => 'p2', 'keyword' => 'kitchen remodeling', 'name' => 'Dream', 'url' => 'https://dream.test/', 'host' => 'dream.test', 'pack_points' => 5, 'seen_points' => 8, 'created_at' => now(), 'updated_at' => now()]);
        $ref = fn (array $domains) => Http::response(['tasks' => [['cost' => 0.024, 'status_code' => 20000, 'result' => [['items' => array_map(fn ($d) => ['domain' => $d, 'rank' => 300, 'backlinks' => 3, 'referring_links_platform_types' => ['organization' => 3]], $domains)]]]]]);
        Http::fake(function ($request) use ($ref) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]);
            }
            $target = $request['0']['target'] ?? '';

            return match ($target) {
                'gs.construction' => $ref(['houzz.com']),
                'prism.test' => $ref(['houzz.com', 'yelp.com', 'nari.org']),
                'dream.test' => $ref(['yelp.com', 'nari.org', 'randomblog.test']),
                default => $ref([]),
            };
        });

        $this->artisan('seo:backlink-gap', ['--competitors' => 2])->assertExitCode(0);

        $yelp = DB::table('seo_backlink_prospects')->where('domain', 'yelp.com')->first();
        $this->assertSame(2, (int) $yelp->competitor_count);
        $this->assertFalse((bool) $yelp->links_to_us);
        $houzz = DB::table('seo_backlink_prospects')->where('domain', 'houzz.com')->first();
        $this->assertTrue((bool) $houzz->links_to_us);
    }

    public function test_ai_mentions_records_whether_we_are_named_and_who_is(): void
    {
        config(['seo.ai_mentions.towns' => ['Kenilworth'], 'seo.ai_mentions.services' => ['kitchen remodeling' => 'kitchen-remodeling']]);
        Http::fake([
            '*/appendix/user_data' => Http::response(['tasks' => [['result' => [['money' => ['balance' => 20]]]]]]),
            '*/chat_gpt/llm_responses/live' => Http::response(['tasks' => [['cost' => 0.03, 'status_code' => 20000, 'result' => [['items' => [['sections' => [['type' => 'text', 'text' => "Top options:\n\n**Prism Kitchen & Bath**\n5.0 (53 reviews)\n\n**[GS Construction & Remodeling](https://gs.construction/)**\nFamily-owned, Arlington Heights.\n"]]]]]]]]]),
            '*/gemini/llm_responses/live' => Http::response(['tasks' => [['cost' => 0.04, 'status_code' => 20000, 'result' => [['items' => [['sections' => [['type' => 'text', 'text' => "1. **Airoom Architects**\n2. **Orren Pickell Building Group**\n"]]]]]]]]]),
        ]);

        $this->artisan('seo:ai-mentions', ['--platforms' => 'chat_gpt,gemini', '--budget' => 2])->assertExitCode(0);

        $gpt = DB::table('seo_ai_mentions')->where('platform', 'chat_gpt')->first();
        $this->assertTrue((bool) $gpt->mentioned);
        $this->assertSame(2, (int) $gpt->mention_rank);
        $this->assertContains('Prism Kitchen & Bath', json_decode($gpt->businesses_named, true));

        $gem = DB::table('seo_ai_mentions')->where('platform', 'gemini')->first();
        $this->assertFalse((bool) $gem->mentioned);
        $this->assertSame(['Airoom Architects', 'Orren Pickell Building Group'], json_decode($gem->businesses_named, true));

        $snap = $this->withHeaders(['Authorization' => 'Bearer t'])->getJson('/api/admin/v1/seo/snapshot');
        // auth is covered elsewhere; the snapshot builder itself is exercised via the controller in SeoReportControllerTest
        $this->assertContains($snap->status(), [200, 401]);
    }

    public function test_business_name_parser_handles_the_engines_formats(): void
    {
        $names = SeoAiMentions::businessesNamed("**Majestic Tiles Remodeling Services**\n**Open now · Bathroom remodeler · 5.0 (113 reviews)**\n**Kenilworth, Illinois**\n**Highly-Rated Companies**\n[Glenbrook Remodeling](https://glenbrook.test)\n3. **Airoom** - design-build\n**1. Orren Pickell Building Group**\n");
        $this->assertSame(['Majestic Tiles Remodeling Services', 'Glenbrook Remodeling', 'Airoom', 'Orren Pickell Building Group'], $names);
    }
}
