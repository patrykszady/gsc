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
}
