<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Exercises the real resolution path — the HTTP host — because the ?site=
 * preview override is deliberately local-env only and is ignored in testing.
 */
class TenantRouteGuardTest extends TestCase
{
    public function test_gsc_only_paths_404_on_other_tenants(): void
    {
        // jpeterson via its preview host: ss.systems used to be the second
        // tenant here, but it runs as its own application now. forPreviewHost
        // resolves inactive sites, which is what makes jpeterson usable as
        // the "other tenant" while it is still pre-launch.
        foreach (['compare', 'projects', 'reviews', 'trades', 'permits', 'costs', 'warranty'] as $path) {
            $this->get("http://dev-jpeterson.ss.systems/{$path}")->assertNotFound();
        }
    }

    public function test_universal_paths_serve_on_other_tenants(): void
    {
        foreach (['', 'contact'] as $path) {
            $this->get("http://ss.systems/{$path}")->assertOk();
        }
    }

    public function test_gs_construction_is_unaffected(): void
    {
        foreach (['', 'projects', 'reviews', 'compare', 'trades', 'warranty', 'contact'] as $path) {
            $this->get("http://gs.construction/{$path}")->assertOk();
        }
    }

    public function test_inactive_tenant_host_falls_back_and_still_serves_gsc(): void
    {
        // jpeterson is is_active=false, so its host owns no tenant and resolves
        // to the default site — which must NOT 404 on GS paths.
        $this->get('http://jpeterson-design.com/projects')->assertOk();
    }
}
