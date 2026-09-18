<?php

namespace App\Support;

use App\Models\Site;

/**
 * A site's own icons: favicon, touch icon, install icons and the web
 * manifest, one set per tenant under public/icons/{slug}/.
 *
 * They used to be single files at the public root — gs.construction's — so
 * every host of this deployment showed GS's mark, and J. Peterson Design's
 * layout carried an inline placeholder. The head now links each site's own
 * set, the manifest is rendered per site, and `icons:build` rasterises a
 * set from a site's mark. `sites:check` reports a site whose set is missing.
 */
final class SiteIcons
{
    /** The files a complete set has, in the order the head lists them. */
    public const FILES = [
        'favicon-96x96.png',
        'favicon.svg',
        'favicon.ico',
        'apple-touch-icon.png',
        'android-chrome-192x192.png',
        'android-chrome-512x512.png',
    ];

    /** A Site, or just its slug — icons:build runs before a site row may exist. */
    public static function dir(Site|string|null $site = null): string
    {
        $slug = is_string($site) ? $site : ($site ?? Site::current())->slug;

        return 'icons/'.$slug;
    }

    public static function path(string $file, Site|string|null $site = null): string
    {
        return public_path(self::dir($site).'/'.$file);
    }

    /** Stable, per-site URL — no cache-busting stamp: Google keeps the favicon URL it first found. */
    public static function url(string $file, Site|string|null $site = null): string
    {
        return asset(self::dir($site).'/'.$file);
    }

    /** @return list<string> files of the set this site is missing */
    public static function missing(Site|string|null $site = null): array
    {
        return array_values(array_filter(self::FILES, fn (string $file) => ! is_file(self::path($file, $site))));
    }

    public static function themeColor(): string
    {
        return (string) config('brand.theme_color', '#1a1a1a');
    }

    /**
     * The web app manifest, from the brand config, with this site's icons.
     *
     * @return array<string, mixed>
     */
    public static function manifest(?Site $site = null): array
    {
        return [
            'name' => (string) config('brand.display_name', config('brand.name')),
            'short_name' => (string) config('brand.name', config('brand.display_name')),
            'description' => (string) config('geo.site_description', config('seo.description', '')),
            'icons' => [
                ['src' => self::url('android-chrome-192x192.png', $site), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => self::url('android-chrome-512x512.png', $site), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => (string) config('brand.background_color', '#ffffff'),
            'theme_color' => self::themeColor(),
        ];
    }
}
