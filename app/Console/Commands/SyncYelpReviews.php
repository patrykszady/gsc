<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SyncYelpReviews extends Command
{
    protected $signature = 'testimonials:sync-yelp-reviews
        {--url= : Yelp business page URL (defaults to config)}
        {--max-pages=10 : Maximum review-feed pages to fetch (10 reviews per page)}
        {--only-new : Only create new reviews; skip updating already matched reviews}
        {--dry-run : Show what would change without writing to DB}';

    protected $description = 'Sync Yelp reviews by reading the public review feed through the residential proxy, match existing testimonials, and create/update records.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $maxPages = max(1, (int) $this->option('max-pages'));
        $onlyNew = (bool) $this->option('only-new');
        $pageUrl = (string) ($this->option('url') ?: config('socials.yelp.url', 'https://www.yelp.com/biz/gs-construction-chicago-2'));

        $this->info('Fetching Yelp reviews via the review feed…');
        $reviews = $this->fetchFromYelp($pageUrl, $maxPages);
        $this->info('Fetched '.count($reviews).' review(s).');

        if (empty($reviews)) {
            $this->warn('No Yelp reviews were discovered.');

            return self::SUCCESS;
        }

        $stats = [
            'created' => 0,
            'matched_url' => 0,
            'matched_content' => 0,
            'matched_name_date' => 0,
            'skipped_existing' => 0,
            'updated' => 0,
        ];

        $existing = Testimonial::with('reviewUrls')->get();
        $seenPayloadKeys = [];

        foreach ($reviews as $rawReview) {
            $payload = $this->normalizePayload($rawReview);
            if (! $payload) {
                continue;
            }

            $payloadKey = $this->payloadKey($payload['reviewer_name'], $payload['review_description'], $payload['review_date']);
            if (isset($seenPayloadKeys[$payloadKey])) {
                continue;
            }
            $seenPayloadKeys[$payloadKey] = true;

            $reviewUrlValue = $payload['url'] ?: null;

            // Match by Yelp URL
            $matchedByUrl = null;
            if ($reviewUrlValue) {
                $matchedByUrl = $existing->first(function (Testimonial $t) use ($reviewUrlValue) {
                    return $t->reviewUrls->contains(function ($url) use ($reviewUrlValue) {
                        return $url->platform === 'yelp' && $this->isSameYelpReviewUrl($url->url, $reviewUrlValue);
                    });
                });
            }

            if ($matchedByUrl) {
                $stats['matched_url']++;
                if ($onlyNew) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $stats['updated'] += $this->upsertIntoExisting($matchedByUrl, $payload, $reviewUrlValue, $dryRun);
                continue;
            }

            // Match by review content
            $matchedByContent = $existing->first(function (Testimonial $t) use ($payload) {
                return $this->normalizeForComparison($t->review_description) === $this->normalizeForComparison($payload['review_description']);
            });

            if ($matchedByContent) {
                $stats['matched_content']++;
                if ($onlyNew) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $stats['updated'] += $this->upsertIntoExisting($matchedByContent, $payload, $reviewUrlValue, $dryRun);
                continue;
            }

            // Match by name + date (exact, then fuzzy by initials)
            $matchedByNameDate = $existing->first(function (Testimonial $t) use ($payload) {
                $sameDate = ($t->review_date?->toDateString() ?? null) === ($payload['review_date']?->toDateString() ?? null);
                if (! $sameDate || ! $payload['review_date']) {
                    return false;
                }
                $sameName = mb_strtolower(trim($t->reviewer_name)) === mb_strtolower(trim($payload['reviewer_name']));

                return $sameName || $this->nameInitialsMatch($t->reviewer_name, $payload['reviewer_name']);
            });

            if ($matchedByNameDate) {
                $stats['matched_name_date']++;
                if ($onlyNew) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $stats['updated'] += $this->upsertIntoExisting($matchedByNameDate, $payload, $reviewUrlValue, $dryRun);
                continue;
            }

            // Create new testimonial
            if ($dryRun) {
                $dateLabel = $payload['review_date'] ? $payload['review_date']->toDateString() : 'no-date';
                $this->line("[DRY RUN] Create: {$payload['reviewer_name']} ({$dateLabel})");
                $stats['created']++;
                continue;
            }

            $testimonial = Testimonial::create([
                'reviewer_name' => $payload['reviewer_name'],
                'review_description' => $payload['review_description'],
                'review_date' => $payload['review_date'],
                'star_rating' => $payload['star_rating'],
            ]);

            if ($reviewUrlValue) {
                $testimonial->reviewUrls()->create([
                    'platform' => 'yelp',
                    'url' => $reviewUrlValue,
                ]);
            }

            $existing->push($testimonial->load('reviewUrls'));
            $stats['created']++;
            $this->line("Created: #{$testimonial->id} {$payload['reviewer_name']}");
        }

        $this->newLine();
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info($prefix.'Summary');
        $this->line('  Reviews scraped: '.count($reviews));
        $this->line('  Created: '.$stats['created']);
        $this->line('  Matched by Yelp URL: '.$stats['matched_url']);
        $this->line('  Matched by content: '.$stats['matched_content']);
        $this->line('  Matched by name+date: '.$stats['matched_name_date']);
        $this->line('  Skipped existing (only-new): '.$stats['skipped_existing']);
        $this->line('  Updated existing: '.$stats['updated']);

        return self::SUCCESS;
    }

    /**
     * Fetch reviews from Yelp's public review-feed JSON endpoint (the same one
     * the Yelp frontend paginates with), routed through the residential proxy
     * used by the rest of the Yelp stack. Falls back to the JSON-LD embedded
     * in the business page when the feed is blocked.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchFromYelp(string $businessUrl, int $maxPages): array
    {
        $path = (string) parse_url($businessUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = $segments[1] ?? null;

        if (! $slug) {
            $this->error('Could not extract Yelp business slug from URL: '.$businessUrl);

            return [];
        }

        $all = [];
        $seenKeys = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $start = $page * 10; // review_feed pages are fixed at 10

            try {
                $response = $this->yelpHttp($businessUrl)
                    ->get("https://www.yelp.com/biz/{$slug}/review_feed", [
                        'rl' => 'en',
                        'q' => '',
                        'sort_by' => 'date_desc',
                        'start' => $start,
                    ]);
            } catch (\Throwable $e) {
                $this->warn('Yelp review feed request failed: '.$e->getMessage());
                break;
            }

            if (! $response->successful()) {
                // 403 here is DataDome — the browser scraper below handles it.
                $this->warn('Yelp review feed HTTP '.$response->status().' on page '.($page + 1).'.');
                break;
            }

            $reviews = (array) $response->json('reviews', []);
            if (empty($reviews)) {
                break;
            }

            foreach ($reviews as $review) {
                if (! is_array($review)) {
                    continue;
                }

                $name = trim((string) data_get($review, 'user.markupDisplayName', data_get($review, 'user.displayName', '')));
                $description = $this->cleanReviewHtml((string) data_get($review, 'comment.text', ''));
                $dateRaw = trim((string) data_get($review, 'localizedDate', ''));
                $rating = data_get($review, 'rating');
                $rating = is_numeric($rating) ? (int) $rating : null;
                $reviewId = (string) data_get($review, 'id', '');
                $reviewUrl = $reviewId !== '' ? rtrim($businessUrl, '/').'?hrid='.urlencode($reviewId) : null;

                if ($name === '' || $description === '') {
                    continue;
                }

                $fingerprint = mb_strtolower($name).'|'.$dateRaw.'|'.$this->normalizeForComparison($description, 200);
                if (isset($seenKeys[$fingerprint])) {
                    continue;
                }
                $seenKeys[$fingerprint] = true;

                $all[] = [
                    'reviewer_name' => $name,
                    'review_description' => $description,
                    'review_date_raw' => $dateRaw,
                    'star_rating' => $rating,
                    'url' => $reviewUrl,
                ];
            }

            if (count($reviews) < 10) {
                break;
            }

            usleep(1_500_000); // pace page requests like a human scroller
        }

        if (! empty($all)) {
            return $all;
        }

        // Primary heavy path: the stealth Puppeteer scraper
        // (scripts/scrape-yelp-reviews.mjs) — solves DataDome via 2captcha and
        // paginates the visible review list like a real browser.
        $this->line('Plain HTTP blocked — launching browser scraper…');
        $all = $this->runBrowserScraper($businessUrl, $maxPages);
        if (! empty($all)) {
            return $all;
        }

        $this->warn('Browser scraper returned nothing — falling back to business-page JSON-LD.');

        return $this->fetchFromJsonLd($businessUrl);
    }

    /**
     * Run scripts/scrape-yelp-reviews.mjs (stealth Puppeteer + DataDome/2captcha
     * handling, same stack as the Yelp photo uploads) and decode its JSON output.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runBrowserScraper(string $businessUrl, int $maxPages): array
    {
        $script = base_path('scripts/scrape-yelp-reviews.mjs');
        if (! is_file($script)) {
            $this->warn('scripts/scrape-yelp-reviews.mjs not found.');

            return [];
        }

        $cmd = ['node', $script, '--url='.$businessUrl, '--max-pages='.$maxPages, '--timeout-ms=180000'];
        if ($proxy = (string) (config('services.yelp.business.proxy') ?: config('services.scraper.proxy') ?: '')) {
            $cmd[] = '--proxy='.$proxy;
        }
        if ($captchaKey = (string) config('services.twocaptcha.api_key', '')) {
            $cmd[] = '--twocaptcha-key='.$captchaKey;
        }

        $realHome = (string) (getenv('HOME') ?: '/home/'.get_current_user());
        $process = new \Symfony\Component\Process\Process($cmd, base_path(), [
            'HOME' => $realHome,
            'PUPPETEER_CACHE_DIR' => (string) (config('services.yelp.business.puppeteer_cache_dir') ?: $realHome.'/.cache/puppeteer'),
        ], null, 300);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->warn('Browser scraper failed to run: '.$e->getMessage());

            return [];
        }

        if (! $process->isSuccessful()) {
            $this->warn('Browser scraper exited '.$process->getExitCode().': '.mb_substr(trim($process->getErrorOutput()), -300));

            return [];
        }

        $json = json_decode(trim($process->getOutput()), true);

        return is_array($json) ? (array) ($json['reviews'] ?? []) : [];
    }

    /**
     * Fallback: parse the schema.org JSON-LD block Yelp embeds on the business
     * page. Yields fewer reviews (typically the visible page) and no review
     * URLs, but keeps the sync alive if the feed endpoint is blocked.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchFromJsonLd(string $businessUrl): array
    {
        $response = $this->yelpHttp($businessUrl, acceptJson: false)->get($businessUrl);
        if (! $response->successful()) {
            $this->warn('Yelp business page HTTP '.$response->status().'.');

            return [];
        }

        if (! preg_match_all('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/si', (string) $response->body(), $m)) {
            return [];
        }

        $all = [];
        foreach ($m[1] as $blob) {
            $json = json_decode(html_entity_decode($blob, ENT_QUOTES | ENT_HTML5), true);
            if (! is_array($json)) {
                continue;
            }
            foreach ((array) data_get($json, 'review', []) as $review) {
                $name = trim((string) data_get($review, 'author.name', data_get($review, 'author', '')));
                $description = trim((string) data_get($review, 'description', ''));
                if ($name === '' || $description === '') {
                    continue;
                }
                $all[] = [
                    'reviewer_name' => $name,
                    'review_description' => $description,
                    'review_date_raw' => trim((string) data_get($review, 'datePublished', '')),
                    'star_rating' => (int) data_get($review, 'reviewRating.ratingValue', 0) ?: null,
                    'url' => null,
                ];
            }
        }

        return $all;
    }

    /**
     * HTTP client tuned for Yelp: residential proxy (2captcha rotating — the
     * same source the Yelp photo stack uses), browser-like headers, and retry
     * on transient connection blips.
     */
    private function yelpHttp(string $businessUrl, bool $acceptJson = true): \Illuminate\Http\Client\PendingRequest
    {
        $proxy = (string) (config('services.yelp.business.proxy') ?: config('services.scraper.proxy') ?: '');

        $client = Http::timeout(45)
            ->retry(3, 2000, fn ($exception) => $exception instanceof ConnectionException, throw: false)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => $acceptJson ? 'application/json, text/plain, */*' : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => $businessUrl,
                'X-Requested-With' => $acceptJson ? 'XMLHttpRequest' : '',
            ]);

        if ($proxy !== '') {
            $client = $client->withOptions(['proxy' => $proxy]);
        }

        return $client;
    }

    /** Strip the HTML Yelp embeds in comment.text (<br>, encoded entities). */
    private function cleanReviewHtml(string $html): string
    {
        $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }

    /**
     * @return array{reviewer_name:string, review_description:string, review_date:?Carbon, star_rating:?int, url:?string}|null
     */
    private function normalizePayload(array $raw): ?array
    {
        $name = trim((string) ($raw['reviewer_name'] ?? ''));
        $description = trim((string) ($raw['review_description'] ?? ''));
        if ($name === '' || $description === '' || mb_strlen($description) < 15) {
            return null;
        }

        $reviewDate = null;
        $dateRaw = trim((string) ($raw['review_date_raw'] ?? ''));
        if ($dateRaw !== '') {
            try {
                $reviewDate = Carbon::parse($dateRaw)->startOfDay();
            } catch (\Throwable) {
                $reviewDate = null;
            }
        }

        $starRating = isset($raw['star_rating']) ? (int) $raw['star_rating'] : null;
        if ($starRating !== null && ($starRating < 1 || $starRating > 5)) {
            $starRating = null;
        }

        $url = null;
        if (! empty($raw['url']) && is_string($raw['url'])) {
            $url = $raw['url'];
        }

        return [
            'reviewer_name' => $name,
            'review_description' => $description,
            'review_date' => $reviewDate,
            'star_rating' => $starRating,
            'url' => $url,
        ];
    }

    private function upsertIntoExisting(Testimonial $testimonial, array $payload, ?string $reviewUrl, bool $dryRun): int
    {
        $changed = 0;

        if ($dryRun) {
            $target = $reviewUrl ?? '[no url]';
            $this->line("[DRY RUN] Match: #{$testimonial->id} {$testimonial->reviewer_name} <- {$target}");

            return 1;
        }

        $update = [];

        if ($payload['review_date'] && (! $testimonial->review_date || ! $testimonial->review_date->isSameDay($payload['review_date']))) {
            $update['review_date'] = $payload['review_date'];
        }

        if ($payload['star_rating'] && $testimonial->star_rating !== $payload['star_rating']) {
            $update['star_rating'] = $payload['star_rating'];
        }

        $existingDescription = (string) $testimonial->review_description;
        $incomingDescription = (string) $payload['review_description'];

        // Yelp is the source of truth for review content.
        if (trim($incomingDescription) !== '' && trim($existingDescription) !== trim($incomingDescription)) {
            $update['review_description'] = $incomingDescription;
        }

        if (! empty($update)) {
            $testimonial->update($update);
            $changed++;
        }

        if ($reviewUrl) {
            $existingYelp = $testimonial->reviewUrls()->where('platform', 'yelp')->first();
            if (! $existingYelp) {
                $testimonial->reviewUrls()->create([
                    'platform' => 'yelp',
                    'url' => $reviewUrl,
                ]);
                $changed++;
            } elseif (! $this->isSameYelpReviewUrl($existingYelp->url, $reviewUrl)) {
                $existingYelp->update(['url' => $reviewUrl]);
                $changed++;
            }
        }

        if ($changed > 0) {
            $this->line("Updated: #{$testimonial->id} {$testimonial->reviewer_name}");
        }

        return $changed;
    }

    private function extractYelpReviewId(string $url): ?string
    {
        if (preg_match('/[?&]hrid=([a-zA-Z0-9_-]+)/i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private function isSameYelpReviewUrl(string $a, string $b): bool
    {
        $aId = $this->extractYelpReviewId($a);
        $bId = $this->extractYelpReviewId($b);

        if ($aId && $bId) {
            return $aId === $bId;
        }

        return $a === $b;
    }

    /**
     * Match names like "Bea B." to "Barbara Brunka" by comparing first-letter initials
     * of the first and last tokens. Date equality is enforced by the caller.
     */
    private function nameInitialsMatch(string $a, string $b): bool
    {
        $tokens = function (string $s): array {
            $s = preg_replace('/[^a-zA-Z\s]/', ' ', $s) ?? $s;
            $parts = preg_split('/\s+/', trim(mb_strtolower($s))) ?: [];

            return array_values(array_filter($parts, fn ($p) => $p !== ''));
        };

        $aTokens = $tokens($a);
        $bTokens = $tokens($b);

        if (count($aTokens) < 2 || count($bTokens) < 2) {
            return false;
        }

        $aFirst = $aTokens[0][0] ?? '';
        $aLast = $aTokens[count($aTokens) - 1][0] ?? '';
        $bFirst = $bTokens[0][0] ?? '';
        $bLast = $bTokens[count($bTokens) - 1][0] ?? '';

        if ($aFirst === '' || $aLast === '' || $bFirst === '' || $bLast === '') {
            return false;
        }

        return $aFirst === $bFirst && $aLast === $bLast;
    }

    private function payloadKey(string $name, string $description, ?Carbon $date): string
    {
        return mb_strtolower(trim($name)).'|'.($date?->toDateString() ?? 'no-date').'|'.$this->normalizeForComparison($description, 160);
    }

    private function normalizeForComparison(string $text, int $length = 120): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = preg_replace('/[^a-zA-Z0-9 ]/', '', $text);
        $text = mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));

        return mb_substr($text, 0, $length);
    }
}
