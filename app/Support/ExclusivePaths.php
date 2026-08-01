<?php

namespace App\Support;

/**
 * Which tenant owns a public path.
 *
 * routes/web.php registers one global route table, so every host would
 * otherwise serve every route — complete with gs.construction's content — on
 * its own domain. config/sites.php 'exclusive_paths' is the single reviewable
 * list of who claims what, and this class is the one place that reads it.
 *
 * Extracted from TenantRouteGuard so the dev switcher and `sites:check` can
 * predict a 404 using EXACTLY the logic that will produce it at request time.
 * A second implementation would drift, and the failure mode of drift here is
 * a checker that reports green while the middleware 404s the visitor.
 */
class ExclusivePaths
{
    /**
     * Every site claiming this path. Empty array = universal.
     *
     * Match is prefix-based: 'compare' covers /compare and /compare/anything.
     * A path may be claimed by several tenants (e.g. /about exists on both gsc
     * and jpeterson, each rendering its own themed view via the overlay).
     *
     * @return array<int, string>
     */
    public static function ownersOf(string $path): array
    {
        $path = ltrim($path, '/');
        $owners = [];

        foreach ((array) config('sites.exclusive_paths', []) as $slug => $prefixes) {
            foreach ((array) $prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                    $owners[] = (string) $slug;
                    break;
                }
            }
        }

        return $owners;
    }

    /** May this tenant serve this path? Unclaimed paths are universal. */
    public static function allows(string $slug, string $path): bool
    {
        $owners = static::ownersOf($path);

        return $owners === [] || in_array($slug, $owners, true);
    }
}
