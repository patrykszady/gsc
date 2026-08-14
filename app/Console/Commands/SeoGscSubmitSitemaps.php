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

            $failures++;
            $err = $gsc->getLastError();
            $this->error("  {$sitemap}: " . ($err['message'] ?? 'unknown error'));

            // One 403 means the token lacks the scope; the second submit will
            // fail identically, so stop after reporting once.
            if (($err['status'] ?? null) === 403) {
                break;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
