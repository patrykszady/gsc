<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Facades\View;

/**
 * Per-site theming.
 *
 * A theme is an overlay, not a copy: `resources/themes/{theme}` is
 * prepended to the view finder, so a theme overrides only the views it
 * actually defines and everything else falls through to `resources/views`.
 * That means a new site starts by inheriting the whole existing site and
 * diverges one file at a time, instead of needing a full set on day one.
 *
 * Themes live OUTSIDE `resources/views` deliberately. `view:cache` compiles
 * every Blade file under the finder's paths recursively, and a theme's views
 * reference theme-local components (`<x-button>`, `<x-cta>`) that only
 * resolve once that theme is prepended — which never happens on the CLI,
 * where no site is resolved. With themes nested inside `resources/views`,
 * `php artisan view:cache` therefore died with "Unable to locate a class or
 * view for component [cta]" and took the whole deploy down with it.
 * Keeping them out of that tree means the optimizer only compiles shared
 * views; theme views compile on first render, with the theme in place.
 *
 * Asset entries are per-theme too, so each site ships its own compiled CSS
 * (own palette, fonts, tokens) with no shared bundle to fight over.
 */
class Theme
{
    /**
     * Point the view finder at this site's theme directory.
     *
     * Idempotent by construction: it rebuilds the path list with EVERY theme
     * directory removed before prepending this site's. prependLocation() is a
     * bare array_unshift with no dedupe, and apply() runs more than once per
     * request — the web group resolves the site, then Livewire's persistent
     * middleware resolves it again on each update, and an admin request runs
     * ResolveSite followed by ResolveAdminSite. Without the rebuild, theme
     * paths accumulate on the finder; harmless under PHP-FPM, unbounded under
     * Octane or a long-lived queue worker, and a correctness bug the moment a
     * second tenant's path is still sitting in the list ahead of this one's.
     */
    public static function apply(Site $site): void
    {
        $path = self::path($site);
        $themeRoot = resource_path('themes');

        $finder = View::getFinder();

        $paths = array_values(array_filter(
            $finder->getPaths(),
            fn (string $existing): bool => ! str_starts_with($existing, $themeRoot),
        ));

        // A site with no theme directory falls through to resources/views —
        // that is how gsc works, and how a new tenant starts before it has any
        // views of its own.
        if (is_dir($path)) {
            array_unshift($paths, $path);
        }

        $finder->setPaths($paths);

        // The finder memoises resolved view => file mappings. Without this a
        // second site in the same process (queue worker, test, console loop)
        // would keep serving the first site's templates. Runs unconditionally:
        // switching TO a theme-less site must still discard the previous
        // site's resolved views.
        $finder->flush();

        // Per-site config is memoised per process for the same reason.
        SiteConfig::flush();
    }

    public static function path(Site $site): string
    {
        return resource_path('themes/' . $site->theme);
    }

    /**
     * Vite entries for the site. Defaults to the shared bundle so a theme with
     * no assets of its own still renders correctly.
     *
     * @return array<int, string>
     */
    public static function viteEntries(Site $site): array
    {
        $custom = $site->setting('assets.vite');

        if (is_array($custom) && $custom !== []) {
            return $custom;
        }

        $css = "resources/css/themes/{$site->theme}/app.css";

        return file_exists(base_path($css))
            ? [$css, 'resources/js/app.js']
            : ['resources/css/app.css', 'resources/js/app.js'];
    }
}
