<?php

namespace Tests\Feature;

use App\Models\Site;
use Tests\TestCase;

/**
 * Preview hostnames for tenants that have not launched.
 *
 * Site::forHost() searches ACTIVE sites only, which is right for production —
 * an unlaunched tenant must not answer on a live domain. The side effect is
 * that a preview URL for a site still in build matches nothing and falls
 * through to the default site, so the client opens their link and is shown
 * gs.construction. That is exactly what happened with
 * dev-jpeterson.ss.systems.
 */
class PreviewHostTest extends TestCase
{
    public function test_a_preview_host_reaches_its_own_inactive_tenant(): void
    {
        $site = Site::forPreviewHost('dev-jpeterson.ss.systems');

        $this->assertNotNull($site, 'preview host must resolve');
        $this->assertSame('jpeterson', $site->slug);
        $this->assertFalse((bool) $site->is_active, 'the point of a preview host is that the site is NOT live yet');
    }

    public function test_the_preview_host_serves_the_tenant_theme_not_the_default_site(): void
    {
        // A full URL, not withServerVariables(): the latter does not change
        // the host Laravel resolves the tenant from, so the request still
        // arrives as the default site and the assertion proves nothing.
        $response = $this->get('http://dev-jpeterson.ss.systems/');

        $response->assertOk();
        $response->assertDontSee('GS Construction', false);
        $this->assertSame('jpeterson', Site::current()->slug);
    }

    public function test_a_preview_host_is_never_indexable_even_in_production(): void
    {
        // An unlaunched client site in Google is worse than a broken preview:
        // it competes with the real site and cannot easily be withdrawn.
        //
        // Forcing production is the whole point. Under APP_ENV=testing the
        // noindex header is emitted for every request anyway, so this test
        // would pass even if preview-host detection were completely broken.
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get('http://dev-jpeterson.ss.systems/');

        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_an_unknown_host_still_falls_back_to_the_default_site(): void
    {
        // Preview hosts are opt-in data; adding one must not make arbitrary
        // hostnames resolve to a non-default tenant.
        $this->assertNull(Site::forPreviewHost('some-random-host.example.com'));
    }

    public function test_live_tenants_are_unaffected(): void
    {
        $this->assertSame('gsc', Site::forHost('gs.construction')?->slug);
        $this->assertSame('ss', Site::forHost('ss.systems')?->slug);
    }
}
