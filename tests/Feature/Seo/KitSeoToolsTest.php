<?php

namespace Tests\Feature\Seo;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Seo\Contracts\SeoSiteProfile;
use SsSystems\Platform\Seo\DataForSeo\Contracts\DataForSeoApi;
use Tests\TestCase;

/**
 * The kit's SEO tools (0.19.1, 2026-11-01) wired directly into
 * gs.construction's own container/commands/schedule — phase 3 of the port
 * (see J. Peterson Design's identical KitSeoToolsTest, the reference this
 * mirrors). The report-snapshot shape itself (rankings/keyword_research/
 * dataforseo/map_pack/intel/ai_traffic keys) is covered by
 * SeoReportControllerTest; this file covers the container bindings, the
 * commands this site registers, and their schedule — the parts a snapshot
 * request never exercises directly.
 */
class KitSeoToolsTest extends TestCase
{
    public function test_the_profile_resolves_from_the_container_reading_this_sites_own_config(): void
    {
        $profile = app(SeoSiteProfile::class);

        $this->assertSame('gs.construction', $profile->domain());
        $this->assertSame('GS Construction & Remodeling', $profile->brandName());
        $this->assertSame('Arlington Heights,Illinois,United States', $profile->locationName());
        $this->assertEqualsWithDelta(42.102847, $profile->latitude(), 0.001);
        $this->assertNotEmpty($profile->trackedQueries(), 'gs.construction configures seo.rank_tracker.web_queries');
        $this->assertArrayHasKey('q', $profile->trackedQueries()[0]);
        $this->assertContains('kitchen remodeling', $profile->services());
        $this->assertContains('yelp.com', $profile->competitorExclusions());
    }

    public function test_the_dataforseo_client_resolves_from_the_same_credential_the_sites_own_service_reads(): void
    {
        Http::fake();

        $api = app(DataForSeoApi::class);
        $this->assertFalse($api->isConfigured(), 'no DATAFORSEO_LOGIN/PASSWORD and no stored platform setting in the test environment');
        $this->assertSame([], $api->request('POST', '/v3/serp/google/organic/live/regular', []));
        Http::assertNothingSent();
    }

    public function test_the_commands_this_site_runs_are_all_registered(): void
    {
        $commands = array_keys(Artisan::all());

        foreach ([
            'seo:track-rankings', 'seo:show-rankings', 'seo:keyword-research', 'seo:domain-overview',
            'seo:backlink-gap', 'seo:ai-mentions', 'seo:competitor-brand-track', 'seo:map-pack-grid',
            // This site's OWN command (it reads a competitor's homepage,
            // which the kit's own MapPackCompetitorsCommand does not do) —
            // never the kit's class, but the same artisan name either way.
            'seo:map-pack-competitors',
        ] as $name) {
            $this->assertContains($name, $commands, "{$name} is registered");
        }
    }

    public function test_the_schedule_still_runs_every_kit_command_at_its_existing_cadence(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('seo:track-rankings')
            ->expectsOutputToContain('seo:keyword-research')
            ->expectsOutputToContain('seo:domain-overview')
            ->expectsOutputToContain('seo:backlink-gap')
            ->expectsOutputToContain('seo:ai-mentions')
            ->expectsOutputToContain('seo:competitor-brand-track')
            ->expectsOutputToContain('seo:map-pack-grid')
            ->expectsOutputToContain('seo:map-pack-competitors');
    }

    public function test_a_paid_command_stops_cleanly_without_credentials(): void
    {
        Http::fake();

        $this->artisan('seo:keyword-research --budget=1')->assertExitCode(0);
        $this->artisan('seo:track-rankings --dry-run')->assertExitCode(0);
        $this->artisan('seo:domain-overview --budget=1')->assertExitCode(0);
        $this->artisan('seo:backlink-gap --budget=1')->assertExitCode(0);
        $this->artisan('seo:ai-mentions --budget=1')->assertExitCode(0);
        $this->artisan('seo:map-pack-grid --budget=1')->assertExitCode(0);
        Http::assertNothingSent();
    }
}
