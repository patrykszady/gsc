<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

/**
 * Where /admin sends you.
 *
 * The host already names the tenant, so being asked "which site?" on
 * gs.construction/admin is a question with one sensible answer. The picker
 * still exists at /admin/sites, because there is no switcher in the admin
 * chrome and an operator with several sites would otherwise be stranded on
 * whichever one they happened to arrive on.
 */
class AdminLandingTest extends TestCase
{
    /**
     * An operator who administers every tenant.
     *
     * site_id === null IS the platform-admin flag (User::isPlatformAdmin),
     * so this is built rather than borrowed from the fixtures — skipping when
     * the seed happens to lack one would quietly prove nothing.
     */
    private function operator(): User
    {
        return User::factory()->create(['site_id' => null]);
    }

    public function test_admin_goes_straight_to_the_tenant_you_arrived_on(): void
    {
        $user = $this->operator();

        $this->actingAs($user)
            ->get('http://gs.construction/admin')
            ->assertRedirect('http://gs.construction/admin/gs.construction');
    }

    public function test_each_host_lands_on_its_own_tenant(): void
    {
        $user = $this->operator();

        foreach (Site::all() as $site) {
            if (! $user->accessibleSites()->contains('id', $site->id)) {
                continue;
            }

            // Site::forHost() searches ACTIVE sites only, so an unlaunched
            // tenant's own domain does not resolve to it — by design, and the
            // reason preview hosts exist. Such a host falls through to the
            // default site, which the next test covers.
            if (! $site->is_active) {
                continue;
            }

            $host = $site->primary_host;

            $this->actingAs($user)
                ->get("http://{$host}/admin")
                ->assertRedirect("http://{$host}/admin/{$host}");
        }
    }

    public function test_a_host_that_names_no_tenant_uses_the_default_site(): void
    {
        // A bare IP or localhost resolves to the default site, which is the
        // right guess for the same reason the host rule is.
        $user = $this->operator();
        $default = Site::default();

        $this->actingAs($user)
            ->get('http://127.0.0.1/admin')
            ->assertRedirect("http://127.0.0.1/admin/{$default->primary_host}");
    }

    public function test_the_picker_is_still_reachable(): void
    {
        $this->actingAs($this->operator())
            ->get('http://gs.construction/admin/sites')
            ->assertOk()
            ->assertSee('gs.construction', false);
    }

    public function test_admin_requires_a_login(): void
    {
        $this->get('http://gs.construction/admin')->assertRedirect('/admin/login');
        $this->get('http://gs.construction/admin/sites')->assertRedirect('/admin/login');
    }
}
