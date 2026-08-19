<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Services\InstagramRemoteLoginService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * /api/admin/v1/platforms/instagram/* — the Instagram Puppeteer session
 * (used for location-tagging) ported from the legacy Livewire component.
 *
 * HARD SAFETY: InstagramRemoteLoginService is fully mocked for every test —
 * never a real Xvfb/Chromium/websockify spawn. verifyInstagramSession()
 * also shells out directly (mirroring the legacy component) to scrape a
 * username from scripts/instagram-check-session.mjs; node_binary is pinned
 * to the harmless `true` coreutil here so that call, even though it isn't
 * routed through the mocked service, can never launch a real browser.
 */
class PlatformsInstagramControllerTest extends TestCase
{
    protected string $profileDir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.instagram.node_binary', 'true');
        $this->profileDir = sys_get_temp_dir().'/ig-profile-test-'.getmypid().'-'.uniqid();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        if (is_file($this->profileDir.'/Default/Cookies')) {
            @unlink($this->profileDir.'/Default/Cookies');
        }
        @rmdir($this->profileDir.'/Default');
        @rmdir($this->profileDir);
        parent::tearDown();
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    protected function mockRemote(): MockInterface
    {
        $remote = Mockery::mock(InstagramRemoteLoginService::class);
        $this->app->instance(InstagramRemoteLoginService::class, $remote);

        return $remote;
    }

    protected function createFakeProfile(int $cookieBytes = 2048): void
    {
        @mkdir($this->profileDir.'/Default', 0755, true);
        file_put_contents($this->profileDir.'/Default/Cookies', str_repeat('x', $cookieBytes));
    }

    // ---- session verify -----------------------------------------------------

    public function test_verify_session_reports_no_profile_without_calling_check_session(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('userDataDir')->andReturn($this->profileDir); // no profile created
        $remote->shouldNotReceive('checkSession');

        $this->postJson('/api/admin/v1/platforms/instagram/session/verify', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.authenticated', false);
    }

    public function test_verify_session_reports_authenticated_true(): void
    {
        $this->createFakeProfile();

        $remote = $this->mockRemote();
        $remote->shouldReceive('userDataDir')->andReturn($this->profileDir);
        $remote->shouldReceive('checkSession')->once()->andReturn(true);

        $response = $this->postJson('/api/admin/v1/platforms/instagram/session/verify', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.authenticated', true);

        $cached = Cache::get('instagram.last_session_check');
        $this->assertTrue($cached['authed']);
    }

    public function test_verify_session_reports_null_when_the_probe_is_indeterminate(): void
    {
        $this->createFakeProfile();

        $remote = $this->mockRemote();
        $remote->shouldReceive('userDataDir')->andReturn($this->profileDir);
        $remote->shouldReceive('checkSession')->once()->andReturn(null);

        $this->postJson('/api/admin/v1/platforms/instagram/session/verify', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.authenticated', null);
    }

    // ---- remote-login viewer -------------------------------------------------

    public function test_start_remote_login_skips_relaunch_when_already_authenticated(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('checkSession')->once()->with(20)->andReturn(true);
        $remote->shouldNotReceive('start');

        $this->postJson('/api/admin/v1/platforms/instagram/remote-login/start', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.already_authenticated', true);
    }

    public function test_start_remote_login_returns_a_signed_viewer_url_never_the_raw_novnc_url(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('checkSession')->once()->with(20)->andReturn(false);
        $remote->shouldReceive('start')->once()->with(false)->andReturn([
            'ok' => true,
            'url' => 'http://127.0.0.1:6081/vnc.html?password=ig-secret-vnc-password',
            'password' => 'ig-secret-vnc-password',
            'started_at' => time(),
            'expires_at' => time() + 1500,
        ]);

        $response = $this->postJson('/api/admin/v1/platforms/instagram/remote-login/start', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $response->assertDontSee('ig-secret-vnc-password', false);
        $response->assertDontSee('127.0.0.1:6081', false);

        $viewerUrl = $response->json('data.viewer_url');
        $this->assertStringContainsString('/platforms/instagram/viewer', $viewerUrl);
        $this->assertStringContainsString('signature=', $viewerUrl);

        $this->assertSame(
            'http://127.0.0.1:6081/vnc.html?password=ig-secret-vnc-password',
            Cache::get('platforms.remote_login_url.instagram'),
        );
    }

    public function test_start_remote_login_with_reset_profile_skips_the_session_check(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldNotReceive('checkSession');
        $remote->shouldReceive('start')->once()->with(true)->andReturn([
            'ok' => true, 'url' => 'http://x/vnc.html', 'started_at' => time(), 'expires_at' => time() + 900,
        ]);

        $this->postJson('/api/admin/v1/platforms/instagram/remote-login/start', [
            'reset_profile' => true,
        ], $this->bearer())->assertOk();
    }

    public function test_poll_remote_login_reports_running(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('status')->once()->andReturn(['ok' => true, 'running' => true]);
        $remote->shouldReceive('tailChromeLog')->once()->andReturn('opening instagram.com...');

        $this->postJson('/api/admin/v1/platforms/instagram/remote-login/poll', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.finished', false);
    }

    public function test_poll_remote_login_prefers_the_scripts_outcome(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('status')->once()->andReturn(['ok' => true, 'running' => false]);
        $remote->shouldReceive('tailChromeLog')->once()->andReturn('done');
        $remote->shouldReceive('readLoginOutcome')->once()->andReturn(['ok' => true, 'authenticated' => true, 'username' => 'jpetersondesign']);
        $remote->shouldNotReceive('checkSession');

        $this->postJson('/api/admin/v1/platforms/instagram/remote-login/poll', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.username', 'jpetersondesign');

        $cached = Cache::get('instagram.last_session_check');
        $this->assertSame('jpetersondesign', $cached['username']);
    }

    public function test_poll_remote_login_falls_back_to_check_session_when_outcome_is_missing(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('status')->once()->andReturn(['ok' => true, 'running' => false]);
        $remote->shouldReceive('tailChromeLog')->once()->andReturn('closed early');
        $remote->shouldReceive('readLoginOutcome')->once()->andReturn(null);
        $remote->shouldReceive('checkSession')->once()->with()->andReturn(false);

        $this->postJson('/api/admin/v1/platforms/instagram/remote-login/poll', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.authenticated', false);
    }

    public function test_stop_remote_login_stops_the_service_and_forgets_the_cached_url(): void
    {
        Cache::put('platforms.remote_login_url.instagram', 'http://x', now()->addMinutes(5));

        $remote = $this->mockRemote();
        $remote->shouldReceive('stop')->once()->andReturn(['ok' => true]);
        $remote->shouldReceive('userDataDir')->andReturn($this->profileDir);
        // stopInstagramRemoteLogin() returns the full instagramStatus() —
        // same call the standalone GET status endpoint makes.
        $remote->shouldReceive('isEnabled')->once()->andReturn(true);

        $this->postJson('/api/admin/v1/platforms/instagram/remote-login/stop', [], $this->bearer())
            ->assertOk()
            ->assertJsonStructure(['data' => ['instagram']]);

        $this->assertNull(Cache::get('platforms.remote_login_url.instagram'));
    }

    public function test_reset_profile_stops_then_restarts_with_reset_profile_true(): void
    {
        $remote = $this->mockRemote();
        $remote->shouldReceive('stop')->once()->andReturn(['ok' => true]);
        $remote->shouldReceive('start')->once()->with(true)->andReturn([
            'ok' => true, 'url' => 'http://x/vnc.html', 'started_at' => time(), 'expires_at' => time() + 900,
        ]);

        $this->postJson('/api/admin/v1/platforms/instagram/remote-login/reset', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_report_remote_error_stops_the_session_and_forgets_the_cached_url(): void
    {
        Cache::put('platforms.remote_login_url.instagram', 'http://x', now()->addMinutes(5));

        $remote = $this->mockRemote();
        $remote->shouldReceive('stop')->once()->andReturn(['ok' => true]);

        $this->postJson('/api/admin/v1/platforms/instagram/remote-login/report-error', [
            'reason' => 'iframe failed to load',
        ], $this->bearer())->assertOk()->assertJsonPath('data.ok', true);

        $this->assertNull(Cache::get('platforms.remote_login_url.instagram'));
    }
}
