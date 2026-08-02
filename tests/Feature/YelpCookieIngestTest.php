<?php

namespace Tests\Feature;

use App\Jobs\VerifyYelpCookies;
use App\Models\PlatformSetting;
use App\Services\YelpBusinessService;
use App\Support\YelpCookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The browser-extension cookie hand-off.
 *
 * Context that drove these cases: the production Yelp session died because the
 * cookie jar was a frozen 2026-06-01 snapshot whose `biz_session` expired
 * 2026-07-25 — every automation run afterwards faithfully injected a dead
 * cookie. And `bse`, the primary auth cookie, is session-only, so without a
 * synthetic expiry it never survives to the next Chromium launch.
 */
class YelpCookieIngestTest extends TestCase
{
    protected string $token = 'test-token-abcdefghijklmnop';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.yelp.business.cookie_ingest_token', $this->token);
        Config::set('services.yelp.business.cookie_session_ttl_days', 30);
        // Never the real jar. Pointing tests at storage/app/yelp-cookies.json
        // means tearDown deletes the operator's live Yelp session.
        Config::set('services.yelp.business.cookies_file', $this->jar = sys_get_temp_dir() . '/yelp-cookies-test-' . getmypid() . '.json');
    }

    protected function tearDown(): void
    {
        @unlink($this->jar);
        parent::tearDown();
    }

    protected string $jar = '';

    protected function cookies(array $overrides = []): array
    {
        return array_merge([
            ['name' => 'bse', 'value' => 'abc', 'domain' => '.yelp.com', 'path' => '/',
             'secure' => true, 'httpOnly' => true, 'session' => true, 'sameSite' => 'lax'],
            ['name' => 'biz_session', 'value' => 'xyz', 'domain' => '.biz.yelp.com', 'path' => '/',
             'secure' => true, 'httpOnly' => true, 'expirationDate' => 1900000000.5, 'sameSite' => 'no_restriction'],
        ], $overrides);
    }

    protected function ingest(array $payload, ?string $token = null)
    {
        return $this->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token))
            ->postJson('/api/yelp/cookies', $payload);
    }

    public function test_it_rejects_a_bad_token(): void
    {
        $this->ingest(['cookies' => $this->cookies()], 'wrong')->assertStatus(401);
    }

    public function test_it_is_disabled_when_no_token_is_configured(): void
    {
        Config::set('services.yelp.business.cookie_ingest_token', null);
        PlatformSetting::put(YelpBusinessService::SETTING_INGEST_TOKEN, null);

        $this->ingest(['cookies' => $this->cookies()])->assertStatus(503);
    }

    public function test_an_admin_generated_token_also_authenticates(): void
    {
        Config::set('services.yelp.business.cookie_ingest_token', null);
        PlatformSetting::put(YelpBusinessService::SETTING_INGEST_TOKEN, 'db-token-123');
        Queue::fake();

        $this->ingest(['cookies' => $this->cookies()], 'db-token-123')->assertOk();
    }

    public function test_it_stores_cookies_and_queues_verification(): void
    {
        Queue::fake();

        $this->ingest(['cookies' => $this->cookies()])
            ->assertOk()
            ->assertJson(['ok' => true, 'stored' => 2]);

        $this->assertFileExists(YelpCookieJar::path());
        Queue::assertPushed(VerifyYelpCookies::class);
    }

    public function test_session_only_cookies_get_a_real_expiry_so_they_survive_the_next_run(): void
    {
        Queue::fake();
        $this->ingest(['cookies' => $this->cookies()])->assertOk();

        $stored = collect(json_decode(file_get_contents(YelpCookieJar::path()), true));
        $bse = $stored->firstWhere('name', 'bse');

        // Without this, CDP stores bse in memory only: it authenticates one
        // Chromium run and is gone from the profile on the next.
        $this->assertArrayHasKey('expires', $bse);
        $this->assertGreaterThan(time(), $bse['expires']);
    }

    public function test_it_rejects_non_yelp_domains_by_suffix_not_substring(): void
    {
        Queue::fake();

        $this->ingest(['cookies' => [
            ['name' => 'evil', 'value' => '1', 'domain' => 'evil-yelp.com.attacker.net',
             'expirationDate' => 1900000000],
            ...$this->cookies(),
        ]])->assertOk()->assertJson(['stored' => 2]);

        $names = collect(json_decode(file_get_contents(YelpCookieJar::path()), true))->pluck('name');
        $this->assertNotContains('evil', $names);
    }

    public function test_it_refuses_a_jar_with_no_session_cookie(): void
    {
        Queue::fake();

        $this->ingest(['cookies' => [
            ['name' => 'analytics', 'value' => '1', 'domain' => '.yelp.com', 'expirationDate' => 1900000000],
        ]])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_samesite_is_mapped_to_cdp_casing(): void
    {
        Queue::fake();
        $this->ingest(['cookies' => $this->cookies()])->assertOk();

        $stored = collect(json_decode(file_get_contents(YelpCookieJar::path()), true));
        $this->assertSame('Lax', $stored->firstWhere('name', 'bse')['sameSite']);
        $this->assertSame('None', $stored->firstWhere('name', 'biz_session')['sameSite']);
    }

    public function test_host_only_cookies_are_emitted_as_url_not_domain(): void
    {
        Queue::fake();
        $this->ingest(['cookies' => [
            ...$this->cookies(),
            ['name' => 'hostonly', 'value' => '1', 'domain' => 'biz.yelp.com', 'hostOnly' => true,
             'path' => '/', 'secure' => true, 'expirationDate' => 1900000000],
        ]])->assertOk();

        $c = collect(json_decode(file_get_contents(YelpCookieJar::path()), true))->firstWhere('name', 'hostonly');
        $this->assertArrayNotHasKey('domain', $c);
        $this->assertSame('https://biz.yelp.com/', $c['url']);
    }

    public function test_pairing_requires_an_admin_session(): void
    {
        $this->getJson('/admin/gs.construction/platforms/extension-pairing')
            ->assertStatus(401);
    }

    public function test_pairing_mints_a_token_and_returns_it(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_INGEST_TOKEN, null);
        $user = \App\Models\User::factory()->create();

        $res = $this->actingAs($user)
            ->getJson('/admin/gs.construction/platforms/extension-pairing')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $token = $res->json('token');
        $this->assertNotEmpty($token);
        // The minted token must be the one the ingest endpoint accepts.
        $this->assertSame($token, PlatformSetting::get(YelpBusinessService::SETTING_INGEST_TOKEN));
    }

    public function test_pairing_returns_the_existing_token_without_rotating_it(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_INGEST_TOKEN, 'stable-token');
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->getJson('/admin/gs.construction/platforms/extension-pairing')
            ->assertOk()
            ->assertJsonPath('token', 'stable-token');

        // Auto-pairing runs on every platforms-page visit; rotating on each
        // call would invalidate every OTHER paired browser each time.
        $this->assertSame('stable-token', PlatformSetting::get(YelpBusinessService::SETTING_INGEST_TOKEN));
    }

    public function test_expired_names_flags_a_rotten_jar(): void
    {
        $expired = YelpCookieJar::expiredNames([
            ['name' => 'biz_session', 'expires' => time() - 86400],
            ['name' => 'fresh', 'expires' => time() + 86400],
        ]);

        $this->assertSame(['biz_session'], $expired);
    }
}
