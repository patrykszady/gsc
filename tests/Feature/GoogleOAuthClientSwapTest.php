<?php

namespace Tests\Feature;

use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Models\Site;
use App\Support\GoogleOAuthApp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Backport of jpeterson-design's GoogleOAuthClientSwapTest (2026-09-27) —
 * gsc never had this fix: swapping the stored Google OAuth client used to
 * leave the old client's oauth_tokens rows in place, so the Platforms
 * screen kept reporting Business Profile/Search Console as "Connected"
 * while every call failed with invalid_client.
 *
 * gsc is multi-tenant, so this file adds one case jpeterson's single-tenant
 * version has no need for: rotating ONE site's client must never touch
 * another site's grants. OAuthToken's BelongsToSite trait (a global scope
 * filtering every query — including forgetGrantsFromThePreviousClient()'s
 * delete() — to Site::current()) is what gives that isolation for free;
 * this test is what pins it.
 */
class GoogleOAuthClientSwapTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function storeGrants(): void
    {
        foreach (['google_search_console', 'google_business_profile'] as $provider) {
            OAuthToken::create([
                'provider' => $provider,
                'refresh_token' => 'issued-by-the-old-client',
                'scopes' => ['https://www.googleapis.com/auth/webmasters'],
            ]);
        }
    }

    private function defaultSite(): Site
    {
        return Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
    }

    public function test_changing_the_client_drops_the_grants_it_invalidated(): void
    {
        $gsc = $this->defaultSite();
        Site::setCurrent($gsc);

        GoogleOAuthApp::save('old-client.apps.googleusercontent.com', 'GOCSPX-old');
        $this->storeGrants();

        GoogleOAuthApp::save('new-client.apps.googleusercontent.com', 'GOCSPX-new', '31627704418');

        $this->assertSame(0, OAuthToken::forSite($gsc)->count(), 'grants from the previous client must not survive');
        $this->assertSame('new-client.apps.googleusercontent.com', PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_ID));
        $this->assertSame('31627704418', PlatformSetting::get(GoogleOAuthApp::SETTING_PROJECT_ID));
    }

    public function test_resaving_the_same_client_keeps_the_grants(): void
    {
        $gsc = $this->defaultSite();
        Site::setCurrent($gsc);

        // Re-uploading the same JSON — say to correct the project id, or just
        // by accident — must not sign the site out of Google.
        GoogleOAuthApp::save('same-client.apps.googleusercontent.com', 'GOCSPX-one');
        $this->storeGrants();

        GoogleOAuthApp::save('same-client.apps.googleusercontent.com', 'GOCSPX-two', '31627704418');

        $this->assertSame(2, OAuthToken::forSite($gsc)->count());
    }

    public function test_the_first_ever_save_has_nothing_to_drop(): void
    {
        $gsc = $this->defaultSite();
        Site::setCurrent($gsc);

        $this->storeGrants();

        GoogleOAuthApp::save('first-client.apps.googleusercontent.com', 'GOCSPX-first');

        $this->assertSame(2, OAuthToken::forSite($gsc)->count());
    }

    public function test_rotating_one_sites_client_never_touches_another_sites_grants(): void
    {
        $gsc = $this->defaultSite();
        $other = Site::where('slug', '!=', $gsc->slug)->firstOrFail();

        Site::setCurrent($other);
        GoogleOAuthApp::save('other-site-old-client.apps.googleusercontent.com', 'GOCSPX-other-old');
        $this->storeGrants(); // the other tenant's own grants, under ITS old client

        Site::setCurrent($gsc);
        GoogleOAuthApp::save('gsc-old-client.apps.googleusercontent.com', 'GOCSPX-gsc-old');
        $this->storeGrants(); // gsc's own grants, under gsc's old client

        // Rotate gsc's client only — the other tenant's client is untouched.
        GoogleOAuthApp::save('gsc-new-client.apps.googleusercontent.com', 'GOCSPX-gsc-new');

        $this->assertSame(0, OAuthToken::forSite($gsc)->count(), "gsc's own dead grants must be gone");
        $this->assertSame(2, OAuthToken::forSite($other)->count(), "the other tenant's grants must be untouched");
        $this->assertSame(
            'other-site-old-client.apps.googleusercontent.com',
            (function () use ($other) {
                Site::setCurrent($other);

                return PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_ID);
            })(),
            "the other tenant's own stored client must be untouched"
        );
    }
}
