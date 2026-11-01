<?php

namespace Tests\Feature\SeoIntel;

use App\Support\Seo\CompetitorDomainSources;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CompetitorDomainSources::competitorDomains() is the shared source every
 * SEO intel family reads through App\Services\Seo\Intel\IntelSource::
 * competitorDomains() (LabsSource's fallback, BacklinksSource,
 * DomainAnalyticsSource) — so filtering it here fixes those transitively.
 *
 * Kit SEO tools port (0.19.1, 2026-11-01): extracted from the retired
 * App\Console\Commands\SeoDomainOverview when that command was replaced by
 * the kit's SsSystems\Platform\Seo\Visibility\Console\DomainOverviewCommand
 * (a different domain source — the site profile + seo_rank_snapshots, see
 * that command's own docblock). SeoDomainOverview::shareOfVoiceDomains()
 * (the owner-curated /compare companies first, read only by that retired
 * command's own weekly footprint list) is gone with it — nothing else ever
 * called it — so its test is gone too, not carried over.
 */
class CompetitorDomainSourcesTest extends TestCase
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

        $domains = CompetitorDomainSources::competitorDomains(10);

        $this->assertContains('realrivalco.com', $domains);
        $this->assertContains('brandnewrival.com', $domains);
        $this->assertNotContains('facebook.com', $domains, 'the map_pack_competitors aggregator host must be dropped even with the highest pack_points');
        $this->assertNotContains('houzz.com', $domains, 'the discovery-report aggregator host must be dropped even though it is listed first');
    }

    public function test_map_pack_hosts_lead_and_the_discovery_file_fills_the_rest(): void
    {
        DB::table('map_pack_competitors')->insert([
            ['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Map Pack Host', 'url' => 'https://mappackhost.com/', 'host' => 'mappackhost.com', 'pack_points' => 99, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Storage::fake('local');
        Storage::disk('local')->put('reports/competitor-discovery.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'domains' => [['host' => 'discoveredrival.com', 'areas' => 3, 'best_pos' => 2, 'known' => false]],
        ]));

        $this->assertSame(['mappackhost.com', 'discoveredrival.com'], CompetitorDomainSources::competitorDomains(10));

        // The limit trims from the end.
        $this->assertSame(['mappackhost.com'], CompetitorDomainSources::competitorDomains(1));
    }
}
