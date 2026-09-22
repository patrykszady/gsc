<?php

namespace Tests\Feature;

use App\Models\Site;
use Tests\TestCase;

/**
 * The central admin (ss.systems) reads this site's logs server-to-server via
 * `LOG_VIEWER_HUB_TOKEN`, checked in AppServiceProvider's LogViewer::auth
 * callback alongside (never instead of) the existing production_token and
 * allowed-admin-email branches.
 *
 * The auth callback short-circuits to true for local/testing, so every test
 * here forces the app to believe it is production (same trick as
 * PreviewHostTest) to actually exercise the token branches.
 */
class LogViewerHubAccessTest extends TestCase
{
    private function actAsProduction(): void
    {
        // The log-viewer API routes are registered outside the `web`
        // middleware group (see LogViewerServiceProvider), so ResolveSite
        // never runs on them and Site::current() is never set on the normal
        // request path. A rejected request here falls into bootstrap/app.php's
        // exception-render fallback, which resolves the tenant itself — the
        // first `sites` query a forbidden-request test would otherwise make.
        // Touch it here, BEFORE forcing production: LazilyRefreshDatabase
        // migrates via `migrate:fresh`, which Laravel's ConfirmableTrait
        // silently refuses to run once the app believes it is production
        // (no --force passed), so migrating has to happen while the app is
        // still `testing`.
        Site::query()->count();

        $this->app->detectEnvironment(fn () => 'production');

        config([
            'log-viewer.hub_token' => 'hub-secret',
            'log-viewer.production_token' => 'prod-secret',
            'log-viewer.require_auth_in_production' => true,
        ]);
    }

    public function test_the_hub_token_is_accepted(): void
    {
        $this->actAsProduction();

        $this->withHeader('Authorization', 'Bearer hub-secret')
            ->get('http://gs.construction/log-viewer/api/folders')
            ->assertOk();
    }

    public function test_a_wrong_bearer_token_is_rejected(): void
    {
        $this->actAsProduction();

        $this->withHeader('Authorization', 'Bearer not-the-right-token')
            ->get('http://gs.construction/log-viewer/api/folders')
            ->assertForbidden();
    }

    public function test_no_bearer_token_is_rejected(): void
    {
        $this->actAsProduction();

        $this->get('http://gs.construction/log-viewer/api/folders')
            ->assertForbidden();
    }

    public function test_the_existing_production_token_still_works(): void
    {
        $this->actAsProduction();

        $this->withHeader('Authorization', 'Bearer prod-secret')
            ->get('http://gs.construction/log-viewer/api/folders')
            ->assertOk();
    }
}
