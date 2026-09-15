<?php

namespace App\Support\Seo;

use App\Models\Site;
use Illuminate\Http\Response;

/**
 * The crawl files a site publishes — robots.txt, sitemap.xml,
 * image-sitemap.xml — one set per tenant.
 *
 * They used to be static files under public/, which nginx serves for every
 * host of this deployment: J. Peterson Design's domain handed out
 * gs.construction's robots rules and gs.construction's sitemap. Now each
 * site's sitemaps live under storage/app/private/tenants/{slug}/ and a route
 * serves the current tenant's; robots.txt is rendered from
 * resources/robots/{slug}.txt (default.txt when a site has none) with the
 * Sitemap lines pointed at that site's own host.
 */
final class CrawlFiles
{
    public static function dir(?Site $site = null): string
    {
        $site ??= Site::current();

        return storage_path("app/private/tenants/{$site->slug}");
    }

    public static function sitemapPath(?Site $site = null): string
    {
        return self::dir($site).'/sitemap.xml';
    }

    public static function imageSitemapPath(?Site $site = null): string
    {
        return self::dir($site).'/image-sitemap.xml';
    }

    /** The robots.txt this site publishes, rendered for its own host. */
    public static function robots(?Site $site = null): string
    {
        $site ??= Site::current();
        $template = (string) file_get_contents(self::robotsSourcePath($site));

        return str_replace('{{base}}', rtrim($site->url(), '/'), $template);
    }

    public static function robotsSourcePath(?Site $site = null): string
    {
        $site ??= Site::current();
        $own = resource_path("robots/{$site->slug}.txt");

        return is_file($own) ? $own : resource_path('robots/default.txt');
    }

    /** Serve a generated sitemap, or say plainly that it has not been generated for this site. */
    public static function serve(string $path): Response
    {
        if (! is_file($path)) {
            return response('Not generated yet for this site — run: php artisan tenants:run sitemap:generate --site='.Site::current()->slug."\n", 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // A plain response, not a streamed file: sitemaps are small, and the
        // body stays readable to the test client and to any middleware.
        return response((string) file_get_contents($path), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
