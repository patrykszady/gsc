<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One-off cleanup for SEO intel data an aggregator/directory domain leaked
 * into before CompetitorFilter existed to stop it at the source. Every
 * scenario seeds one aggregator artifact alongside a real-local counterpart
 * that must survive untouched throughout.
 */
class SeoCompetitorsPurgeAggregatorsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function seedFixtures(): void
    {
        DB::table('seo_intel_findings')->insert([
            // 1. serp.competitor_top3 — key is the offending domain directly.
            ['site_id' => null, 'family' => 'serp', 'code' => 'serp.competitor_top3', 'severity' => 'info', 'fingerprint' => 'fp-yelp', 'subject' => 'kitchen remodeling', 'key' => 'yelp.com', 'title' => 'yelp.com entered the top 3', 'detail' => 'It now outranks us.', 'first_seen_on' => '2026-09-01', 'last_seen_on' => '2026-09-01', 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'family' => 'serp', 'code' => 'serp.competitor_top3', 'severity' => 'info', 'fingerprint' => 'fp-real-rival', 'subject' => 'kitchen remodeling', 'key' => 'realrivalco.com', 'title' => 'realrivalco.com entered the top 3', 'detail' => 'It now outranks us.', 'first_seen_on' => '2026-09-01', 'last_seen_on' => '2026-09-01', 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()],
            // 2. labs.keyword_gap — key is intentionally null; domain is the leading token of `detail`.
            ['site_id' => null, 'family' => 'labs', 'code' => 'labs.keyword_gap', 'severity' => 'info', 'fingerprint' => 'fp-houzz-gap', 'subject' => 'kitchen remodeling', 'key' => null, 'title' => 'Keyword gap', 'detail' => 'houzz.com ranks #3 for "kitchen remodeling" (~90 searches/mo); we do not rank in the top 20.', 'first_seen_on' => '2026-09-01', 'last_seen_on' => '2026-09-01', 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'family' => 'labs', 'code' => 'labs.keyword_gap', 'severity' => 'info', 'fingerprint' => 'fp-real-gap', 'subject' => 'bathroom remodeling', 'key' => null, 'title' => 'Keyword gap', 'detail' => 'realrivalco.com ranks #5 for "bathroom remodeling" (~50 searches/mo); we do not rank in the top 20.', 'first_seen_on' => '2026-09-01', 'last_seen_on' => '2026-09-01', 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()],
            // 3. labs.new_competitor — subject is the domain directly.
            ['site_id' => null, 'family' => 'labs', 'code' => 'labs.new_competitor', 'severity' => 'info', 'fingerprint' => 'fp-bbb-new', 'subject' => 'bbb.org', 'key' => null, 'title' => 'New organic competitor: bbb.org', 'detail' => 'bbb.org entered the top 10.', 'first_seen_on' => '2026-09-01', 'last_seen_on' => '2026-09-01', 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'family' => 'labs', 'code' => 'labs.new_competitor', 'severity' => 'info', 'fingerprint' => 'fp-real-new', 'subject' => 'realrivalco.com', 'key' => null, 'title' => 'New organic competitor: realrivalco.com', 'detail' => 'realrivalco.com entered the top 10.', 'first_seen_on' => '2026-09-01', 'last_seen_on' => '2026-09-01', 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('seo_intel_snapshots')->insert([
            // Deleted via the exclusion list AND its own giant metrics.
            ['site_id' => null, 'family' => 'labs', 'kind' => 'competitor', 'subject' => 'bbb.org', 'taken_on' => '2026-09-01', 'run_id' => 'r1', 'metrics' => json_encode(['organic_count' => 300000, 'organic_etv' => 400000]), 'payload' => json_encode(['domain' => 'bbb.org']), 'created_at' => now(), 'updated_at' => now()],
            // A real local competitor's ordinary metrics — must survive.
            ['site_id' => null, 'family' => 'labs', 'kind' => 'competitor', 'subject' => 'realrivalco.com', 'taken_on' => '2026-09-01', 'run_id' => 'r1', 'metrics' => json_encode(['organic_count' => 400, 'organic_etv' => 900]), 'payload' => json_encode(['domain' => 'realrivalco.com']), 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('map_pack_competitors')->insert([
            ['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Facebook-Only Shop', 'url' => 'https://facebook.com/shop', 'host' => 'facebook.com', 'pack_points' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'place_id' => 'p2', 'keyword' => 'kitchen remodeling', 'name' => 'Real Rival Co', 'url' => 'https://realrivalco.com/', 'host' => 'realrivalco.com', 'pack_points' => 8, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_dry_run_reports_nonzero_counts_and_writes_nothing(): void
    {
        $this->seedFixtures();

        $this->artisan('seo:competitors-purge-aggregators')
            ->expectsOutputToContain('Findings to resolve: 3')
            ->expectsOutputToContain('Snapshots to delete: 1')
            ->expectsOutputToContain('map_pack_competitors hosts to clear: 1')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('seo_intel_findings')->whereNotNull('resolved_at')->count(), 'dry run must resolve nothing');
        $this->assertSame(2, DB::table('seo_intel_snapshots')->count(), 'dry run must delete nothing');
        $this->assertSame('facebook.com', DB::table('map_pack_competitors')->where('place_id', 'p1')->value('host'), 'dry run must clear no host');
    }

    public function test_apply_resolves_deletes_and_nulls_only_the_aggregator_artifacts(): void
    {
        $this->seedFixtures();

        $this->artisan('seo:competitors-purge-aggregators', ['--apply' => true])->assertExitCode(0);

        // Findings: the aggregator ones are resolved, the real ones untouched.
        $this->assertNotNull(DB::table('seo_intel_findings')->where('fingerprint', 'fp-yelp')->value('resolved_at'));
        $this->assertNull(DB::table('seo_intel_findings')->where('fingerprint', 'fp-real-rival')->value('resolved_at'));
        $this->assertNotNull(DB::table('seo_intel_findings')->where('fingerprint', 'fp-houzz-gap')->value('resolved_at'));
        $this->assertNull(DB::table('seo_intel_findings')->where('fingerprint', 'fp-real-gap')->value('resolved_at'));
        $this->assertNotNull(DB::table('seo_intel_findings')->where('fingerprint', 'fp-bbb-new')->value('resolved_at'));
        $this->assertNull(DB::table('seo_intel_findings')->where('fingerprint', 'fp-real-new')->value('resolved_at'));

        // Snapshots: the aggregator/giant row is gone, the real one survives.
        $this->assertNull(DB::table('seo_intel_snapshots')->where('subject', 'bbb.org')->first());
        $this->assertNotNull(DB::table('seo_intel_snapshots')->where('subject', 'realrivalco.com')->first());

        // map_pack_competitors: the aggregator row survives with host cleared; the real row is untouched.
        $fb = DB::table('map_pack_competitors')->where('place_id', 'p1')->first();
        $this->assertNull($fb->host);
        $this->assertSame('Facebook-Only Shop', $fb->name);
        $this->assertSame(10, (int) $fb->pack_points);
        $this->assertSame('realrivalco.com', DB::table('map_pack_competitors')->where('place_id', 'p2')->value('host'));

        // A second --apply finds nothing left to do.
        $this->artisan('seo:competitors-purge-aggregators', ['--apply' => true])
            ->expectsOutputToContain('Findings to resolve: 0')
            ->expectsOutputToContain('Snapshots to delete: 0')
            ->expectsOutputToContain('map_pack_competitors hosts to clear: 0')
            ->assertExitCode(0);

        $this->assertSame(3, DB::table('seo_intel_findings')->whereNotNull('resolved_at')->count(), 'idempotent: no further resolves');
    }
}
