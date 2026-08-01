<?php

namespace Tests\Feature;

use App\Models\Site;
use Tests\TestCase;

/**
 * Pins the production-safety boundary of the local preview mechanism.
 *
 * phpunit.xml sets APP_ENV=testing, which is NOT local — so every assertion
 * here runs in exactly production's posture. That is the point: these tests
 * fail if the dev-only affordances ever become reachable on a real host.
 */
class DevHostPreviewTest extends TestCase
{
    public function test_dev_hosts_resolve_nothing_outside_local(): void
    {
        foreach (['jpeterson.localhost', 'gsc.localhost', 'ss.localhost', 'gsc.test', 'jpeterson.localhost:8003'] as $host) {
            $this->assertNull(
                Site::forDevHost($host),
                "{$host} must not resolve a tenant outside the local environment.",
            );
        }
    }

    public function test_inactive_site_is_still_unreachable_by_its_real_host(): void
    {
        // The dev-host branch must not have leaked an is_active bypass into
        // the production host path. TenantRouteGuardTest depends on this too:
        // jpeterson-design.com falls back to the default site until launch.
        $this->assertNull(Site::forHost('jpeterson-design.com'));
    }

    public function test_the_sites_register_is_not_routable_outside_local(): void
    {
        $this->get('http://gs.construction/_sites')->assertNotFound();
        $this->get('http://gs.construction/_sites/bar')->assertNotFound();
    }

    public function test_no_dev_bar_or_headers_on_a_production_host(): void
    {
        $response = $this->get('http://gs.construction/');

        $response->assertOk();
        $response->assertHeaderMissing('X-Dev-Site');
        $this->assertStringNotContainsString('dev-site-bar', $response->getContent());
    }

    public function test_query_override_cannot_select_a_tenant_outside_local(): void
    {
        $this->get('http://gs.construction/?site=jpeterson')->assertOk();

        $this->assertSame('gsc', Site::current()->slug);
    }

    public function test_session_pin_cannot_select_a_tenant_outside_local(): void
    {
        // A pin written on a developer's machine must be inert on a real host —
        // otherwise a stale session could serve the wrong business's site.
        $this->withSession(['preview.site' => 'jpeterson'])
            ->get('http://gs.construction/')
            ->assertOk();

        $this->assertSame('gsc', Site::current()->slug);
    }

    public function test_every_site_has_a_distinct_dev_host(): void
    {
        $hosts = Site::listAll()->map(fn (Site $site): string => $site->devHost());

        $this->assertSame(
            $hosts->count(),
            $hosts->unique()->count(),
            'Two sites share a dev host — slugs must be unique.',
        );
    }
}
