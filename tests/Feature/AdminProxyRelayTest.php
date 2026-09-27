<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * gsc's AdminProxyController is now a ~15-line wrapper around
 * SsSystems\Platform\Http\AdminProxyRelay (kit 0.13.0) — this pins the
 * behaviour that lives in THIS site's controller (site brand overlay on the
 * down page, the newly-enabled circuit breaker) plus the request path every
 * admin hit takes through the shared relay. AdminProxyDownPageTest already
 * covers the down page's brand rendering in detail; this file covers
 * pass-through, the /admin/_assets Cache-Control fix, Location rewrite and
 * the breaker.
 */
class AdminProxyRelayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ss.url' => 'http://ss.test',
            'services.ss.service_secret' => 'secret',
        ]);
    }

    public function test_it_relays_a_successful_response_including_the_noindex_header(): void
    {
        Http::fake([
            'ss.test/*' => Http::response('<html>hi</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->get('/admin/gsc/login')
            ->assertOk()
            ->assertSee('hi');

        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        Http::assertSent(fn ($request) => $request->url() === 'http://ss.test/admin/gsc/login'
            && $request->hasHeader('X-Site-Key', 'gsc')
            && $request->hasHeader('X-Ss-Service-Secret', 'secret'));
    }

    /**
     * The bug this port fixes: gsc used to append every upstream header onto
     * response()'s own defaults, so an asset's "public, max-age=31536000,
     * immutable" merged with Laravel's default Cache-Control "no-cache,
     * private" into a header that still carried no-cache — the admin
     * stylesheet and the Flux bundle re-downloaded on every hard refresh.
     */
    public function test_admin_assets_get_a_cacheable_cache_control_header_not_merged_with_the_default(): void
    {
        Http::fake([
            'ss.test/*' => Http::response('body{}', 200, [
                'Content-Type' => 'text/css',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]),
        ]);

        $response = $this->get('/admin/_assets/app.css')->assertOk();

        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertTrue($response->headers->hasCacheControlDirective('immutable'));
        $this->assertSame('31536000', $response->headers->getCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-cache'));
    }

    public function test_a_redirect_location_pointing_at_ss_systems_is_rewritten_to_this_hosts_origin(): void
    {
        Http::fake([
            'ss.test/*' => Http::response('', 302, ['Location' => 'http://ss.test/admin/gsc/login']),
        ]);

        $response = $this->get('http://gs-construction.test/admin/gsc/logout');

        $response->assertStatus(302);
        $this->assertSame('http://gs-construction.test/admin/gsc/login', $response->headers->get('Location'));
    }

    public function test_the_proxy_never_csrf_blocks_a_post_under_admin(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        // A blocked CSRF token would 419, not 502 — proving the route's
        // withoutMiddleware took effect.
        $this->post('/admin/gsc/login', ['foo' => 'bar'])->assertStatus(502);
    }

    // --- the circuit breaker: NEW on gsc as of kit 0.13.0 -------------------

    public function test_a_connection_failure_opens_the_breaker_so_the_next_hit_skips_the_outbound_call(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->get('/admin/gsc/login')->assertStatus(502);

        Http::fake(); // any outbound call now would fail the test via an unexpected request
        $this->get('/admin/gsc/login')->assertStatus(502);

        Http::assertNothingSent();
    }

    public function test_the_down_page_still_wears_the_visiting_hosts_brand_once_the_breaker_is_open(): void
    {
        // Regression guard for the exact failure the brief calls out:
        // beforeDown must still fire from the cached-breaker early return,
        // not only from the ConnectionException catch, or the overlay
        // silently stops applying the instant the breaker trips.
        Cache::put('admin-proxy-down', true, now()->addSeconds(60));

        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        Site::forgetActive();

        Http::fake(); // any outbound call here would fail the test via an unexpected request

        $this->get('http://jpeterson-design.com/admin')
            ->assertStatus(502)
            ->assertSee('Admin unavailable — J. Peterson Design')
            ->assertSee('Studio Admin');

        Http::assertNothingSent();
    }

    public function test_an_empty_service_secret_serves_the_down_page_without_an_outbound_call(): void
    {
        config(['services.ss.service_secret' => '']);
        Http::fake(); // any outbound call here would fail the test via an unexpected request

        $this->get('/admin/gsc/login')->assertStatus(502);

        Http::assertNothingSent();
    }
}
