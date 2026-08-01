<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

/**
 * Data behind the local dev site bar and /_sites.
 *
 * Everything here is derived from live sources — the sites table, the view
 * finder, the route table, config/sites.php — never from a hand-maintained
 * duplicate. A register that has to be updated by hand is a register that
 * lies, and the whole point of this is to answer "which tenant am I on and
 * what will this link do" without guessing.
 *
 * Local-only. Nothing in this class is referenced from the production
 * pipeline: the middleware and routes that use it are registered inside an
 * environment check.
 */
class DevSites
{
    /**
     * One row per tenant, describing it and what $path would do there.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function register(string $path, ?int $port = null): Collection
    {
        $current = Site::current();
        $port ??= static::port();

        return Site::listAll()->map(function (Site $site) use ($path, $port, $current): array {
            [$status, $note] = static::verdict($site, $path);

            $themeDir = Theme::path($site);

            return [
                'site' => $site,
                'is_current' => $site->id === $current->id,
                // Stable per slug, so a tenant keeps its colour between runs.
                'hue' => crc32($site->slug) % 360,
                'url' => static::urlFor($site, $path, $port),
                'theme_dir' => $themeDir,
                'theme_exists' => is_dir($themeDir),
                'overlays' => static::overlays($site),
                'claims' => (array) config("sites.exclusive_paths.{$site->slug}", []),
                'status' => $status,
                'note' => $note,
                'nav' => static::nav($site),
            ];
        });
    }

    /**
     * The port the dev server is actually listening on.
     *
     * SERVER_PORT before getPort(): getPort() reads the Host header first and
     * falls back to 80 when it carries no port. Browsers always send
     * "host:8003"; curl and scripts do not, and a link to port 80 goes
     * nowhere.
     */
    public static function port(): int
    {
        $request = request();

        return (int) ($request?->server('SERVER_PORT') ?: $request?->getPort() ?: 8003);
    }

    /** The same path on that tenant's local host. */
    public static function urlFor(Site $site, string $path, int $port): string
    {
        return 'http://' . $site->devHost()
            . ($port !== 80 ? ':' . $port : '')
            . '/' . ltrim($path, '/');
    }

    /**
     * What this tenant would do with this path.
     *
     * A PREDICTION, not a fetch: it answers "is there a route, and does the
     * tenant guard allow it". A 200 here can still 404 at request time when
     * the bound model has no row for this tenant under the site scope, so the
     * UI labels it as predicted.
     *
     * @return array{0: string, 1: string}
     */
    public static function verdict(Site $site, string $path): array
    {
        $path = '/' . ltrim($path, '/');

        $owners = ExclusivePaths::ownersOf($path);

        if ($owners !== [] && ! in_array($site->slug, $owners, true)) {
            return ['404', 'claimed by ' . implode(' + ', $owners)];
        }

        if (! static::routeExists($path)) {
            return ['404', 'no route'];
        }

        return ['200', $owners === [] ? 'universal' : 'claimed by ' . implode(' + ', $owners)];
    }

    /**
     * Is there a GET route for this path?
     *
     * Uses Route::matches() rather than RouteCollection::match(): match()
     * calls bind() on the matched route, which would rebind the parameters of
     * the very Route instance currently serving this request.
     */
    protected static function routeExists(string $path): bool
    {
        $probe = Request::create($path, 'GET');

        foreach (Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if ($route->matches($probe, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * This tenant's nav links, each with its verdict.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function nav(Site $site): array
    {
        $links = (array) SiteConfig::forSite($site, 'nav.links', []);

        return collect($links)->map(function (array $link) use ($site): array {
            $href = (string) ($link['href'] ?? '');

            // Anchors and off-site links have no route to check.
            if ($href === '' || str_starts_with($href, '#') || str_contains($href, '://') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                return ['label' => $link['label'] ?? $href, 'href' => $href, 'status' => '—', 'note' => 'anchor/external'];
            }

            [$status, $note] = static::verdict($site, parse_url($href, PHP_URL_PATH) ?: '/');

            return ['label' => $link['label'] ?? $href, 'href' => $href, 'status' => $status, 'note' => $note];
        })->all();
    }

    /** Config files this site overrides — file overlays plus settings JSON. */
    public static function overlays(Site $site): array
    {
        $keys = array_keys((array) $site->setting('config', []));

        foreach (glob(config_path("sites/{$site->slug}/*.php")) ?: [] as $file) {
            $keys[] = basename($file, '.php');
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * Theme view files that actually served this response — the direct answer
     * to "is my override being picked up, or am I looking at the shared view".
     */
    public static function viewsUsed(): array
    {
        $themeRoot = resource_path('views/themes');

        return collect(View::getFinder()->getViews())
            ->filter(fn (string $file): bool => str_starts_with($file, $themeRoot))
            ->map(fn (string $file): string => ltrim(str_replace($themeRoot, '', $file), '/'))
            ->values()
            ->all();
    }

    /** How ResolveSite picked the current tenant, for display. */
    public static function resolvedVia(): string
    {
        return app()->bound('site.resolved_via')
            ? (string) app('site.resolved_via')
            : 'unknown';
    }
}
