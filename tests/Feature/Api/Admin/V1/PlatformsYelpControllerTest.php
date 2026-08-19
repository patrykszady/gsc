<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\YelpAutoLogin;
use App\Models\PlatformSetting;
use App\Services\YelpBusinessService;
use App\Services\YelpRemoteLoginService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * /api/admin/v1/platforms/yelp/* — the FULL Yelp management surface ported
 * from the legacy Livewire component's action methods into this API for the
 * central admin's Platforms screen.
 *
 * HARD SAFETY: every side-effecting endpoint here is exercised against a
 * MOCKED YelpBusinessService / YelpRemoteLoginService bound into the
 * container, Queue::fake()'d dispatches, and a temp cookie-jar path — never
 * the real services, never a real Chromium/captcha/network round trip
 * against yelp.com. See tests/Feature/YelpCookieIngestTest.php for the same
 * temp-jar pattern this file reuses.
 */
class PlatformsYelpControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    protected function fakeJar(): string
    {
        $jar = sys_get_temp_dir().'/yelp-cookies-test-'.getmypid().'-'.uniqid().'.json';
        Config::set('services.yelp.business.cookies_file', $jar);

        return $jar;
    }

    // ---- credentials -----------------------------------------------------

    public function test_save_credentials_stores_email_and_password(): void
    {
        $this->postJson('/api/admin/v1/platforms/yelp/credentials', [
            'email' => 'owner@example.com',
            'password' => 'super-secret',
        ], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.configured', true);

        $this->assertSame('owner@example.com', PlatformSetting::get(YelpBusinessService::SETTING_EMAIL));
        $this->assertSame('super-secret', PlatformSetting::get(YelpBusinessService::SETTING_PASSWORD));
    }

    public function test_save_credentials_never_echoes_the_password_back(): void
    {
        $response = $this->postJson('/api/admin/v1/platforms/yelp/credentials', [
            'email' => 'owner@example.com',
            'password' => 'super-secret-value',
        ], $this->bearer())->assertOk();

        $response->assertDontSee('super-secret-value', false);
        $this->assertArrayNotHasKey('password', $response->json('data.yelp'));
    }

    public function test_save_credentials_blank_password_keeps_the_existing_one(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, 'already-saved');

        $this->postJson('/api/admin/v1/platforms/yelp/credentials', [
            'email' => 'owner@example.com',
            'password' => '',
        ], $this->bearer())->assertOk();

        $this->assertSame('already-saved', PlatformSetting::get(YelpBusinessService::SETTING_PASSWORD));
    }

    public function test_save_credentials_rejects_an_invalid_email(): void
    {
        $this->postJson('/api/admin/v1/platforms/yelp/credentials', [
            'email' => 'not-an-email',
        ], $this->bearer())->assertStatus(422);
    }

    public function test_save_credentials_requires_a_bearer_token(): void
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        $this->postJson('/api/admin/v1/platforms/yelp/credentials', ['email' => 'a@b.com'])
            ->assertUnauthorized();
    }

    public function test_clear_password_removes_the_stored_row(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, 'secret');

        $this->deleteJson('/api/admin/v1/platforms/yelp/credentials/password', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.yelp.has_password', false);

        $this->assertNull(PlatformSetting::get(YelpBusinessService::SETTING_PASSWORD));
    }

    // ---- session check ----------------------------------------------------

    /**
     * checkYelpSession() must call keepSessionAlive() — NOT the bare
     * checkSession() — for the same reason the legacy component's safety
     * test (YelpAdminPanelSafetyTest) pins: a bare negative check can only
     * make things worse from a button (markSessionDead() freezes uploads and
     * spends a captcha solve), while keepSessionAlive() repairs the jar when
     * authenticated. A plain (non-partial) Mockery mock enforces this: any
     * call to an unstubbed method fails the test.
     */
    public function test_check_session_calls_keep_session_alive_and_reports_true(): void
    {
        $service = Mockery::mock(YelpBusinessService::class);
        $service->shouldReceive('keepSessionAlive')->once()->andReturn(true);
        $this->app->instance(YelpBusinessService::class, $service);

        $this->postJson('/api/admin/v1/platforms/yelp/session/check', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.authenticated', true);
    }

    public function test_check_session_reports_null_when_the_probe_is_blocked(): void
    {
        $service = Mockery::mock(YelpBusinessService::class);
        $service->shouldReceive('keepSessionAlive')->once()->andReturn(null);
        $this->app->instance(YelpBusinessService::class, $service);

        $this->postJson('/api/admin/v1/platforms/yelp/session/check', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.authenticated', null);
    }

    // ---- cookie injection --------------------------------------------------

    public function test_import_cookies_merges_yelp_cookies_from_a_paste(): void
    {
        $jar = $this->fakeJar();

        $paste = json_encode([
            ['name' => 'bse', 'value' => 'abc', 'domain' => '.yelp.com', 'expirationDate' => time() + 86400],
        ]);

        $this->postJson('/api/admin/v1/platforms/yelp/cookies/import', [
            'paste' => $paste,
        ], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.has_session_cookie', true);

        $this->assertFileExists($jar);
        $stored = json_decode(file_get_contents($jar), true);
        $this->assertCount(1, $stored);

        @unlink($jar);
    }

    public function test_import_cookies_rejects_invalid_json(): void
    {
        $this->fakeJar();

        // 200 with ok=false, not 422 — a business outcome on a well-formed
        // request, not malformed input. See the controller's comment: a 422
        // here would make ss-systems' SiteApiConnection throw
        // SiteApiValidationException and lose this exact message.
        $this->postJson('/api/admin/v1/platforms/yelp/cookies/import', [
            'paste' => 'not json at all',
        ], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', 'That does not look like valid JSON.');
    }

    public function test_import_cookies_rejects_a_paste_with_no_yelp_domains(): void
    {
        $this->fakeJar();

        $paste = json_encode([
            ['name' => 'foo', 'value' => 'bar', 'domain' => '.example.com'],
        ]);

        $this->postJson('/api/admin/v1/platforms/yelp/cookies/import', [
            'paste' => $paste,
        ], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', 'No yelp.com cookies found in the pasted JSON.');
    }

    public function test_import_cookies_warns_when_no_session_cookie_is_present(): void
    {
        $jar = $this->fakeJar();

        $paste = json_encode([
            ['name' => 'analytics', 'value' => '1', 'domain' => '.yelp.com', 'expirationDate' => time() + 86400],
        ]);

        $this->postJson('/api/admin/v1/platforms/yelp/cookies/import', [
            'paste' => $paste,
        ], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.has_session_cookie', false)
            ->assertJsonFragment(['ok' => true]);

        @unlink($jar);
    }

    public function test_import_cookies_clears_the_session_dead_flag(): void
    {
        $jar = $this->fakeJar();
        Cache::put('yelp.session_dead', ['at' => now()->toIso8601String(), 'note' => 'x'], now()->addDay());

        $paste = json_encode([
            ['name' => 'bse', 'value' => 'abc', 'domain' => '.yelp.com', 'expirationDate' => time() + 86400],
        ]);

        $this->postJson('/api/admin/v1/platforms/yelp/cookies/import', ['paste' => $paste], $this->bearer())
            ->assertOk();

        $this->assertNull(Cache::get('yelp.session_dead'));

        @unlink($jar);
    }

    public function test_clear_cookies_deletes_the_jar_file(): void
    {
        $jar = $this->fakeJar();
        file_put_contents($jar, '[]');

        $this->deleteJson('/api/admin/v1/platforms/yelp/cookies', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->assertFileDoesNotExist($jar);
    }

    // ---- captcha / proxy (unattended sign-in) ------------------------------

    public function test_save_auto_login_settings_stores_captcha_key_and_proxy(): void
    {
        $this->postJson('/api/admin/v1/platforms/yelp/auto-login/settings', [
            'captcha_key' => 'a-2captcha-key',
            'proxy' => 'http://user:pass@host:8080',
        ], $this->bearer())->assertOk();

        $this->assertSame('a-2captcha-key', PlatformSetting::get('yelp_twocaptcha_key'));
        $this->assertSame('http://user:pass@host:8080', PlatformSetting::get('yelp_proxy'));
    }

    public function test_save_auto_login_settings_never_echoes_the_captcha_key(): void
    {
        $response = $this->postJson('/api/admin/v1/platforms/yelp/auto-login/settings', [
            'captcha_key' => 'super-secret-captcha-key',
        ], $this->bearer())->assertOk();

        $response->assertDontSee('super-secret-captcha-key', false);
    }

    public function test_run_auto_login_dispatches_the_job_without_executing_it(): void
    {
        Queue::fake();

        $this->postJson('/api/admin/v1/platforms/yelp/auto-login/run', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        Queue::assertPushed(YelpAutoLogin::class);
    }

    public function test_run_auto_login_respects_the_captcha_spend_floor(): void
    {
        Queue::fake();
        Cache::put('yelp.auto_login_attempted', true, now()->addMinutes(30));

        $this->postJson('/api/admin/v1/platforms/yelp/auto-login/run', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false);

        Queue::assertNothingPushed();
    }

    // ---- remote-login viewer ------------------------------------------------

    protected function mockRemote(): MockInterface
    {
        $remote = Mockery::mock(YelpRemoteLoginService::class);
        $this->app->instance(YelpRemoteLoginService::class, $remote);

        return $remote;
    }

    public function test_start_remote_login_requires_credentials_first(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldNotReceive('start');

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/start', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false);
    }

    /**
     * The response must carry a SIGNED viewer URL — never the raw noVNC URL
     * (which embeds a one-time VNC password). The raw URL/password are only
     * ever cached server-side.
     */
    public function test_start_remote_login_returns_a_signed_viewer_url_never_the_raw_novnc_url(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_EMAIL, 'owner@example.com');
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, 'secret');

        $remote = $this->mockRemote();
        $remote->shouldReceive('start')->once()->with(false)->andReturn([
            'ok' => true,
            'url' => 'http://127.0.0.1:6080/vnc.html?password=totally-secret-vnc-password',
            'password' => 'totally-secret-vnc-password',
            'started_at' => time(),
            'expires_at' => time() + 1500,
        ]);

        $response = $this->postJson('/api/admin/v1/platforms/yelp/remote-login/start', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $response->assertDontSee('totally-secret-vnc-password', false);
        $response->assertDontSee('127.0.0.1:6080', false);

        $viewerUrl = $response->json('data.viewer_url');
        $this->assertNotEmpty($viewerUrl);
        $this->assertStringContainsString('/platforms/yelp/viewer', $viewerUrl);
        $this->assertStringContainsString('signature=', $viewerUrl);

        // The raw URL is exactly what the (unauthenticated, signed)
        // viewer-redirect endpoint reads back out of cache.
        $this->assertSame(
            'http://127.0.0.1:6080/vnc.html?password=totally-secret-vnc-password',
            Cache::get('platforms.remote_login_url.yelp'),
        );
    }

    public function test_start_remote_login_passes_reset_profile_through(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_EMAIL, 'owner@example.com');
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, 'secret');

        $remote = $this->mockRemote();
        $remote->shouldReceive('start')->once()->with(true)->andReturn([
            'ok' => true, 'url' => 'http://x/vnc.html', 'started_at' => time(), 'expires_at' => time() + 900,
        ]);

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/start', [
            'reset_profile' => true,
        ], $this->bearer())->assertOk();
    }

    public function test_start_remote_login_surfaces_the_services_error(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_EMAIL, 'owner@example.com');
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, 'secret');

        $remote = $this->mockRemote();
        $remote->shouldReceive('start')->once()->andReturn([
            'ok' => false, 'error' => 'Missing host packages: Xvfb.',
        ]);

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/start', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error', 'Missing host packages: Xvfb.');
    }

    public function test_poll_remote_login_reports_running_without_touching_yelp_business_service(): void
    {
        // No YelpBusinessService mock bound at all — if the controller called
        // it while still running, container resolution would hit the REAL
        // service. Asserting a normal 200 here proves that never happens.
        $remote = $this->mockRemote();
        $remote->shouldReceive('status')->once()->andReturn(['ok' => true, 'running' => true]);
        $remote->shouldReceive('tailChromeLog')->once()->with(6000)->andReturn('navigating to biz.yelp.com...');

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/poll', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.finished', false)
            ->assertJsonPath('data.log_tail', 'navigating to biz.yelp.com...');
    }

    public function test_poll_remote_login_prefers_the_scripts_outcome_and_requeues_photos(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('status')->once()->andReturn(['ok' => true, 'running' => false]);
        $remote->shouldReceive('tailChromeLog')->once()->andReturn('login ok');
        $remote->shouldReceive('readLoginOutcome')->once()->andReturn(['ok' => true, 'authenticated' => true]);

        $biz = Mockery::mock(YelpBusinessService::class);
        $biz->shouldReceive('markSessionFresh')->once()->andReturn(3);
        $biz->shouldNotReceive('checkSession');
        $this->app->instance(YelpBusinessService::class, $biz);

        Cache::put('platforms.remote_login_url.yelp', 'http://x', now()->addMinutes(5));

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/poll', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.requeued', 3);

        $this->assertNull(Cache::get('platforms.remote_login_url.yelp'));
    }

    public function test_poll_remote_login_falls_back_to_check_session_when_outcome_is_missing(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('status')->once()->andReturn(['ok' => true, 'running' => false]);
        $remote->shouldReceive('tailChromeLog')->once()->andReturn('closed');
        $remote->shouldReceive('readLoginOutcome')->once()->andReturn(null);

        $biz = Mockery::mock(YelpBusinessService::class);
        $biz->shouldNotReceive('markSessionFresh');
        $biz->shouldReceive('checkSession')->once()->andReturn(false);
        $this->app->instance(YelpBusinessService::class, $biz);

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/poll', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.authenticated', false);
    }

    public function test_stop_remote_login_stops_the_service_and_forgets_the_cached_url(): void
    {
        Cache::put('platforms.remote_login_url.yelp', 'http://x', now()->addMinutes(5));

        $remote = $this->mockRemote();
        $remote->shouldReceive('stop')->once()->andReturn(['ok' => true]);

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/stop', [], $this->bearer())
            ->assertOk()
            ->assertJsonStructure(['data' => ['yelp']]);

        $this->assertNull(Cache::get('platforms.remote_login_url.yelp'));
    }

    public function test_reset_profile_stops_then_restarts_with_reset_profile_true(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_EMAIL, 'owner@example.com');
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, 'secret');
        Cache::put('yelp.session_dead', ['at' => now()->toIso8601String(), 'note' => 'x'], now()->addDay());

        $remote = $this->mockRemote();
        $remote->shouldReceive('stop')->once()->andReturn(['ok' => true]);
        $remote->shouldReceive('start')->once()->with(true)->andReturn([
            'ok' => true, 'url' => 'http://x/vnc.html', 'started_at' => time(), 'expires_at' => time() + 900,
        ]);

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/reset', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->assertNull(Cache::get('yelp.session_dead'));
    }

    public function test_reset_profile_still_stops_but_refuses_to_start_without_credentials(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('stop')->once()->andReturn(['ok' => true]);
        $remote->shouldNotReceive('start');

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/reset', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false);
    }

    public function test_report_remote_error_stops_the_session_and_forgets_the_cached_url(): void
    {
        Cache::put('platforms.remote_login_url.yelp', 'http://x', now()->addMinutes(5));

        $remote = $this->mockRemote();
        $remote->shouldReceive('stop')->once()->andReturn(['ok' => true]);

        $this->postJson('/api/admin/v1/platforms/yelp/remote-login/report-error', [
            'reason' => 'iframe failed to load',
        ], $this->bearer())->assertOk()->assertJsonPath('data.ok', true);

        $this->assertNull(Cache::get('platforms.remote_login_url.yelp'));
    }
}
