<?php

namespace App\Support;

use App\Models\Site;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Runs work in the context of a specific tenant.
 *
 * HTTP requests get their tenant from ResolveSite. Console commands, queued
 * jobs and scheduled tasks have no request, so Site::current() falls back to
 * the default site — meaning anything that ingests or writes per-site data
 * from the console silently operates on gs.construction regardless of intent.
 *
 * This is the primitive that fixes that. It binds the site, applies the theme
 * and per-site runtime config, runs the callback, then restores whatever was
 * bound before — so it nests safely and cannot leak context into later work.
 *
 *   Tenancy::for($site, fn () => $this->sync());     // one tenant
 *   Tenancy::each(fn (Site $site) => $this->sync()); // every active tenant
 */
class Tenancy
{
    /**
     * A raw query builder that respects the tenant, for the tables whose
     * models use BelongsToSite.
     *
     * DB::table() goes straight to the query builder and never applies
     * Eloquent global scopes, so every raw `DB::table('gsc_query_metrics')`
     * in the app was reading EVERY tenant's rows. That is how the admin's SEO
     * Reports page rendered gs.construction's pages, queries and coverage
     * counts inside J. Peterson Design's admin: the Eloquent half was scoped
     * correctly and the raw half was not.
     *
     * Predicate is identical to App\Models\Concerns\SiteScope — including the
     * NULL allowance, so a partially-backfilled table behaves the same through
     * both paths.
     */
    public static function table(string $table): \Illuminate\Database\Query\Builder
    {
        $siteId = Site::current()->id;

        // Accepts "table" and "table as alias" — the predicate must qualify
        // with the ALIAS when one is given, or MySQL rejects the column.
        $qualifier = preg_split('/\s+as\s+/i', trim($table))[1] ?? $table;

        return \Illuminate\Support\Facades\DB::table($table)
            ->where(function ($q) use ($qualifier, $siteId) {
                $q->where($qualifier . '.site_id', $siteId)
                    ->orWhereNull($qualifier . '.site_id');
            });
    }

    /** The current tenant's id, for hand-written SQL that cannot use table(). */
    public static function currentId(): int
    {
        return (int) Site::current()->id;
    }

    /**
     * Namespace a cache key to the current tenant.
     *
     * A literal key like 'admin.seo-reports.health-snapshot' is filled by
     * whichever tenant renders first and then served to every other one — so
     * J. Peterson Design's admin showed gs.construction's SEO Health score,
     * pillars and ranking distribution even after the queries themselves were
     * correctly scoped. Cached tenant data needs the tenant in the key.
     *
     * Default site keeps the bare key so existing warm caches stay valid and
     * gs.construction sees no cold-start after deploy.
     */
    public static function cacheKey(string $key): string
    {
        $slug = Site::current()->slug;

        return $slug === (string) config('sites.default', 'gsc')
            ? $key
            : $slug . ':' . $key;
    }

    /** Run $callback with $site bound as the current tenant. */
    public static function for(Site $site, Closure $callback): mixed
    {
        $previous = app()->bound('site.current') ? app('site.current') : null;

        self::bind($site);

        try {
            return $callback($site);
        } finally {
            // Restore rather than clear: a caller may itself be inside a
            // Tenancy::for() block, and dropping context would silently
            // fall back to the default site for the rest of its work.
            if ($previous instanceof Site) {
                self::bind($previous);
            } else {
                app()->forgetInstance('site.current');
                SiteConfig::flush();
                // Put the runtime config back to shared defaults, or the last
                // tenant's overrides would outlive the block.
                SiteConfig::restore();
            }
        }
    }

    /**
     * Run $callback once per site. Defaults to active sites only — an inactive
     * site's domain does not resolve publicly, so ingesting or publishing for
     * it would produce data for a site nobody can reach.
     *
     * @return array<string, mixed> keyed by site slug
     */
    public static function each(Closure $callback, bool $includeInactive = false): array
    {
        $results = [];

        foreach (self::sites($includeInactive) as $site) {
            $results[$site->slug] = self::for($site, $callback);
        }

        return $results;
    }

    /** @return Collection<int, Site> */
    public static function sites(bool $includeInactive = false): Collection
    {
        return Site::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolve a site from a CLI-friendly key: slug, primary host, or id.
     * Returns null when not found so callers can fail loudly with context.
     */
    public static function find(string|int $key): ?Site
    {
        return Site::query()->where('slug', $key)->first()
            ?? Site::query()->where('primary_host', $key)->first()
            ?? Site::forHost((string) $key)
            ?? (is_numeric($key) ? Site::query()->find((int) $key) : null);
    }

    protected static function bind(Site $site): void
    {
        Site::setCurrent($site);
        SiteConfig::flush();
        Theme::apply($site);
        SiteConfig::applyRuntime($site);

        // Console and queue have no request, so url()/route() fall back to
        // config('app.url') — gs.construction for every tenant. Without this,
        // a sitemap, feed or email generated for site B is full of site A's
        // URLs, which is worse than failing outright because it looks fine.
        config(['app.url' => $site->url()]);
        URL::forceRootUrl($site->url());
    }
}
