<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SyncHouzzReviews extends Command
{
    /** @var array<string, ?string> */
    private array $reviewUrlByUserProfileCache = [];

    protected $signature = 'testimonials:sync-houzz-reviews
        {--profile-url=https://www.houzz.com/professionals/kitchen-and-bath-remodelers/gs-construction-pfvwus-pf~1225706575 : Houzz business profile URL}
        {--browser-scrape : Use Puppeteer profile scraper to extract all review cards}
        {--browser-headed : Run Puppeteer in headed mode (useful for logged-in/manual flows)}
        {--browser-timeout-ms=120000 : Timeout for browser scraping in milliseconds}
        {--browser-json= : Path to Puppeteer JSON file, or "-" for stdin}
        {--profile-html= : Local path to saved Houzz profile HTML (used when direct fetch is blocked)}
        {--seed-review-url=* : One or more explicit Houzz review URLs to include}
        {--seed-from-db : Include existing houzz URLs from review_urls table as seeds}
        {--proxy= : Residential proxy URL for Puppeteer (e.g. http://user:pass@host:port)}
        {--only-new : Only create new reviews; skip updating already matched reviews}
        {--dry-run : Show what would change without writing to DB}';

    protected $description = 'Scrape Houzz reviews, match existing testimonials, and create/update records.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $profileUrl = (string) $this->option('profile-url');
        $browserScrape = (bool) $this->option('browser-scrape');
        $browserHeaded = (bool) $this->option('browser-headed');
        $browserTimeoutMs = max(10000, (int) $this->option('browser-timeout-ms'));
        $profileHtmlPath = (string) $this->option('profile-html');
        $seedUrls = array_values(array_filter((array) $this->option('seed-review-url')));
        $seedFromDb = (bool) $this->option('seed-from-db');
        $proxy = (string) ($this->option('proxy') ?: config('services.scraper.proxy', ''));
        $onlyNew = (bool) $this->option('only-new');

        $browserJsonPath = (string) $this->option('browser-json');

        $profileReviews = [];
        if ($browserJsonPath !== '') {
            $profileReviews = $this->loadBrowserJson($browserJsonPath);
            $source = $browserJsonPath === '-' ? 'stdin' : 'JSON file';
            $this->info('Loaded '.count($profileReviews).' profile review card(s) from '.$source.'.');
        } elseif ($browserScrape) {
            $this->info('Scraping Houzz profile review list with Puppeteer...');
            $profileReviews = $this->scrapeProfileReviewsWithBrowser($profileUrl, $browserTimeoutMs, $browserHeaded, $proxy);
            $this->info('Scraped '.count($profileReviews).' profile review card(s).');
        }

        $this->info('Collecting Houzz review URLs...');
        $reviewUrls = $this->collectReviewUrls($profileUrl, $profileHtmlPath, $seedUrls, $seedFromDb);

        if (empty($reviewUrls) && empty($profileReviews)) {
            $this->warn('No Houzz reviews were discovered from URL seeds or profile scrape.');
            $this->line('Tip: add --browser-scrape and/or provide --seed-review-url.');

            return self::SUCCESS;
        }

        if (! empty($reviewUrls)) {
            $this->info('Discovered '.count($reviewUrls).' review URL(s).');
        }

        $stats = [
            'created' => 0,
            'matched_url' => 0,
            'matched_content' => 0,
            'matched_name_date' => 0,
            'skipped_existing' => 0,
            'updated' => 0,
            'failed_parse' => 0,
        ];

        $existing = Testimonial::with('reviewUrls')->get();
        $seenPayloadKeys = [];

        foreach ($profileReviews as $profilePayload) {
            $payload = $this->normalizeProfilePayload($profilePayload);
            if (! $payload) {
                $stats['failed_parse']++;
                continue;
            }

            if (! $payload['url'] && ! empty($payload['reviewer_profile_url'])) {
                $resolved = $this->resolveReviewUrlFromReviewerProfile(
                    $payload['reviewer_profile_url'],
                    $payload['review_description'],
                );

                if ($resolved) {
                    $payload['url'] = $resolved;
                }
            }

            $payloadKey = $this->payloadKey($payload['reviewer_name'], $payload['review_description'], $payload['review_date']);
            if (isset($seenPayloadKeys[$payloadKey])) {
                continue;
            }
            $seenPayloadKeys[$payloadKey] = true;

            $profileUrlValue = $payload['url']
                ?? ($payload['reviewer_profile_url'] ?: null);
            $matchedByUrl = null;

            if ($profileUrlValue) {
                $matchedByUrl = $existing->first(function (Testimonial $t) use ($profileUrlValue) {
                    return $t->reviewUrls->contains(function ($url) use ($profileUrlValue) {
                        return $url->platform === 'houzz' && $this->isSameHouzzReviewUrl($url->url, $profileUrlValue);
                    });
                });
            }

            if ($matchedByUrl) {
                $stats['matched_url']++;
                if ($onlyNew) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $stats['updated'] += $this->upsertIntoExisting($matchedByUrl, $payload, $profileUrlValue, $dryRun);
                continue;
            }

            $matchedByContent = $existing->first(function (Testimonial $t) use ($payload) {
                return $this->normalizeForComparison($t->review_description) === $this->normalizeForComparison($payload['review_description']);
            });

            if ($matchedByContent) {
                $stats['matched_content']++;
                if ($onlyNew) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $stats['updated'] += $this->upsertIntoExisting($matchedByContent, $payload, $profileUrlValue, $dryRun);
                continue;
            }

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
                $stats['updated'] += $this->upsertIntoExisting($matchedByNameDate, $payload, $profileUrlValue, $dryRun);
                continue;
            }

            if ($dryRun) {
                $dateLabel = $payload['review_date'] ? $payload['review_date']->toDateString() : 'no-date';
                $this->line("[DRY RUN] Create (profile): {$payload['reviewer_name']} ({$dateLabel})");
                $stats['created']++;
                continue;
            }

            $testimonial = Testimonial::create([
                'reviewer_name' => $payload['reviewer_name'],
                'review_description' => $payload['review_description'],
                'review_date' => $payload['review_date'],
                'star_rating' => $payload['star_rating'],
            ]);

            if ($profileUrlValue) {
                $testimonial->reviewUrls()->create([
                    'platform' => 'houzz',
                    'url' => $profileUrlValue,
                ]);
            }

            $existing->push($testimonial->load('reviewUrls'));
            $stats['created']++;
            $this->line("Created: #{$testimonial->id} {$payload['reviewer_name']}");
        }

        foreach ($reviewUrls as $reviewUrl) {
            $payload = $this->scrapeReviewPage($reviewUrl);
            if (! $payload) {
                $this->warn("Parse failed: {$reviewUrl}");
                $stats['failed_parse']++;
                continue;
            }

            $payloadKey = $this->payloadKey($payload['reviewer_name'], $payload['review_description'], $payload['review_date']);
            if (isset($seenPayloadKeys[$payloadKey])) {
                continue;
            }
            $seenPayloadKeys[$payloadKey] = true;

            $matchedByUrl = $existing->first(function (Testimonial $t) use ($reviewUrl) {
                return $t->reviewUrls->contains(function ($url) use ($reviewUrl) {
                    return $url->platform === 'houzz' && $this->isSameHouzzReviewUrl($url->url, $reviewUrl);
                });
            });

            if ($matchedByUrl) {
                $stats['matched_url']++;
                if ($onlyNew) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $stats['updated'] += $this->upsertIntoExisting($matchedByUrl, $payload, $reviewUrl, $dryRun);
                continue;
            }

            $matchedByContent = $existing->first(function (Testimonial $t) use ($payload) {
                return $this->normalizeForComparison($t->review_description) === $this->normalizeForComparison($payload['review_description']);
            });

            if ($matchedByContent) {
                $stats['matched_content']++;
                if ($onlyNew) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $stats['updated'] += $this->upsertIntoExisting($matchedByContent, $payload, $reviewUrl, $dryRun);
                continue;
            }

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
                $stats['updated'] += $this->upsertIntoExisting($matchedByNameDate, $payload, $reviewUrl, $dryRun);
                continue;
            }

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

            $testimonial->reviewUrls()->create([
                'platform' => 'houzz',
                'url' => $reviewUrl,
            ]);

            $existing->push($testimonial->load('reviewUrls'));
            $stats['created']++;
            $this->line("Created: #{$testimonial->id} {$payload['reviewer_name']}");
        }

        $this->newLine();
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info($prefix.'Summary');
        $this->line('  Created: '.$stats['created']);
        $this->line('  Profile reviews scraped: '.count($profileReviews));
        $this->line('  Matched by Houzz URL: '.$stats['matched_url']);
        $this->line('  Matched by content: '.$stats['matched_content']);
        $this->line('  Matched by name+date: '.$stats['matched_name_date']);
        $this->line('  Skipped existing (only-new): '.$stats['skipped_existing']);
        $this->line('  Updated existing: '.$stats['updated']);
        $this->line('  Parse failures: '.$stats['failed_parse']);

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function collectReviewUrls(string $profileUrl, string $profileHtmlPath, array $seedUrls, bool $seedFromDb): array
    {
        $urls = [];

        if ($profileUrl !== '') {
            $html = $this->fetchHtml($profileUrl);
            if ($html) {
                preg_match_all('#https?://www\.houzz\.com/viewReview/\d+/[^"\'\s<]+#i', $html, $matches);
                foreach ($matches[0] ?? [] as $url) {
                    $urls[] = $this->normalizeUrl($url);
                }
            }
        }

        if ($profileHtmlPath !== '' && is_file($profileHtmlPath)) {
            $html = @file_get_contents($profileHtmlPath);
            if (is_string($html) && $html !== '') {
                preg_match_all('#https?://www\.houzz\.com/viewReview/\d+/[^"\'\s<]+#i', $html, $matches);
                foreach ($matches[0] ?? [] as $url) {
                    $urls[] = $this->normalizeUrl($url);
                }

                // Also handle relative links copied from browser source.
                preg_match_all('#/viewReview/\d+/[^"\'\s<]+#i', $html, $matches);
                foreach ($matches[0] ?? [] as $relative) {
                    $urls[] = $this->normalizeUrl('https://www.houzz.com'.$relative);
                }
            }
        }

        if ($seedFromDb) {
            $dbUrls = Testimonial::query()
                ->with(['reviewUrls' => fn ($q) => $q->where('platform', 'houzz')])
                ->get()
                ->flatMap(fn (Testimonial $t) => $t->reviewUrls->pluck('url')->all())
                ->all();

            foreach ($dbUrls as $dbUrl) {
                $urls[] = $this->normalizeUrl((string) $dbUrl);
            }
        }

        foreach ($seedUrls as $seedUrl) {
            $seedUrl = $this->normalizeUrl($seedUrl);
            if ($seedUrl !== '') {
                $urls[] = $seedUrl;
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));

        // Expand from seed pages in case they contain additional viewReview links.
        foreach ($urls as $url) {
            $html = $this->fetchHtml($url);
            if (! $html) {
                continue;
            }

            preg_match_all('#https?://www\.houzz\.com/viewReview/\d+/[^"\'\s<]+#i', $html, $matches);
            foreach ($matches[0] ?? [] as $candidate) {
                $urls[] = $this->normalizeUrl($candidate);
            }
        }

        return $this->deduplicateHouzzUrls($urls);
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(30)->get($url);

            if (! $response->successful()) {
                return null;
            }

            return $response->body();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{reviewer_name:string, review_description:string, review_date:?Carbon, star_rating:?int}|null
     */
    private function scrapeReviewPage(string $reviewUrl): ?array
    {
        $html = $this->fetchHtml($reviewUrl);
        if (! $html) {
            return null;
        }

        // Houzz can return a generic "Page Not Found" shell for blocked requests.
        if (stripos($html, '<title>Page Not Found</title>') !== false) {
            return null;
        }

        $reviewer = null;
        $description = null;
        $starRating = null;
        $reviewDate = null;

        if (preg_match('#<a[^>]*class=["\'][^"\']*hz-username[^"\']*["\'][^>]*>([^<]+)</a>#i', $html, $m)) {
            $reviewer = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        }

        if (preg_match('#<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']#i', $html, $m)) {
            $description = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        } elseif (preg_match('#<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']#i', $html, $m)) {
            $description = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        }

        if (preg_match_all('#rate-star--highlighted#i', $html, $m)) {
            $starRating = count($m[0]) ?: null;
        }

        if (preg_match('#<b>\s*Project Date\s*</b>\s*:\s*([^<]+)</div>#i', $html, $m)) {
            $reviewDate = $this->parseHouzzProjectDate(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)));
        }

        // Prefer the review post date (from <time> element) over project date.
        // When a review was updated, Houzz shows both: "Sep 2, 2018 · last modified: Oct 17, 2018".
        // Pick the earliest <time> datetime — that's the original review date.
        if (preg_match_all('#<time[^>]+datetime=["\']([^"\']+)["\']#i', $html, $matches)) {
            $dates = [];
            foreach ($matches[1] as $dt) {
                try {
                    $dates[] = Carbon::parse(trim($dt))->startOfDay();
                } catch (\Throwable) {
                }
            }
            if ($dates) {
                sort($dates);
                $reviewDate = $dates[0];
            }
        }

        if (! $reviewer || ! $description) {
            return null;
        }

        return [
            'reviewer_name' => $reviewer,
            'review_description' => $description,
            'review_date' => $reviewDate,
            'star_rating' => $starRating,
        ];
    }

    private function parseHouzzProjectDate(string $raw): ?Carbon
    {
        // Typical value: "December 2022".
        try {
            return Carbon::createFromFormat('F Y', $raw)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function upsertIntoExisting(Testimonial $testimonial, array $payload, ?string $reviewUrl, bool $dryRun): int
    {
        $changed = 0;

        $reviewUrl = $reviewUrl ? $this->normalizeUrl($reviewUrl) : null;

        if ($dryRun) {
            $target = $reviewUrl ?? '[no direct url]';
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
            // Keep richer existing body if it is meaningfully longer.
            if (mb_strlen($incomingDescription) > mb_strlen($existingDescription) + 25) {
                $update['review_description'] = $incomingDescription;
            }
        } elseif (
            ! str_contains($existingDescription, "\n")
            && str_contains($incomingDescription, "\n")
            && mb_strlen($incomingDescription) >= mb_strlen($existingDescription) - 20
        ) {
            // Preserve paragraph formatting when text is otherwise equivalent.
            $update['review_description'] = $incomingDescription;
        }

        if (! empty($update)) {
            $testimonial->update($update);
            $changed++;
        }

        if ($reviewUrl) {
            $existingHouzz = $testimonial->reviewUrls()->where('platform', 'houzz')->first();
            if (! $existingHouzz) {
                $testimonial->reviewUrls()->create([
                    'platform' => 'houzz',
                    'url' => $reviewUrl,
                ]);
                $changed++;
            } elseif (! $this->isSameHouzzReviewUrl($existingHouzz->url, $reviewUrl)) {
                $existingHouzz->update(['url' => $reviewUrl]);
                $changed++;
            }
        }

        if ($changed > 0) {
            $this->line("Updated: #{$testimonial->id} {$testimonial->reviewer_name}");
        }

        return $changed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scrapeProfileReviewsWithBrowser(string $profileUrl, int $timeoutMs, bool $headed = false, string $proxy = ''): array
    {
        $scriptPath = base_path('scripts/scrape-houzz-reviews.mjs');
        if (! is_file($scriptPath)) {
            $this->warn('Browser scraper script missing: '.$scriptPath);
            return [];
        }

        $cmd = sprintf(
            'node %s --url=%s --timeout-ms=%d %s %s',
            escapeshellarg($scriptPath),
            escapeshellarg($profileUrl),
            $timeoutMs,
            $headed ? '--headed' : '',
            $proxy !== '' ? '--proxy='.escapeshellarg($proxy) : '',
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
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
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
     * @return array<int, array<string, mixed>>
     */
    private function loadBrowserJson(string $path): array
    {
        if ($path === '-') {
            $contents = file_get_contents('php://stdin');
        } else {
            if (! is_file($path)) {
                $this->warn('Browser JSON file not found: '.$path);

                return [];
            }

            $contents = file_get_contents($path);
        }

        if (! is_string($contents) || trim($contents) === '') {
            $this->warn('Browser JSON input is empty.');

            return [];
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded) || ! isset($decoded['reviews']) || ! is_array($decoded['reviews'])) {
            $this->warn('Failed to parse browser JSON input.');

            return [];
        }

        return $decoded['reviews'];
    }

    private function resolveReviewUrlFromReviewerProfile(string $reviewerProfileUrl, string $reviewText): ?string
    {
        $reviewerProfileUrl = $this->normalizeUrl($reviewerProfileUrl);
        if ($reviewerProfileUrl === '') {
            return null;
        }

        if (array_key_exists($reviewerProfileUrl, $this->reviewUrlByUserProfileCache)) {
            return $this->reviewUrlByUserProfileCache[$reviewerProfileUrl];
        }

        $sources = [$reviewerProfileUrl];
        $activityUrl = $this->buildActivityUrlFromReviewerProfile($reviewerProfileUrl);
        if ($activityUrl) {
            $sources[] = $activityUrl;
        }

        $htmlBlobs = [];
        foreach (array_unique($sources) as $sourceUrl) {
            $html = $this->fetchHtml($sourceUrl);
            if ($html) {
                $htmlBlobs[] = $html;
            }
        }

        if (empty($htmlBlobs)) {
            $this->reviewUrlByUserProfileCache[$reviewerProfileUrl] = null;
            return null;
        }

        $candidates = [];
        foreach ($htmlBlobs as $html) {
            preg_match_all('#https?://www\.houzz\.com/viewReview/\d+/[^"\'\s<]+#i', $html, $matches);
            foreach ($matches[0] ?? [] as $u) {
                $candidates[] = $this->normalizeUrl($u);
            }
            preg_match_all('#/viewReview/\d+/[^"\'\s<]+#i', $html, $matches);
            foreach ($matches[0] ?? [] as $u) {
                $candidates[] = $this->normalizeUrl('https://www.houzz.com'.$u);
            }
        }

        $candidates = array_values(array_unique($candidates));
        $candidates = array_values(array_filter($candidates, fn ($u) => str_contains(mb_strtolower($u), 'gs-construction-review')));

        if (count($candidates) === 1) {
            $this->reviewUrlByUserProfileCache[$reviewerProfileUrl] = $candidates[0];
            return $candidates[0];
        }

        if (count($candidates) > 1) {
            $target = $this->normalizeForComparison($reviewText, 140);
            foreach ($candidates as $candidate) {
                $candidateHtml = $this->fetchHtml($candidate);
                if (! $candidateHtml) {
                    continue;
                }

                $candidateText = null;
                if (preg_match('#<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']#i', $candidateHtml, $m)) {
                    $candidateText = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
                }

                if (! $candidateText) {
                    continue;
                }

                if ($this->normalizeForComparison($candidateText, 140) === $target) {
                    $this->reviewUrlByUserProfileCache[$reviewerProfileUrl] = $candidate;
                    return $candidate;
                }
            }
        }

        $this->reviewUrlByUserProfileCache[$reviewerProfileUrl] = null;
        return null;
    }

    private function buildActivityUrlFromReviewerProfile(string $reviewerProfileUrl): ?string
    {
        if (preg_match('#/user/([^/?\#]+)#i', $reviewerProfileUrl, $m)) {
            $slug = strtolower(trim($m[1]));
            if ($slug === '') {
                return null;
            }

            return 'https://www.houzz.com/activities/user/'.$slug.'/reviews';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $profilePayload
    * @return array{reviewer_name:string, reviewer_profile_url:?string, review_description:string, review_date:?Carbon, star_rating:?int, url:?string}|null
     */
    private function normalizeProfilePayload(array $profilePayload): ?array
    {
        $name = trim((string) ($profilePayload['reviewer_name'] ?? ''));
        $description = trim((string) ($profilePayload['review_description'] ?? ''));
        if ($name === '' || $description === '') {
            return null;
        }

        $dateRaw = trim((string) ($profilePayload['review_date_raw'] ?? ''));
        $reviewDate = $this->parseHouzzProjectDate($dateRaw);

        if (! $reviewDate && $dateRaw !== '') {
            try {
                $reviewDate = Carbon::parse($dateRaw)->startOfDay();
            } catch (\Throwable) {
                $reviewDate = null;
            }
        }

        $starRating = isset($profilePayload['star_rating']) ? (int) $profilePayload['star_rating'] : null;
        if ($starRating !== null && ($starRating < 1 || $starRating > 5)) {
            $starRating = null;
        }

        $url = null;
        if (! empty($profilePayload['url']) && is_string($profilePayload['url'])) {
            $url = $this->normalizeUrl($profilePayload['url']);
        }

        $reviewerProfileUrl = null;
        if (! empty($profilePayload['reviewer_profile_url']) && is_string($profilePayload['reviewer_profile_url'])) {
            $reviewerProfileUrl = $this->normalizeUrl($profilePayload['reviewer_profile_url']);
        }

        return [
            'reviewer_name' => $name,
            'reviewer_profile_url' => $reviewerProfileUrl,
            'review_description' => $description,
            'review_date' => $reviewDate,
            'star_rating' => $starRating,
            'url' => $url,
        ];
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

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        if ($host === 'www.houzz.com') {
            $scheme = 'https';
        }

        return $scheme.'://'.$host.$path;
    }

    private function extractHouzzReviewIdFromUrl(string $url): ?string
    {
        if (preg_match('#/viewreview/(\d+)/#i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private function isSameHouzzReviewUrl(string $a, string $b): bool
    {
        $aId = $this->extractHouzzReviewIdFromUrl($a);
        $bId = $this->extractHouzzReviewIdFromUrl($b);

        if ($aId && $bId) {
            return $aId === $bId;
        }

        return $this->normalizeUrl($a) === $this->normalizeUrl($b);
    }

    /**
     * @param array<int, string> $urls
     * @return array<int, string>
     */
    private function deduplicateHouzzUrls(array $urls): array
    {
        $deduped = [];
        $seenKeys = [];

        foreach ($urls as $url) {
            $normalized = $this->normalizeUrl($url);
            if ($normalized === '') {
                continue;
            }

            $reviewId = $this->extractHouzzReviewIdFromUrl($normalized);
            $key = $reviewId ? 'id:'.$reviewId : 'url:'.$normalized;

            if (isset($seenKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;
            $deduped[] = $normalized;
        }

        return $deduped;
    }
}
