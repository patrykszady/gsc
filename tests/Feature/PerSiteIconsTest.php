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
     * gs.construction's icon is the logo's own GS letters, navy (#132146) on
     * the logo's light blue (#50c8ec) — Patryk, 2026-10-27: the full badge
     * was "hard to read" small; "just use GS, light blue background and navy
     * GS, our logo colours and GS font". The 2026-09-24 navy-disc mark stays
     * rejected. Sources in resources/brand/gsc-icon-gs*.svg.
     */
    public function test_gs_construction_wears_its_gs_letters_on_light_blue(): void
    {
        foreach (['public/icons/gsc/favicon.svg', 'resources/brand/gsc-icon-gs-full.svg', 'resources/brand/gsc-icon-gs-maskable.svg'] as $file) {
            $svg = (string) file_get_contents(base_path($file));

            $this->assertStringNotContainsString('<circle', $svg, $file.': not the rejected navy-disc mark');
            $this->assertMatchesRegularExpression('/<rect width="144" height="144"( rx="\d+")? fill="#50c8ec"\/>/', $svg, $file.': light-blue background');
            $this->assertSame(2, substr_count($svg, 'fill="#132146"'), $file.': the navy G and S, nothing else');
        }

        $this->assertFileDoesNotExist(resource_path('brand/gsc-favicon-mark.svg'));

        // The raster icons are the same artwork: light blue at the edge, navy in the letters.
        $touch = imagecreatefrompng(public_path('icons/gsc/apple-touch-icon.png'));
        $edge = imagecolorsforindex($touch, imagecolorat($touch, 2, 2));
        $this->assertSame([80, 200, 236], [$edge['red'], $edge['green'], $edge['blue']]);
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
