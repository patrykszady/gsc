<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SyncYelpReviews extends Command
{
    protected $signature = 'testimonials:sync-yelp-reviews
        {--url= : Yelp business page URL (defaults to config)}
        {--place-id= : Yelp place_id for SerpApi Yelp Reviews API}
        {--max-pages=10 : Maximum number of SerpApi pages to fetch}
        {--per-page=49 : Reviews per page (max 49)}
        {--only-new : Only create new reviews; skip updating already matched reviews}
        {--dry-run : Show what would change without writing to DB}';

    protected $description = 'Sync Yelp reviews via SerpApi Yelp Reviews API, match existing testimonials, and create/update records.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $maxPages = max(1, (int) $this->option('max-pages'));
        $perPage = max(1, min(49, (int) $this->option('per-page')));
        $onlyNew = (bool) $this->option('only-new');
        $pageUrl = (string) ($this->option('url') ?: config('socials.yelp.url', 'https://www.yelp.com/biz/gs-construction-chicago-2'));
        $apiKey = (string) config('services.serpapi.api_key', '');

        if ($apiKey === '') {
            $this->error('SERPAPI_API_KEY (or SERPAPI_KEY) is not set.');

            return self::FAILURE;
        }

        $placeId = trim((string) ($this->option('place-id') ?: config('services.serpapi.yelp_place_id', '')));
        if ($placeId === '') {
            $this->line('Resolving Yelp place_id from business URL...');
            $placeId = $this->resolvePlaceIdFromBusinessUrl($pageUrl, $apiKey) ?? '';
        }

        if ($placeId === '') {
            $this->error('Could not resolve Yelp place_id. Provide --place-id or set SERPAPI_YELP_PLACE_ID.');

            return self::FAILURE;
        }

        $this->info('Fetching Yelp reviews from SerpApi...');
        $reviews = $this->fetchWithSerpApi($placeId, $apiKey, $maxPages, $perPage, $pageUrl);
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
     * @return array<int, array<string, mixed>>
     */
    private function fetchWithSerpApi(string $placeId, string $apiKey, int $maxPages, int $perPage, string $businessUrl): array
    {
        $all = [];
        $seenKeys = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $start = $page * $perPage;

            $response = Http::timeout(30)
                ->acceptJson()
                ->get('https://serpapi.com/search.json', [
                    'engine' => 'yelp_reviews',
                    'place_id' => $placeId,
                    'start' => $start,
                    'num' => $perPage,
                    'api_key' => $apiKey,
                    'sortby' => 'date_desc',
                ]);

            if (! $response->successful()) {
                $this->warn('SerpApi request failed (HTTP '.$response->status().') on page '.($page + 1).'.');
                break;
            }

            $json = $response->json();
            if (! is_array($json)) {
                $this->warn('Unexpected SerpApi response payload on page '.($page + 1).'.');
                break;
            }

            if (! empty($json['error']) && is_string($json['error'])) {
                $this->warn('SerpApi error: '.$json['error']);
                break;
            }

            $reviews = $json['reviews'] ?? [];
            if (! is_array($reviews) || empty($reviews)) {
                break;
            }

            foreach ($reviews as $review) {
                if (! is_array($review)) {
                    continue;
                }

                $name = trim((string) data_get($review, 'user.name', ''));
                $description = trim((string) data_get($review, 'comment.text', ''));
                $dateRaw = trim((string) data_get($review, 'date', ''));
                $rating = data_get($review, 'rating');
                $rating = is_numeric($rating) ? (int) $rating : null;
                $reviewUrl = $this->extractReviewUrl($review, $businessUrl);

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

            if (count($reviews) < $perPage) {
                break;
            }
        }

        return $all;
    }

    private function extractReviewUrl(array $review, string $businessUrl): ?string
    {
        $candidate = data_get($review, 'link');
        if (is_string($candidate) && str_contains($candidate, 'yelp.com')) {
            return $candidate;
        }

        $reviewId = data_get($review, 'review_id');
        if (is_string($reviewId) && $reviewId !== '') {
            return rtrim($businessUrl, '/').'?hrid='.urlencode($reviewId);
        }

        return null;
    }

    private function resolvePlaceIdFromBusinessUrl(string $businessUrl, string $apiKey): ?string
    {
        $path = (string) parse_url($businessUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = $segments[1] ?? null;

        if (! $slug) {
            return null;
        }

        $query = preg_replace('/-\d+$/', '', $slug) ?? $slug;
        $query = str_replace('-', ' ', $query);

        $response = Http::timeout(30)
            ->acceptJson()
            ->get('https://serpapi.com/search.json', [
                'engine' => 'yelp_search',
                'find_desc' => $query,
                'api_key' => $apiKey,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $results = $json['organic_results'] ?? [];
        if (! is_array($results)) {
            return null;
        }

        $targetPath = '/'.trim((string) parse_url($businessUrl, PHP_URL_PATH), '/');

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $resultLink = data_get($result, 'link');
            if (! is_string($resultLink) || $resultLink === '') {
                continue;
            }

            $resultPath = '/'.trim((string) parse_url($resultLink, PHP_URL_PATH), '/');
            if ($resultPath !== $targetPath) {
                continue;
            }

            $placeIds = data_get($result, 'place_ids', []);
            if (is_array($placeIds) && ! empty($placeIds) && is_string($placeIds[0])) {
                return $placeIds[0];
            }

            $singlePlaceId = data_get($result, 'place_id');
            if (is_string($singlePlaceId) && $singlePlaceId !== '') {
                return $singlePlaceId;
            }
        }

        return null;
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

        // SerpApi is the source of truth for review content.
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
