<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * /services on a non-gsc tenant must serve that tenant's own themed view.
 *
 * The route used to test view()->exists('themes/{theme}/services'). Theme::apply()
 * prepends resources/themes/{theme} to the view finder, so a themed view resolves
 * under its PLAIN name — the path-style name matched nothing, the abort_unless
 * fired, and every non-gsc tenant 404'd. jpeterson 404'd on /services for as long
 * as the route existed, despite shipping services.blade.php in its theme.
 *
 * The guard the route is built around still has to hold: a tenant with no themed
 * services view must 404 rather than fall through to resources/views/services.blade.php,
 * which is GS Construction's own hardcoded page.
 */
class ThemedServicesRouteTest extends TestCase
{
    public function test_a_tenant_with_a_themed_services_view_gets_it(): void
    {
        $this->get('http://dev-jpeterson.ss.systems/services')->assertOk();
    }

    public function test_that_view_is_the_tenants_own_not_gs_constructions(): void
    {
        $html = $this->get('http://dev-jpeterson.ss.systems/services')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('J. Peterson Design', $html);
        $this->assertStringNotContainsString('GS Construction', $html);
    }

    public function test_a_tenant_without_one_404s_instead_of_borrowing_gscs_page(): void
    {
        // themes/ss has no services.blade.php. Falling through to the shared view
        // would publish GS Construction's six services under another brand.
        $this->get('http://ss.systems/services')->assertNotFound();
    }

    public function test_gs_construction_still_serves_its_own_services_page(): void
    {
        $this->get('http://gs.construction/services')->assertOk();
    }
}
