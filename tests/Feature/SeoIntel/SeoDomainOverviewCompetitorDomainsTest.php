<?php

namespace Tests\Feature\SeoIntel;

use App\Console\Commands\SeoDomainOverview;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SeoDomainOverview::competitorDomains() is the shared source every other
 * family reads through IntelSource::competitorDomains() (LabsSource's
 * fallback, BacklinksSource, DomainAnalyticsSource) plus SeoKeywordResearch —
 * so filtering it here fixes those transitively.
 */
class SeoDomainOverviewCompetitorDomainsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_aggregator_hosts_are_dropped_from_both_sources_even_when_they_rank_first(): void
    {
        DB::table('map_pack_competitors')->insert([
            ['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'A Facebook Page', 'url' => 'https://facebook.com/somepage', 'host' => 'facebook.com', 'pack_points' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'place_id' => 'p2', 'keyword' => 'kitchen remodeling', 'name' => 'Real Rival Co', 'url' => 'https://realrivalco.com/', 'host' => 'realrivalco.com', 'pack_points' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('reports/competitor-discovery.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'domains' => [
                // Listed FIRST and with the best position — must still be dropped.
                ['host' => 'houzz.com', 'areas' => 10, 'best_pos' => 1, 'known' => false],
                ['host' => 'brandnewrival.com', 'areas' => 3, 'best_pos' => 4, 'known' => false],
            ],
        ]));

        $domains = SeoDomainOverview::competitorDomains(10);

        $this->assertContains('realrivalco.com', $domains);
        $this->assertContains('brandnewrival.com', $domains);
        $this->assertNotContains('facebook.com', $domains, 'the map_pack_competitors aggregator host must be dropped even with the highest pack_points');
        $this->assertNotContains('houzz.com', $domains, 'the discovery-report aggregator host must be dropped even though it is listed first');
    }

    public function test_the_share_of_voice_set_leads_with_the_curated_compare_companies_then_discovery_then_the_map_pack(): void
    {
        config(['competitors.competitors' => [
            ['name' => 'Curated One', 'website' => 'https://www.curatedone.com'],
            ['name' => 'Curated Two', 'website' => 'https://curatedtwo.com/'],
        ]]);
        DB::table('map_pack_competitors')->insert([
            ['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Map Pack Host', 'url' => 'https://mappackhost.com/', 'host' => 'mappackhost.com', 'pack_points' => 99, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Storage::fake('local');
        Storage::disk('local')->put('reports/competitor-discovery.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'domains' => [['host' => 'discoveredrival.com', 'areas' => 3, 'best_pos' => 2, 'known' => false]],
        ]));

        // The map-pack host has the most points and, in the shared source
        // the other intel families read, still comes first. The weekly
        // footprint's own list leads with the owner's /compare companies,
        // then discovery, then the map pack fills what is left.
        $this->assertSame(
            ['curatedone.com', 'curatedtwo.com', 'discoveredrival.com', 'mappackhost.com'],
            SeoDomainOverview::shareOfVoiceDomains(10),
        );
        $this->assertSame(['mappackhost.com', 'discoveredrival.com'], SeoDomainOverview::competitorDomains(10), 'the shared source is unchanged: map pack first, discovery second, and it never reads the curated list');

        // The limit trims from the END, so it is never a curated company that goes.
        $this->assertSame(['curatedone.com', 'curatedtwo.com', 'discoveredrival.com'], SeoDomainOverview::shareOfVoiceDomains(3));
    }
}
