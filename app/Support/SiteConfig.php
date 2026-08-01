<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Arr;

/**
 * Per-site configuration, resolved as an overlay in three tiers:
 *
 *   1. Site settings JSON     $site->settings['config'][$key]   (admin-editable)
 *   2. Per-site config file   config/sites/{slug}/{key}.php     (large/structured)
 *   3. Shared default         config/{key}.php                  (today's files)
 *
 * Tier 3 means gs.construction needs no migration at all — its existing config
 * files keep working untouched and remain the default for any site that has
 * not overridden them. A new site overrides only what actually differs.
 *
 * Top-level keys merge, so a site can override `socials.instagram.url` without
 * restating every other network. Numeric-keyed lists replace wholesale rather
 * than merging, since merging ordered lists by index produces nonsense.
 */
class SiteConfig
{
    /** @var array<string, array<string, mixed>> resolved per site+file */
    protected static array $cache = [];

    /**
     * Pristine config, captured BEFORE any tenant override is pushed into the
     * runtime repository. Without this, resolving site B would merge on top of
     * site A's already-injected values instead of the shared defaults.
     *
     * @var array<string, array<mixed, mixed>>
     */
    protected static array $base = [];

    /** @var array<string, true> files currently overridden in the runtime config */
    protected static array $mutated = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        [$file, $path] = array_pad(explode('.', $key, 2), 2, null);

        $resolved = static::resolve($file);

        return $path === null ? $resolved : Arr::get($resolved, $path, $default);
    }

    /**
     * Read ANOTHER site's config without making it the current tenant.
     *
     * For the dev switcher and sites:check, which report on every site while
     * one is bound. Deliberately not Tenancy::for(): that binds the site and
     * calls URL::forceRootUrl(), which would leak into the rest of the
     * request. Safe because the cache is already keyed per site and base()
     * snapshots the pristine config before applyRuntime() ever mutates it.
     */
    public static function forSite(Site $site, string $key, mixed $default = null): mixed
    {
        [$file, $path] = array_pad(explode('.', $key, 2), 2, null);

        $resolved = static::resolve($file, $site);

        return $path === null ? $resolved : Arr::get($resolved, $path, $default);
    }

    /** @return array<string, mixed> */
    protected static function resolve(string $file, ?Site $site = null): array
    {
        $site ??= Site::current();
        $cacheKey = $site->id . ':' . $file;

        if (isset(static::$cache[$cacheKey])) {
            return static::$cache[$cacheKey];
        }

        $value = static::base($file);

        $siteFile = config_path("sites/{$site->slug}/{$file}.php");
        if (is_file($siteFile)) {
            $value = static::merge($value, (array) require $siteFile);
        }

        $fromSettings = $site->setting("config.{$file}");
        if (is_array($fromSettings)) {
            $value = static::merge($value, $fromSettings);
        }

        return static::$cache[$cacheKey] = $value;
    }

    /**
     * @param  array<mixed, mixed>  $base
     * @param  array<mixed, mixed>  $override
     * @return array<mixed, mixed>
     */
    protected static function merge(array $base, array $override): array
    {
        // A list means "replace" — merging ordered data by index is never right.
        if (array_is_list($override)) {
            return $override;
        }

        // Explicit opt-out of inheritance for identity data.
        if (($override['__replace'] ?? false) === true) {
            unset($override['__replace']);

            return $override;
        }

        foreach ($override as $k => $v) {
            $base[$k] = is_array($v) && is_array($base[$k] ?? null)
                ? static::merge($base[$k], $v)
                : $v;
        }

        return $base;
    }

    /**
     * Push this site's overrides into Laravel's runtime config repository.
     *
     * site_config() only helps code we control. Third-party packages read
     * config() directly — ralphjsmit/laravel-seo reads config('seo.site_name'),
     * and config/geo.php resolves identity from env(), which is process-global
     * and cannot vary per tenant. Injecting at request time makes per-site
     * values visible to the framework and every package, not just our views.
     *
     * Safe under PHP-FPM (one request per process). Console/queue callers must
     * invoke this themselves when switching sites.
     */
    public static function applyRuntime(Site $site): void
    {
        // Restore anything a previous tenant injected. Required: a site that
        // overrides nothing must fall back to the shared defaults, not inherit
        // whichever tenant ran before it in this process.
        static::restore();

        foreach (static::overrideKeys($site) as $file) {
            static::base($file);
            config([$file => static::resolve($file)]);
            static::$mutated[$file] = true;
        }
    }

    /** Put the runtime config back to the shared defaults. */
    public static function restore(): void
    {
        foreach (array_keys(static::$mutated) as $file) {
            config([$file => static::$base[$file]]);
        }

        static::$mutated = [];
    }

    /** Pristine value for a config file, snapshotted before first mutation. */
    protected static function base(string $file): array
    {
        return static::$base[$file] ??= (array) config($file, []);
    }

    /**
     * Config files this site actually overrides — from per-site files and the
     * settings JSON. Nothing is touched for a site with no overrides.
     *
     * @return array<int, string>
     */
    protected static function overrideKeys(Site $site): array
    {
        $keys = array_keys((array) $site->setting('config', []));

        $dir = config_path("sites/{$site->slug}");
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.php') ?: [] as $path) {
                $keys[] = basename($path, '.php');
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Drop memoised per-site values (site switch inside one process, tests).
     * Deliberately keeps $base: that is the pristine snapshot, not a cache.
     */
    public static function flush(): void
    {
        static::$cache = [];
    }
}
