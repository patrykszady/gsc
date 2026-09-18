<?php

namespace App\Support\Seo;

use App\Models\BlogPost;
use App\Models\Site;
use App\Support\ExclusivePaths;
use App\Support\SiteConfig;
use App\Support\Tenancy;

/**
 * Whether a candidate URL belongs in THIS tenant's sitemap.
 *
 * GenerateSitemap discovers pages from things that are shared by every site:
 * the one global route table, and config files like remodel-costs or
 * design-partners that only gs.construction actually fills. The only tenant
 * question it asked was ExclusivePaths, which reasons about path prefixes —
 * and a prefix can have two owners. So J. Peterson Design's sitemap carried
 * six /services/{slug} URLs that 404 on her own site, because 'services' is
 * claimed by both tenants, while /portfolio and /testimonials — real pages of
 * hers — were missing, because they are legacy redirects on gs.construction
 * and were excluded for everybody.
 *
 * The gates here answer the narrower question the prefix cannot: does this
 * tenant have the CONTENT behind the path. Every gate is structural — config
 * a site overrides, rows a site owns, links a site's own nav declares — so
 * nothing is dispatched and the nightly run stays as cheap as it was.
 *
 * For the default site every gate is open by construction: SiteConfig::owns()
 * is true for it, the shared config is its config, and the aliases below are
 * its own redirects. gs.construction's sitemap is unchanged, which the
 * baseline fixture test pins.
 */
final class SitemapTenantFilter
{
    /**
     * Paths that are a real page on one tenant and a legacy redirect on
     * another. The route table cannot tell them apart: on gs.construction the
     * 301 comes from RedirectLegacyUrls, not from the route's action, so this
     * was hardcoded as "never" and took the tenants that really serve it with
     * it. A tenant serves one of these when its own nav links it.
     */
    public const SHARED_ALIASES = ['portfolio', 'testimonials'];

    /**
     * Page families whose content lives in a shared config file. A tenant gets
     * them only if it overrides that file — otherwise the page renders
     * gs.construction's material, or nothing, under another business's name.
     *
     * @var array<string, string> first path segment => config file
     */
    public const CONFIG_BACKED = [
        'compare' => 'competitors',
        'costs' => 'remodel-costs',
        'insurance-claims' => 'insurance-claims',
        'trades' => 'trades',
        'permits' => 'permit-guides',
        'design-partners' => 'design-partners',
    ];

    public static function allows(string $path, ?Site $site = null): bool
    {
        $site ??= Site::current();
        $path = trim(parse_url($path, PHP_URL_PATH) ?: $path, '/');

        if ($path === '') {
            return true;
        }

        if (! ExclusivePaths::allows($site->slug, $path)) {
            return false;
        }

        $segments = explode('/', $path);

        if (in_array($path, self::SHARED_ALIASES, true)) {
            return self::navLinks($path, $site);
        }

        // /services is the index every site with services has; /services/{slug}
        // needs that slug in the tenant's own services-content overlay.
        if ($segments[0] === 'services' && count($segments) > 1) {
            return SiteConfig::owns('services-content.services.'.$segments[1], $site);
        }

        if (isset(self::CONFIG_BACKED[$segments[0]])) {
            return SiteConfig::owns(self::CONFIG_BACKED[$segments[0]], $site);
        }

        // The blog index is worth advertising only where there is something to
        // read; posts are site-scoped, so this counts that tenant's own.
        if ($segments[0] === 'blog' && count($segments) === 1) {
            return self::asSite($site, fn (): bool => BlogPost::query()->published()->exists());
        }

        return true;
    }

    /** Does this tenant's own navigation link this path? */
    private static function navLinks(string $path, Site $site): bool
    {
        $links = self::asSite($site, fn (): array => (array) config('nav.links', []));

        foreach ($links as $link) {
            if (trim((string) ($link['href'] ?? ''), '/') === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read config and site-scoped rows as that tenant.
     *
     * The command already runs inside the tenant it is generating for, so this
     * is a no-op there. It matters when something asks about another site —
     * a test, or sites:check — where the ambient config would otherwise be
     * the default site's and answer for the wrong business.
     *
     * @template T
     *
     * @param  \Closure(): T  $read
     * @return T
     */
    private static function asSite(Site $site, \Closure $read): mixed
    {
        if (Site::current()->getKey() === $site->getKey()) {
            return $read();
        }

        return Tenancy::for($site, fn () => $read());
    }
}
