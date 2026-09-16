<?php

namespace Tests\Feature\Console;

use App\Services\Seo\CompetitorSiteFetcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * seo:map-pack-competitors reads a map-pack business's homepage for
 * analysis. When the GBP "website" field actually points at a directory or
 * social page (Facebook, Houzz, ...), there is no real homepage to read, and
 * reading it would misattribute that page's content onto every other
 * business that happens to share the same host — see CompetitorFilter.
 */
class SeoMapPackCompetitorsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_aggregator_hosted_business_is_never_fetched_as_a_website(): void
    {
        DB::table('map_pack_competitors')->insert([
            ['site_id' => null, 'place_id' => 'p1', 'keyword' => 'kitchen remodeling', 'name' => 'Facebook-Only Shop', 'url' => 'https://facebook.com/some-shop', 'host' => 'facebook.com', 'pack_points' => 10, 'reviews' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => null, 'place_id' => 'p2', 'keyword' => 'kitchen remodeling', 'name' => 'Real Rival Co', 'url' => 'https://realrivalco.com/', 'host' => 'realrivalco.com', 'pack_points' => 8, 'reviews' => 12, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $fetcher = $this->mock(CompetitorSiteFetcher::class);
        $fetcher->shouldReceive('read')->once()->with('https://realrivalco.com/')->andReturn(['site_title' => 'Real Rival', 'site_fetched_at' => now()]);

        $this->artisan('seo:map-pack-competitors', ['--limit' => 20])->assertExitCode(0);

        $fb = DB::table('map_pack_competitors')->where('host', 'facebook.com')->first();
        $this->assertSame('Facebook-Only Shop', $fb->name);
        $this->assertSame(10, (int) $fb->pack_points);
        $this->assertSame(5, (int) $fb->reviews);
        $this->assertNull($fb->site_title, 'the aggregator-hosted row must never be fetched-and-updated');

        $rival = DB::table('map_pack_competitors')->where('host', 'realrivalco.com')->first();
        $this->assertSame('Real Rival', $rival->site_title);
    }
}
