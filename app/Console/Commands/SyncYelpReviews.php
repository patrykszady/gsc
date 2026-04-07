<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncYelpReviews extends Command
{
    protected $signature = 'testimonials:sync-yelp-reviews
        {--url= : Yelp business page URL (defaults to config)}
        {--browser-scrape : Use Puppeteer to scrape reviews (required)}
        {--browser-headed : Run Puppeteer in headed mode}
        {--browser-timeout-ms=120000 : Timeout for browser scraping in milliseconds}
        {--max-pages=10 : Maximum number of review pages to scrape}
        {--proxy= : Residential proxy URL (e.g. http://user:pass@host:port)}
        {--only-new : Only create new reviews; skip updating already matched reviews}
        {--dry-run : Show what would change without writing to DB}';

    protected $description = 'Scrape Yelp reviews via Puppeteer + 2captcha DataDome solver, match existing testimonials, and create/update records.';

    private string $proxy = '';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $browserScrape = (bool) $this->option('browser-scrape');
        $browserHeaded = (bool) $this->option('browser-headed');
        $browserTimeoutMs = max(10000, (int) $this->option('browser-timeout-ms'));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $this->proxy = (string) ($this->option('proxy') ?: '');
        $onlyNew = (bool) $this->option('only-new');
        $pageUrl = (string) ($this->option('url') ?: config('socials.yelp.url', 'https://www.yelp.com/biz/gs-construction-chicago-2'));

        if (! $browserScrape) {
            $this->error('Yelp requires --browser-scrape (DataDome protection).');

            return self::FAILURE;
        }

        $twocaptchaKey = (string) config('services.twocaptcha.api_key', '');
        if ($twocaptchaKey === '') {
            $this->error('TWOCAPTCHA_API_KEY is not set. Required for DataDome solving.');

            return self::FAILURE;
        }

        $this->info('Scraping Yelp reviews with Puppeteer + DataDome solver...');
        $reviews = $this->scrapeWithBrowser($pageUrl, $browserTimeoutMs, $maxPages, $browserHeaded);
        $this->info('Scraped '.count($reviews).' review(s).');

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

            // Match by name + date
            $matchedByNameDate = $existing->first(function (Testimonial $t) use ($payload) {
                $sameName = mb_strtolower(trim($t->reviewer_name)) === mb_strtolower(trim($payload['reviewer_name']));
                $sameDate = ($t->review_date?->toDateString() ?? null) === ($payload['review_date']?->toDateString() ?? null);

                return $sameName && $sameDate;
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
     * @return array<int, array<string, mixed>>
     */
    private function scrapeWithBrowser(string $pageUrl, int $timeoutMs, int $maxPages, bool $headed = false): array
    {
        $scriptPath = base_path('scripts/scrape-yelp-reviews.mjs');
        if (! is_file($scriptPath)) {
            $this->warn('Browser scraper script missing: '.$scriptPath);

            return [];
        }

        $twocaptchaKey = (string) config('services.twocaptcha.api_key', '');

        $cmd = sprintf(
            'node %s --url=%s --timeout-ms=%d --max-pages=%d --twocaptcha-key=%s %s %s',
            escapeshellarg($scriptPath),
            escapeshellarg($pageUrl),
            $timeoutMs,
            $maxPages,
            escapeshellarg($twocaptchaKey),
            $headed ? '--headed' : '',
            $this->proxy !== '' ? '--proxy='.escapeshellarg($this->proxy) : '',
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($process)) {
            $this->warn('Failed to start Puppeteer scraper process.');

            return [];
        }

        fclose($pipes[0]);

        // Read stdout and stderr concurrently to prevent pipe buffer deadlock.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $stderr = '';
        $open = [$pipes[1], $pipes[2]];

        while ($open) {
            $read = $open;
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($pipe)) {
                        $key = array_search($pipe, $open, true);
                        if ($key !== false) {
                            unset($open[$key]);
                        }
                    }

                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $output .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($stderr !== '' && $stderr !== false) {
            foreach (explode("\n", trim($stderr)) as $line) {
                $this->line('  <comment>[scraper]</comment> '.$line);
            }
        }

        if (! is_string($output) || trim($output) === '') {
            $this->warn('Puppeteer scraper returned no output.');

            return [];
        }

        $decoded = json_decode($output, true);
        if (! is_array($decoded) || ! isset($decoded['reviews']) || ! is_array($decoded['reviews'])) {
            $this->warn('Failed to parse Puppeteer scraper JSON output.');

            return [];
        }

        return $decoded['reviews'];
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

        if (! $testimonial->review_date && $payload['review_date']) {
            $update['review_date'] = $payload['review_date'];
        }

        if (! $testimonial->star_rating && $payload['star_rating']) {
            $update['star_rating'] = $payload['star_rating'];
        }

        $existingDescription = (string) $testimonial->review_description;
        $incomingDescription = (string) $payload['review_description'];

        if ($this->normalizeForComparison($existingDescription) !== $this->normalizeForComparison($incomingDescription)) {
            if (mb_strlen($incomingDescription) > mb_strlen($existingDescription) + 25) {
                $update['review_description'] = $incomingDescription;
            }
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
