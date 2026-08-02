<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Site extends Model
{
    protected $guarded = [];

    protected $casts = [
        'hosts' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * The site handling the current request, bound by ResolveSite middleware.
     * Falls back to the default site so console commands, queue jobs, and
     * tests that never pass through HTTP middleware still get a site.
     */
    public static function current(): self
    {
        if (app()->bound('site.current')) {
            return app('site.current');
        }

        // ResolveSite lives in the `web` middleware group, which only runs for
        // a MATCHED route. An unmatched URL throws NotFoundHttpException during
        // routing, so the 404 view used to render with the default site — every
        // tenant served GS Construction's 404, brand name, phone and all. Same
        // for any error page rendered outside the route pipeline.
        //
        // Falling back to the request host first fixes all of those at once,
        // without reordering middleware (ResolveSite must stay after
        // StartSession for the ?site= pin). Console and queue have no request,
        // so they still fall through to the default site as documented.
        $site = null;

        if (app()->bound('request')) {
            // Same order ResolveSite uses, so an error page resolves to the
            // same tenant the matched route would have.
            $host = app('request')->getHost();
            $site = static::forDevHost($host)
                ?? static::forHost($host)
                ?? static::forPreviewHost($host);
        }

        $site ??= static::default();
        app()->instance('site.current', $site);

        return $site;
    }

    /** Bind the current site for this request/process. */
    public static function setCurrent(self $site): void
    {
        app()->instance('site.current', $site);
    }

    public static function default(): self
    {
        return static::query()
            ->where('slug', (string) config('sites.default', 'gsc'))
            ->firstOrFail();
    }

    /**
     * Match a request host to a site. Comparison is case-insensitive and
     * ignores ports (127.0.0.1:8003) and a leading "www.".
     */
    public static function forHost(?string $host): ?self
    {
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(explode(':', $host)[0]);
        $bare = preg_replace('/^www\./', '', $host);

        return static::active()->first(function (self $site) use ($host, $bare) {
            foreach ((array) $site->hosts as $candidate) {
                $candidate = strtolower($candidate);
                if ($candidate === $host || preg_replace('/^www\./', '', $candidate) === $bare) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Match a LOCAL development host to a site: {slug}.localhost, {slug}.test.
     *
     * This is what makes local behave like production. The tenant is selected
     * from the Host header, so every link, form post, redirect, wire:navigate
     * fetch and Livewire update stays on the tenant for free — none of them
     * carry a query string, which is why the older ?site= override only ever
     * survived a single request.
     *
     * Deliberately does NOT filter on is_active and does NOT go through
     * active(): a theme has to be previewable before its site launches, and
     * jpeterson is is_active=false until then.
     *
     * The environment gate on line one is the entire production-safety
     * surface. Outside local this returns null before touching the request,
     * so production tenant selection is byte-for-byte what it was. `.localhost`
     * and `.test` are RFC 6761/6762 reserved and cannot be delegated, so even
     * a defeated gate could not collide with a real tenant's hostname.
     */
    public static function forDevHost(?string $host): ?self
    {
        if (! app()->environment('local') || ! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(explode(':', $host)[0]);

        foreach (['.localhost', '.test'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return static::query()
                    ->where('slug', substr($host, 0, -strlen($suffix)))
                    ->first();
            }
        }

        return null;
    }

    /** The local host this site is reachable at: "jpeterson.localhost". */
    public function devHost(): string
    {
        return $this->slug . '.localhost';
    }

    /**
     * Match a PREVIEW host — a real hostname that reaches this site even while
     * is_active is false.
     *
     * forHost() searches active sites only, which is correct for production:
     * an unlaunched tenant must not answer on a live domain. But it also means
     * a site in build cannot be shown to its client over a real URL, because
     * the tunnel hostname resolves to the default site and silently serves
     * gs.construction instead.
     *
     * Preview hosts are explicit opt-in data — a host only matches if someone
     * put it in that site's settings — so this cannot widen production
     * resolution by accident. Responses on a preview host are forced
     * noindex by NoIndexNonProduction regardless of environment.
     *
     * @return array{0: self, 1: string}|array{0: null, 1: null}
     */
    public static function forPreviewHost(?string $host): ?self
    {
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(explode(':', $host)[0]);

        return static::listAll()->first(function (self $site) use ($host): bool {
            foreach ((array) $site->setting('preview_hosts', []) as $candidate) {
                if (strtolower((string) $candidate) === $host) {
                    return true;
                }
            }

            return false;
        });
    }

    /** @return array<int, string> */
    public function previewHosts(): array
    {
        return array_values((array) $this->setting('preview_hosts', []));
    }

    /** @return Collection<int, self> */
    public static function active(): Collection
    {
        // Sites are tiny and read on every request; cache per-process.
        static $sites = null;

        return $sites ??= static::query()->where('is_active', true)->get();
    }

    /**
     * Every site, launched or not — for the dev switcher and sites:check,
     * which must show a tenant that is still in build.
     *
     * @return Collection<int, self>
     */
    public static function listAll(): Collection
    {
        static $sites = null;

        return $sites ??= static::query()
            ->orderByDesc('is_active')
            ->orderBy('slug')
            ->get();
    }

    /** Per-site setting with dot access: $site->setting('seo.gsc_property'). */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function url(string $path = ''): string
    {
        return 'https://' . $this->primary_host . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}
