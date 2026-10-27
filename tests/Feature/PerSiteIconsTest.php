<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\SiteIcons;
use Tests\TestCase;

/**
 * Each site links its own icons and manifest. The files used to sit at the
 * public root — gs.construction's — so every host of the deployment showed
 * GS's mark, and J. Peterson Design's layout carried an inline placeholder.
 */
class PerSiteIconsTest extends TestCase
{
    /** The migrated jpeterson row is inactive until launch; a host only resolves for an active site. */
    private function jpd(): Site
    {
        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    public function test_every_site_ships_a_complete_icon_set(): void
    {
        $this->assertSame([], SiteIcons::missing(), 'gs.construction');
        $this->assertSame([], SiteIcons::missing($this->jpd()), 'jpeterson');
    }

    public function test_each_host_links_its_own_icons_and_manifest(): void
    {
        $this->jpd();

        $gs = $this->get('https://gs.construction/')->assertOk()->getContent();
        $this->assertStringContainsString('href="https://gs.construction/icons/gsc/favicon-96x96.png"', $gs);
        $this->assertStringContainsString('href="https://gs.construction/icons/gsc/favicon.ico"', $gs);
        $this->assertStringContainsString('<meta name="theme-color" content="#1a1a1a">', $gs);
        // Organization/logo in the structured data moved with the files (JSON may escape the slashes).
        $this->assertMatchesRegularExpression('#icons\\\\?/gsc\\\\?/android-chrome-512x512\.png#', $gs, 'schema logo is the per-site icon');
        $this->assertStringNotContainsString('gs.construction/android-chrome-512x512.png', $gs, 'the old root icon path is gone');

        $jpd = $this->get('https://jpeterson-design.com/')->assertOk()->getContent();
        $this->assertStringContainsString('href="https://jpeterson-design.com/icons/jpeterson/favicon-96x96.png"', $jpd);
        $this->assertStringContainsString('href="https://jpeterson-design.com/icons/jpeterson/favicon.svg"', $jpd);
        $this->assertStringContainsString('<meta name="theme-color" content="#408085">', $jpd);
        // Hero placeholders are data URIs too; only the icon links matter here.
        $this->assertDoesNotMatchRegularExpression('/<link[^>]+rel="(?:shortcut )?icon"[^>]+href="data:/', $jpd, 'the inline placeholder icon is gone');
        $this->assertStringNotContainsString('/icons/gsc/', $jpd, 'never another site\'s icons');
    }

    /**
     * gs.construction's icon is the original GS badge (Patryk, 2026-10-27:
     * "it should be our original logo"). The 2026-09-24 square mark — the
     * badge's letters and roof on a navy disc, made for Google's 18 px
     * result slot — was rejected and removed.
     */
    public function test_gs_construction_wears_its_original_badge_icon(): void
    {
        $svg = (string) file_get_contents(public_path('icons/gsc/favicon.svg'));

        $this->assertStringNotContainsString('<circle', $svg, 'the rejected navy-disc mark is not the icon');
        $this->assertStringContainsString('<rect width="144" height="144" fill="#ffffff"/>', $svg, 'the badge sits on white');
        $this->assertStringContainsString('fill="#50c8ec"', $svg, 'with its light-blue outline');
        $this->assertFileDoesNotExist(resource_path('brand/gsc-favicon-mark.svg'));
    }

    public function test_the_manifest_and_root_favicon_are_answered_per_host(): void
    {
        $this->jpd();

        $this->get('https://gs.construction/site.webmanifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'GS Construction & Remodeling')
            ->assertJsonPath('icons.1.src', 'https://gs.construction/icons/gsc/android-chrome-512x512.png');

        $this->get('https://jpeterson-design.com/site.webmanifest')->assertOk()
            ->assertJsonPath('name', 'J. Peterson Design')
            ->assertJsonPath('theme_color', '#408085')
            ->assertJsonPath('icons.0.src', 'https://jpeterson-design.com/icons/jpeterson/android-chrome-192x192.png');

        $this->get('https://gs.construction/favicon.ico')->assertOk()->assertHeader('Content-Type', 'image/x-icon');
        $this->get('https://jpeterson-design.com/favicon.ico')->assertOk()->assertHeader('Content-Type', 'image/x-icon');
        $this->assertFileDoesNotExist(public_path('favicon.ico'), 'a static root favicon would be served to every host');
        $this->assertFileDoesNotExist(public_path('site.webmanifest'));
    }

    /** The old root URLs — Google's stored logo, the favicon it first found — land on the tenant's own file. */
    public function test_the_old_root_icon_urls_redirect_to_the_sites_own_set(): void
    {
        $this->jpd();

        $this->get('https://gs.construction/android-chrome-512x512.png')
            ->assertRedirect('https://gs.construction/icons/gsc/android-chrome-512x512.png')->assertStatus(301);
        $this->get('https://gs.construction/favicon-32x32.png')
            ->assertRedirect('https://gs.construction/icons/gsc/favicon-96x96.png')->assertStatus(301);
        $this->get('https://gs.construction/favicon-dark.svg')
            ->assertRedirect('https://gs.construction/icons/gsc/favicon.svg')->assertStatus(301);
        $this->get('https://jpeterson-design.com/apple-touch-icon.png')
            ->assertRedirect('https://jpeterson-design.com/icons/jpeterson/apple-touch-icon.png')->assertStatus(301);
        $this->get('https://gs.construction/favicon-999x999.png')->assertNotFound();
    }
}
