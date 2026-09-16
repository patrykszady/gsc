<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The standalone page /admin shows when ss-systems is down is shared by
 * every site, so it must wear the site's own brand: gs.construction's logo
 * and sky accent on gs.construction, the studio's serif-and-teal look on
 * J. Peterson's. It once hardcoded the latter for both.
 */
class AdminProxyDownPageTest extends TestCase
{
    public function test_it_wears_the_default_site_brand(): void
    {
        config([
            'brand.display_name' => 'GS Construction & Remodeling',
            'admin.accent' => null,
            'admin.logo' => 'images/logo.svg',
        ]);

        $html = view('errors.admin-proxy-down')->render();

        $this->assertStringContainsString('Admin unavailable — GS Construction &amp; Remodeling', $html);
        $this->assertStringContainsString('images/logo.svg', $html);
        $this->assertStringContainsString('background: #0ea5e9', $html);
        $this->assertStringContainsString("font-family: -apple-system", $html);
        $this->assertStringNotContainsString('Studio Admin', $html);
        $this->assertStringNotContainsString('#4e9da2', $html);
        $this->assertStringNotContainsString('Georgia', $html);
    }

    public function test_it_wears_the_studio_look_when_the_site_says_so(): void
    {
        config([
            'brand.display_name' => 'J. Peterson Design',
            'admin.accent' => [500 => '#408085', 600 => '#366c70'],
            'admin.logo' => null,
            'admin.proxy_down' => (require base_path('config/sites/jpeterson/admin.php'))['proxy_down'],
        ]);

        $html = view('errors.admin-proxy-down')->render();

        $this->assertStringContainsString('Admin unavailable — J. Peterson Design', $html);
        $this->assertStringContainsString('Studio Admin', $html);
        $this->assertStringContainsString('background: #408085', $html);
        $this->assertStringContainsString('Georgia', $html);
        $this->assertStringContainsString('border-radius: 999px', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_the_proxy_page_wears_the_brand_of_the_host_it_is_served_on(): void
    {
        config(['services.ss.url' => 'http://ss.test']);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        // The migrated jpeterson row is inactive until launch; a host only
        // resolves for an active site (same as PerSiteSearchConsoleTest).
        $site = \App\Models\Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        \App\Models\Site::forgetActive();

        // The proxy route runs without ResolveSite; the fallback must still
        // bind the tenant from the host, or every tenant gets GS Construction's page.
        // A full URL: Symfony's Request::create() takes the host from the URL,
        // so a Host header alone would be overwritten by app.url's host.
        $this->get('http://jpeterson-design.com/admin')
            ->assertStatus(502)
            ->assertSee('Admin unavailable — J. Peterson Design')
            ->assertSee('Studio Admin')
            ->assertDontSee('images/logo.svg');
    }

    public function test_the_proxy_shows_it_when_ss_systems_cannot_be_reached(): void
    {
        config(['services.ss.url' => 'http://ss.test', 'admin.logo' => 'images/logo.svg']);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->get('/admin')
            ->assertStatus(502)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Admin is temporarily unavailable')
            ->assertSee('images/logo.svg')
            ->assertDontSee('Studio Admin');
    }
}
