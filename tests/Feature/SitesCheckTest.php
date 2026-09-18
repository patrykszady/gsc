<?php

namespace Tests\Feature;

use App\Models\Site;
use Tests\TestCase;

/**
 * `sites:check` is meant to gate a deploy, which it cannot do while a tenant
 * that left the platform reports a dozen failures on every run: no theme, no
 * brand overlay, and a nav whose every path another tenant now claims.
 */
class SitesCheckTest extends TestCase
{
    public function test_a_departed_tenant_is_skipped_rather_than_failed(): void
    {
        $this->artisan('sites:check --site=ss')
            ->expectsOutputToContain('left this platform')
            ->assertSuccessful();
    }

    public function test_a_departed_tenant_that_is_still_active_is_a_failure(): void
    {
        $site = Site::query()->where('slug', 'ss')->firstOrFail();
        $site->forceFill(['is_active' => true])->save();
        Site::forgetActive();
        Site::forgetListAll();

        $this->artisan('sites:check --site=ss')
            ->expectsOutputToContain('is_active = true')
            ->assertFailed();
    }

    public function test_a_live_tenant_is_still_checked(): void
    {
        $this->artisan('sites:check --site=gsc')
            ->expectsOutputToContain('gs.construction')
            ->assertSuccessful();
    }
}
