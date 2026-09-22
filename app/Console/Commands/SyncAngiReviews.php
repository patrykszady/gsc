<?php

namespace App\Console\Commands;

use App\Models\ReviewUrl;
use App\Models\Site;
use App\Models\Testimonial;
use App\Support\Reviews\AngiReviews;
use SsSystems\Platform\Reviews\AngiScraper;
use SsSystems\Platform\Reviews\ReviewText;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Import the reviews on this site's Angi profile.
 *
 * Angi has no API and no per-review permalink, so the scraper that ships
 * with ss-systems/platform-kit reads the profile page in a real browser on a
 * virtual display, and each imported review links back to the profile. The
 * browser, the page's markup and the words for each failure live in the
 * package (`AngiScraper`), the text rules in `ReviewText`; what stays here
 * is this site's own: where its profile URL and brand name come from, its
 * Chrome profile, and the testimonials it writes.
 *
 * A scraped review is matched against the testimonials we already have —
 * same text, or same reviewer and date — so a review left on several sites
 * is stored once. An unmatched review is created; a matched one keeps the
 * text it already has and simply gains an Angi citation, since it is on
 * Angi too.
 */
class SyncAngiReviews extends Command
{
    protected $signature = 'testimonials:sync-angi-reviews
        {--profile-url= : Angi profile URL (default: the site setting from Admin → Social Media)}
        {--max-pages=10 : How many review pages to walk at most}
        {--timeout-ms=90000 : Per-page navigation timeout}
        {--proxy= : Residential proxy URL to fall back to (default: the configured scraper proxy)}
        {--proxy-region= : Country the proxy sessions exit from (default: services.scraper.proxy_region, "us")}
        {--direct-only : Never fall back to the residential proxy}
        {--skip-direct : Go straight to the residential proxy — for a server Angi is known to refuse}
        {--headless : Run without a virtual display (Angi usually blocks this)}
        {--from-json= : Read scraper output from this JSON file instead of running a browser}
        {--dry-run : Show what would change without writing to the database}';

    protected $description = 'Scrape the Angi profile reviews and add new ones as testimonials.';

    /**
     * Angi publishes no per-review permalink and no edit history, so nothing
     * anchors an update: a matched review is left exactly as it is, and only
     * unseen ones are created.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            return $this->sync($dryRun);
        } catch (\Throwable $e) {
            if (! $dryRun) {
                AngiReviews::recordRun([], mb_substr($e->getMessage(), 0, 300));
            }

            throw $e;
        }
    }

    private function sync(bool $dryRun): int
    {
        $profileUrl = (string) ($this->option('profile-url') ?: AngiReviews::profileUrl() ?? '');
        if ($profileUrl === '') {
            $this->warn('No Angi profile URL is configured for '.Site::current()->name.'. Add it under Admin → Social Media → profile links.');
            if (! $dryRun) {
                AngiReviews::recordRun([], 'No Angi profile URL configured.');
            }

            return self::FAILURE;
        }

        $this->info('Reading '.$profileUrl);
        $scraped = ($fromJson = (string) $this->option('from-json')) !== ''
            ? $this->readScrapedJson($fromJson)
            : $this->scraper()->run($profileUrl, fn (string $line) => $this->line('  <comment>[scraper]</comment> '.$line));

        if ($error = ($scraped['error'] ?? null)) {
            $message = AngiScraper::explain($error, $scraped['business_name'] ?? null, AngiReviews::brandName());
            $this->warn($message);
            if (! $dryRun) {
                AngiReviews::recordRun(['scraped' => 0, 'created' => 0], $message);
            }

            return self::FAILURE;
        }

        // The scraper checks this too. Repeated here because the profile URL is
        // operator-supplied, and importing another business's reviews would be
        // worse than importing none.
        $businessName = (string) ($scraped['business_name'] ?? '');
        if ($businessName !== '' && ! AngiReviews::pageBelongsToBrand($businessName, AngiReviews::brandName())) {
            $message = AngiScraper::explain('wrong_business', $businessName, AngiReviews::brandName());
            $this->warn($message);
            if (! $dryRun) {
                AngiReviews::recordRun(['scraped' => 0, 'created' => 0], $message);
            }

            return self::FAILURE;
        }

        $payloads = collect($scraped['reviews'] ?? [])
            ->map(fn (array $review) => ReviewText::normalizeAngiReview($review))
            ->filter()
            ->values();

        $this->info('Scraped '.$payloads->count().' review(s) from '.($scraped['business_name'] ?: 'the profile').'.');

        $stats = ['created' => 0, 'matched' => 0, 'linked' => 0, 'failed_parse' => count($scraped['reviews'] ?? []) - $payloads->count()];
        $existing = Testimonial::with('reviewUrls')->get();
        $seen = [];

        foreach ($payloads as $payload) {
            $key = ReviewText::payloadKey($payload);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($match = $this->matchExisting($existing, $payload)) {
                $stats['matched']++;

                // We already hold this review from another site. Its text
                // stays as it is, but it IS on Angi, so it should cite Angi:
                // these rows are what the Platforms count and the public
                // citations read.
                if (! $match->reviewUrls->contains(fn (ReviewUrl $url) => $url->platform === 'angi')) {
                    $stats['linked']++;
                    if ($dryRun) {
                        $this->line("[DRY RUN] Cite Angi on: {$match->reviewer_name}");
                    } else {
                        $match->reviewUrls()->create(['platform' => 'angi', 'url' => $profileUrl]);
                        $match->load('reviewUrls');
                        $this->line("Cited Angi on: #{$match->id} {$match->reviewer_name}");
                    }
                }

                continue;
            }

            $label = $payload['reviewer_name'].' ('.($payload['review_date']?->toDateString() ?? 'no date').')';

            if ($dryRun) {
                $this->line("[DRY RUN] Create: {$label}");
                $stats['created']++;

                continue;
            }

            $testimonial = Testimonial::create([
                'reviewer_name' => $payload['reviewer_name'],
                'review_description' => $payload['review_description'],
                'review_date' => $payload['review_date'],
                'star_rating' => $payload['star_rating'],
            ]);
            // Angi has no per-review permalink; the profile page is the citation.
            $testimonial->reviewUrls()->create(['platform' => 'angi', 'url' => $profileUrl]);
            $existing->push($testimonial->load('reviewUrls'));
            $stats['created']++;
            $this->line("Created: #{$testimonial->id} {$label}");
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Summary');
        $this->line('  Scraped: '.$payloads->count());
        $this->line('  Created: '.$stats['created']);
        $this->line('  Already had it: '.$stats['matched'].' ('.$stats['linked'].' newly cited to Angi)');
        $this->line('  Unusable cards: '.$stats['failed_parse']);

        if (! $dryRun) {
            AngiReviews::recordRun([
                'scraped' => $payloads->count(),
                'created' => $stats['created'],
                'matched' => $stats['matched'],
                'linked' => $stats['linked'],
                'parse_failures' => $stats['failed_parse'],
            ]);
        }

        return self::SUCCESS;
    }

    /** The package's scraper, set up with this site's particulars and this run's options. */
    private function scraper(): AngiScraper
    {
        // Cloudflare refuses some addresses outright. The scraper tries this
        // server first and falls back to residential sessions.
        $proxy = (string) ($this->option('direct-only')
            ? ''
            : ($this->option('proxy') ?: config('services.scraper.proxy', '')));

        return new AngiScraper(
            brandName: AngiReviews::brandName(),
            chromeProfileDir: $this->chromeProfileDir(),
            nodeModules: base_path('node_modules'),
            proxy: $proxy !== '' ? $proxy : null,
            proxyRegion: (string) ($this->option('proxy-region') ?: config('services.scraper.proxy_region', AngiScraper::DEFAULT_PROXY_REGION)),
            skipDirect: (bool) $this->option('skip-direct') && $proxy !== '',
            headless: (bool) $this->option('headless'),
            maxPages: (int) $this->option('max-pages'),
            timeoutMs: (int) $this->option('timeout-ms'),
            xvfb: (string) config('services.scraper.xvfb', 'xvfb-run'),
            screen: (string) config('services.scraper.screen', '1440x2400x24'),
        );
    }

    /**
     * Same review, already stored? Angi gives no permalink, so this is text
     * first (a review syndicated from another site is word for word), then
     * reviewer and date.
     *
     * @param  Collection<int, Testimonial>  $existing
     * @param  array<string, mixed>  $payload
     */
    private function matchExisting($existing, array $payload): ?Testimonial
    {
        $byText = $existing->first(fn (Testimonial $t) => ReviewText::comparable($t->review_description) === ReviewText::comparable($payload['review_description']));
        if ($byText) {
            return $byText;
        }

        return $existing->first(function (Testimonial $t) use ($payload) {
            $sameName = mb_strtolower(trim((string) $t->reviewer_name)) === mb_strtolower(trim($payload['reviewer_name']));
            $sameDate = ($t->review_date?->toDateString() ?? null) === ($payload['review_date']?->toDateString() ?? null);

            return $sameName && $sameDate && $payload['review_date'] !== null;
        });
    }

    /**
     * Read a scraper payload captured earlier, so the matching can be
     * exercised without a browser.
     *
     * @return array<string, mixed>
     */
    private function readScrapedJson(string $path): array
    {
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded + ['reviews' => []] : ['error' => 'scraper_failed', 'reviews' => []];
    }

    /** Chromium's profile, per site: two tenants importing at once must not share one. */
    private function chromeProfileDir(): string
    {
        return storage_path('app/angi/chrome-profile/'.Site::current()->slug);
    }
}
