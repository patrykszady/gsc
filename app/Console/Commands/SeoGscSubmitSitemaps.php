<?php

namespace App\Console\Commands;

use App\Services\GoogleSearchConsoleService;
use App\Support\Seo\SearchConsoleProperty;
use Illuminate\Console\Command;
use SsSystems\Platform\Seo\SitemapStatus;
use SsSystems\Platform\Seo\SitemapSubmitter;

/**
 * Submit the sitemaps to Google via the Search Console API.
 *
 * Google retired the /ping endpoint in June 2023, and IndexNow never reaches
 * Google — so after sitemap:generate rewrites the file, nothing told Google to
 * come back for it and the Sitemaps report routinely showed reads 3–6 days
 * stale. sitemaps.submit is the supported nudge; submitting an
 * already-registered sitemap simply schedules a re-fetch.
 *
 * A thin wrapper over the kit's SsSystems\Platform\Seo\SitemapSubmitter —
 * the submit-in-order-stop-on-a-standing-condition loop moved there, this
 * command keeps only what is genuinely this site's own: which property/base
 * URL to use, and printing the result the same way it always has.
 */
class SeoGscSubmitSitemaps extends Command
{
    protected $signature = 'seo:gsc-submit-sitemaps
        {--site= : GSC property override (defaults to services.google.search_console.site_url)}';

    protected $description = 'Submit sitemap.xml and image-sitemap.xml to Google Search Console (re-fetch nudge)';

    public function handle(GoogleSearchConsoleService $gsc): int
    {
        if (! $gsc->isConfigured()) {
            $this->warn('Search Console API not configured — skipping.');

            return self::SUCCESS;
        }

        $site = (string) ($this->option('site') ?: SearchConsoleProperty::url());
        $base = SearchConsoleProperty::baseUrl();

        $result = (new SitemapSubmitter($gsc))->submit($site, ["{$base}/sitemap.xml", "{$base}/image-sitemap.xml"]);

        foreach ($result->submitted as $sitemap) {
            $this->info("  submitted {$sitemap}");
        }

        // No token, 401, and 403 are all the same standing condition — the
        // one-time interactive `search-console:auth` hasn't been run (or
        // needs re-running) to grant the write scope. Exiting FAILURE here
        // made the nightly scheduler log an exception every day until that
        // happens; a standing condition is a warn-and-skip, not an incident.
        if ($result->authStandingSkip) {
            $this->warn("  {$result->authStandingSitemap}: {$result->authStandingMessage}");
            $this->warn('  Skipping until `php artisan search-console:auth` grants the write scope.');

            return self::SUCCESS;
        }

        foreach ($result->failures as $sitemap => $message) {
            $this->error("  {$sitemap}: {$message}");
        }

        // Warm the admin's Sitemaps card while we are already talking to
        // Google. Without this the first person to open the SEO screen after
        // the cache expires pays for a live sitemaps.list call inside their
        // page load — and that call can take the full 20s timeout, which the
        // central admin sees as the whole site API timing out.
        SitemapStatus::snapshot($site, fresh: true);

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }
}
