<?php

namespace Tests\Unit;

use App\Models\AreaServed;
use App\Services\Seo\CompetitorSiteFetcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompetitorSiteFetcherTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reads_structure_services_and_our_towns_only(): void
    {
        AreaServed::create(['city' => 'Highland Park', 'slug' => 'highland-park']);
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine']);
        Http::fake(['prism.test/*' => Http::response('<html><head><title>Prism Remodeling</title><meta name="description" content="Kitchen and bathroom remodeling in Highland Park, IL"></head><body><h1>Bathroom Remodeling Highland Park</h1><h2>Kitchen remodels</h2><h2>Basement finishing</h2><img src="x.jpg"><p>Serving Highland Park and Deerfield.</p></body></html>')]);

        $r = app(CompetitorSiteFetcher::class)->read('https://prism.test/');

        $this->assertSame('Prism Remodeling', $r['site_title']);
        $this->assertSame(['Bathroom Remodeling Highland Park', 'Kitchen remodels', 'Basement finishing'], $r['site_headings']);
        $this->assertSame(['Highland Park'], $r['site_towns']);
        $this->assertContains('Kitchen', $r['site_services']);
        $this->assertContains('Basement', $r['site_services']);
        $this->assertArrayNotHasKey('images', $r);
    }
}
