<?php

namespace App\Console\Commands;

use App\Services\GoogleSearchConsoleService;
use Illuminate\Console\Command;

/**
 * Submit the sitemaps to Google via the Search Console API.
 *
 * Google retired the /ping endpoint in June 2023, and IndexNow never reaches
 * Google — so after sitemap:generate rewrites the file, nothing told Google to
 * come back for it and the Sitemaps report routinely showed reads 3–6 days
 * stale. sitemaps.submit is the supported nudge; submitting an
 * already-registered sitemap simply schedules a re-fetch.
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

        $site = (string) ($this->option('site') ?: config('services.google.search_console.site_url'));
        $base = rtrim((string) config('app.url'), '/');

        $failures = 0;
        foreach (["{$base}/sitemap.xml", "{$base}/image-sitemap.xml"] as $sitemap) {
            if ($gsc->submitSitemap($site, $sitemap)) {
                $this->info("  submitted {$sitemap}");

                continue;
            }

            $err = $gsc->getLastError();

            // No token, 401, and 403 are all the same standing condition —
            // the one-time interactive `search-console:auth` hasn't been run
            // (or needs re-running) to grant the write scope. Exiting FAILURE
            // here made the nightly scheduler log an exception every day
            // until that happens; a standing condition is a warn-and-skip,
            // not an incident. The second submit would fail identically.
            $status = $err['status'] ?? null;
            $needsAuth = in_array($status, [401, 403], true)
                || str_contains((string) ($err['message'] ?? ''), 'search-console:auth');

            if ($needsAuth) {
                $this->warn("  {$sitemap}: " . ($err['message'] ?? 'not authorized'));
                $this->warn('  Skipping until `php artisan search-console:auth` grants the write scope.');

                return self::SUCCESS;
            }

            $failures++;
            $this->error("  {$sitemap}: " . ($err['message'] ?? 'unknown error'));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
