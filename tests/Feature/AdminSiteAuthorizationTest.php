<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

/**
 * A client login must reach its own tenant's admin and nothing else.
 *
 * Before users had a site_id, the only guard on /admin-legacy/{site}/… was `auth`:
 * any authenticated user could edit the URL segment and read another tenant's
 * projects, leads and contact submissions. These tests pin that shut.
 */
class AdminSiteAuthorizationTest extends TestCase
{
    private function scopedUser(string $slug): User
    {
        $site = Site::query()->where('slug', $slug)->firstOrFail();

        return User::query()->updateOrCreate(
            ['email' => "scoped-{$slug}@example.test"],
            ['name' => 'Scoped Test', 'password' => 'secret-Password-1', 'site_id' => $site->id],
        );
    }

    private function platformUser(): User
    {
        return User::query()->updateOrCreate(
            ['email' => 'platform@example.test'],
            ['name' => 'Platform Test', 'password' => 'secret-Password-1', 'site_id' => null],
        );
    }

    public function test_scoped_user_is_forbidden_from_another_tenants_admin(): void
    {
        $this->actingAs($this->scopedUser('jpeterson'))
            ->get('http://ss.systems/admin-legacy/gs.construction/projects')
            ->assertForbidden();
    }

    public function test_scoped_user_can_reach_their_own_admin(): void
    {
        $this->actingAs($this->scopedUser('jpeterson'))
            ->get('http://ss.systems/admin-legacy/jpeterson-design.com')
            ->assertSuccessful();
    }

    public function test_scoped_user_skips_the_picker_and_lands_in_their_own_admin(): void
    {
        $this->actingAs($this->scopedUser('jpeterson'))
            ->get('http://ss.systems/admin-legacy')
            ->assertRedirect('/admin-legacy/jpeterson-design.com');
    }

    public function test_platform_admin_still_sees_every_site(): void
    {
        // The picker moved to /admin-legacy/sites. /admin now honours the host you
        // arrived on, because being asked "which site?" on a URL that already
        // names one is a question with a single sensible answer. A platform
        // admin can still reach every tenant — via the switcher in the admin
        // chrome, which links here.
        $this->actingAs($this->platformUser())
            ->get('http://ss.systems/admin-legacy/sites')
            ->assertSuccessful()
            ->assertSee('GS Construction')
            ->assertSee('J. Peterson Design');
    }

    public function test_admin_root_honours_the_host_for_a_platform_admin(): void
    {
        // jpeterson via its preview host. This used to run against
        // ss.systems, which was both a tenant and the admin hub host; that
        // site left the platform, and its `sites` row is deactivated, so the
        // host now falls back to the default tenant and proves nothing.
        // forPreviewHost resolves inactive sites, so jpeterson still works
        // as a non-default tenant to land in.
        $this->actingAs($this->platformUser())
            ->get('http://dev-jpeterson.ss.systems/admin-legacy')
            ->assertRedirect('http://dev-jpeterson.ss.systems/admin-legacy/jpeterson-design.com');
    }

    public function test_platform_admin_may_administer_any_tenant(): void
    {
        $platform = $this->platformUser();

        $this->actingAs($platform)->get('http://ss.systems/admin-legacy/gs.construction')->assertSuccessful();
        $this->actingAs($platform)->get('http://ss.systems/admin-legacy/jpeterson-design.com')->assertSuccessful();
    }

    public function test_the_picker_never_lists_another_tenant(): void
    {
        $this->actingAs($this->scopedUser('jpeterson'))
            ->get('http://ss.systems/admin-legacy/jpeterson-design.com')
            ->assertSuccessful()
            ->assertDontSee('gs.construction');
    }

    public function test_model_helpers(): void
    {
        $scoped = $this->scopedUser('jpeterson');
        $platform = $this->platformUser();
        $gsc = Site::query()->where('slug', 'gsc')->firstOrFail();
        $jp = Site::query()->where('slug', 'jpeterson')->firstOrFail();

        $this->assertFalse($scoped->isPlatformAdmin());
        $this->assertTrue($platform->isPlatformAdmin());

        $this->assertTrue($scoped->canAccessSite($jp));
        $this->assertFalse($scoped->canAccessSite($gsc));
        $this->assertTrue($platform->canAccessSite($gsc));

        $this->assertCount(1, $scoped->accessibleSites());
        $this->assertGreaterThan(1, $platform->accessibleSites()->count());
    }
}
