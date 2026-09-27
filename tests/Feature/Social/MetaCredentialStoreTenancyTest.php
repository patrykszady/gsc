<?php

namespace Tests\Feature\Social;

use App\Models\OAuthToken;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use SsSystems\Platform\Social\Adapters\OAuthTokenCredentialStore;
use Tests\TestCase;

/**
 * gsc is multi-tenant — written BEFORE MetaSocialService itself was ported
 * onto the kit's MetaGraphClient/OAuthTokenCredentialStore (see
 * ss-platform-kit's CONSOLIDATION-PLAN.md, Kit 0.13.0 unit 9), per the
 * fan-out rules' tenancy guard. `OAuthTokenCredentialStore` never
 * references `App\Models\OAuthToken` directly (a kit class never
 * references `App\Models\*`) — it is handed the model's own FQCN as a
 * class-string and calls only that class's static methods
 * (`::forProvider()`/`::updateOrCreate()`/`::where()`), which is exactly
 * where `OAuthToken`'s `use BelongsToSite;` global scope lives. This test
 * proves that scope survives being called through the adapter: one
 * tenant's Meta grant must never be readable, overwritable, or
 * disconnectable from another tenant's context.
 */
class MetaCredentialStoreTenancyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(): OAuthTokenCredentialStore
    {
        return new OAuthTokenCredentialStore(OAuthToken::class, 'meta');
    }

    private function otherSite(Site $gsc): Site
    {
        return Site::where('slug', '!=', $gsc->slug)->firstOrFail();
    }

    public function test_a_tenants_stored_grant_is_invisible_from_another_tenants_context(): void
    {
        $gsc = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $other = $this->otherSite($gsc);

        Site::setCurrent($gsc);
        $this->store()->store([
            'access_token' => 'gsc-page-token', 'refresh_token' => 'gsc-user-token',
            'page_id' => 'gsc-page', 'page_name' => 'GSC Page', 'ig_id' => 'gsc-ig', 'ig_username' => 'gsc_ig',
            'granted_by_email' => 'gsc-owner@example.test', 'scopes' => ['pages_show_list'],
        ]);

        Site::setCurrent($other);
        $otherCreds = $this->store()->get();

        $this->assertNull($otherCreds['token'], "another tenant's context must not see gsc's grant");
        $this->assertNull($this->store()->grantedByEmail());
        $this->assertNull($this->store()->grantedAt());

        // The row is really there — just scoped away from the other tenant.
        $this->assertSame(1, OAuthToken::forSite($gsc)->where('provider', 'meta')->count());
        $this->assertSame(0, OAuthToken::forSite($other)->where('provider', 'meta')->count());
    }

    public function test_each_tenant_can_hold_its_own_independent_grant_at_once(): void
    {
        $gsc = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $other = $this->otherSite($gsc);

        Site::setCurrent($gsc);
        $this->store()->store([
            'access_token' => 'gsc-token', 'refresh_token' => 'gsc-refresh', 'page_id' => 'gsc-page',
            'page_name' => null, 'ig_id' => null, 'ig_username' => null, 'granted_by_email' => null, 'scopes' => [],
        ]);

        Site::setCurrent($other);
        $this->store()->store([
            'access_token' => 'other-token', 'refresh_token' => 'other-refresh', 'page_id' => 'other-page',
            'page_name' => null, 'ig_id' => null, 'ig_username' => null, 'granted_by_email' => null, 'scopes' => [],
        ]);

        Site::setCurrent($gsc);
        $this->assertSame('gsc-token', $this->store()->get()['token']);

        Site::setCurrent($other);
        $this->assertSame('other-token', $this->store()->get()['token']);
    }

    public function test_disconnecting_one_tenants_grant_never_clears_the_other_tenants(): void
    {
        $gsc = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $other = $this->otherSite($gsc);

        Site::setCurrent($gsc);
        $this->store()->store(['access_token' => 'gsc-token', 'refresh_token' => 'gsc-refresh', 'page_id' => null, 'page_name' => null, 'ig_id' => null, 'ig_username' => null, 'granted_by_email' => null, 'scopes' => []]);

        Site::setCurrent($other);
        $this->store()->store(['access_token' => 'other-token', 'refresh_token' => 'other-refresh', 'page_id' => null, 'page_name' => null, 'ig_id' => null, 'ig_username' => null, 'granted_by_email' => null, 'scopes' => []]);

        // Disconnect gsc's own grant only.
        Site::setCurrent($gsc);
        $this->store()->clear();

        $this->assertNull($this->store()->get()['token']);

        Site::setCurrent($other);
        $this->assertSame('other-token', $this->store()->get()['token'], "the other tenant's grant must survive gsc's disconnect");
    }
}
