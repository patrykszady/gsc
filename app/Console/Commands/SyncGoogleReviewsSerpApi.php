<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SyncGoogleReviewsSerpApi extends Command
{
    protected $signature = 'testimonials:sync-google-reviews-serpapi
        {--place-id= : Google place_id (defaults to GOOGLE_BUSINESS_PROFILE_PLACE_ID)}
        {--data-id= : SerpApi data_id (e.g. 0x...:0x...). If omitted, place_id is used and SerpApi resolves it.}
        {--max-pages=10 : Maximum number of SerpApi pages to fetch}
        {--only-new : Only create new reviews; skip updating already matched reviews}
        {--force : Skip the cheap probe and always call SerpApi}
        {--dry-run : Show what would change without writing to DB}';

    protected $description = 'Sync Google Maps reviews via SerpApi (google_maps_reviews). Probes free Places API first to skip when nothing changed.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $maxPages = max(1, (int) $this->option('max-pages'));
        $onlyNew = (bool) $this->option('only-new');

        $apiKey = (string) config('services.serpapi.api_key', '');
        if ($apiKey === '') {
            $this->error('SERPAPI_API_KEY (or SERPAPI_KEY) is not set.');

            return self::FAILURE;
        }

        $placeId = trim((string) ($this->option('place-id') ?: config('services.google.business_profile.place_id', '')));
        $dataId = trim((string) ($this->option('data-id') ?: config('services.serpapi.google_data_id', '')));

        if ($placeId === '' && $dataId === '') {
            $this->error('Provide --place-id, --data-id, or set GOOGLE_BUSINESS_PROFILE_PLACE_ID / SERPAPI_GOOGLE_DATA_ID.');

            return self::FAILURE;
        }

        $cacheKey = 'google_review_count:'.($dataId ?: $placeId);

        if (! $this->option('force') && $placeId !== '') {
            $currentCount = $this->probeGoogleReviewCount($placeId);
            $lastCount = Cache::get($cacheKey);
            $lastCount = is_numeric($lastCount) ? (int) $lastCount : null;

            if ($currentCount !== null) {
                if ($lastCount !== null && $currentCount === $lastCount) {
                    $this->info("Google review count unchanged ({$currentCount}). Skipping SerpApi call. Use --force to override.");

                    return self::SUCCESS;
                }
                $this->line('Google review count: '.$currentCount.($lastCount !== null ? " (was {$lastCount})" : ' (no previous value)'));
            } else {
                $this->warn('Could not probe Google review count via Places API; proceeding with SerpApi call.');
            }
        }

        $this->info('Fetching Google reviews from SerpApi...');
        $reviews = $this->fetchWithSerpApi($placeId, $dataId, $apiKey, $maxPages);
        $this->info('Fetched '.count($reviews).' review(s).');

        if (empty($reviews)) {
            $this->warn('No Google reviews were discovered.');

            return self::SUCCESS;
        }

        $stats = [
            'created' => 0,
            'matched_external_id' => 0,
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

            // Match by external Google review id
            $matchedById = null;
            if ($payload['external_id']) {
                $matchedById = $existing->first(function (Testimonial $t) use ($payload) {
                    return $t->reviewUrls->contains(function ($url) use ($payload) {
                        return $url->platform === 'google' && $url->external_id === $payload['external_id'];
                    });
                });
            }

            if ($matchedById) {
                $stats['matched_external_id']++;
                if ($onlyNew) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $stats['updated'] += $this->upsertIntoExisting($matchedById, $payload, $dryRun);
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
                $stats['updated'] += $this->upsertIntoExisting($matchedByContent, $payload, $dryRun);
                continue;
            }

            // Match by name + date (exact, then fuzzy initials)
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
                $stats['updated'] += $this->upsertIntoExisting($matchedByNameDate, $payload, $dryRun);
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

            if ($payload['external_id'] || $payload['url']) {
                $testimonial->reviewUrls()->create([
                    'platform' => 'google',
                    'url' => $payload['url'] ?: $this->buildFallbackGoogleUrl($payload['external_id'], $placeId),
                    'external_id' => $payload['external_id'],
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
        $this->line('  Matched by Google review id: '.$stats['matched_external_id']);
        $this->line('  Matched by content: '.$stats['matched_content']);
        $this->line('  Matched by name+date: '.$stats['matched_name_date']);
        $this->line('  Skipped existing (only-new): '.$stats['skipped_existing']);
        $this->line('  Updated existing: '.$stats['updated']);

        if (! $dryRun && ! $this->option('force') && $placeId !== '') {
            $finalCount = $this->probeGoogleReviewCount($placeId);
            if ($finalCount !== null) {
                Cache::forever($cacheKey, $finalCount);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Probe Google Places API v1 for userRatingCount. Free tier covers light usage.
     */
    private function probeGoogleReviewCount(string $placeId): ?int
    {
        $apiKey = (string) config('services.google.places.api_key', '') ?: env('GOOGLE_PLACES_API_KEY', '');
        if ($apiKey === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => 'userRatingCount',
                ])
                ->get('https://places.googleapis.com/v1/places/'.$placeId);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (is_array($data) && isset($data['userRatingCount']) && is_numeric($data['userRatingCount'])) {
            return (int) $data['userRatingCount'];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchWithSerpApi(string $placeId, string $dataId, string $apiKey, int $maxPages): array
    {
        $all = [];
        $seenKeys = [];
        $nextPageToken = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = [
                'engine' => 'google_maps_reviews',
                'api_key' => $apiKey,
                'sort_by' => 'newestFirst',
                'hl' => 'en',
            ];
            if ($dataId !== '') {
                $params['data_id'] = $dataId;
            } elseif ($placeId !== '') {
                $params['place_id'] = $placeId;
            }
            if ($nextPageToken) {
                $params['next_page_token'] = $nextPageToken;
            }

            $response = Http::timeout(30)->acceptJson()
                ->get('https://serpapi.com/search.json', $params);

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
                $description = trim((string) (data_get($review, 'snippet') ?? data_get($review, 'extracted_snippet.original') ?? ''));
                $rating = data_get($review, 'rating');
                $rating = is_numeric($rating) ? (int) $rating : null;

                $isoDate = (string) data_get($review, 'iso_date', '');
                $relativeDate = (string) data_get($review, 'date', '');
                $dateRaw = $isoDate !== '' ? $isoDate : $relativeDate;

                $reviewLink = data_get($review, 'link');
                if (! is_string($reviewLink)) {
                    $reviewLink = null;
                }

                $reviewId = data_get($review, 'review_id');
                if (! is_string($reviewId) || $reviewId === '') {
                    $reviewId = null;
                }

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
                    'url' => $reviewLink,
                    'external_id' => $reviewId,
                ];
            }

            $nextPageToken = data_get($json, 'serpapi_pagination.next_page_token');
            if (! is_string($nextPageToken) || $nextPageToken === '') {
                break;
            }
        }

        return $all;
    }

    /**
     * @return array{reviewer_name:string, review_description:string, review_date:?Carbon, star_rating:?int, url:?string, external_id:?string}|null
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

        return [
            'reviewer_name' => $name,
            'review_description' => $description,
            'review_date' => $reviewDate,
            'star_rating' => $starRating,
            'url' => is_string($raw['url'] ?? null) ? $raw['url'] : null,
            'external_id' => is_string($raw['external_id'] ?? null) ? $raw['external_id'] : null,
        ];
    }

    private function upsertIntoExisting(Testimonial $testimonial, array $payload, bool $dryRun): int
    {
        $changed = 0;

        if ($dryRun) {
            $target = $payload['external_id'] ?: ($payload['url'] ?: '[no url]');
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

        if ($payload['external_id'] || $payload['url']) {
            $existingGoogle = $testimonial->reviewUrls()->where('platform', 'google')->first();
            $url = $payload['url'] ?: $this->buildFallbackGoogleUrl($payload['external_id'], (string) config('services.google.business_profile.place_id', ''));

            if (! $existingGoogle) {
                $testimonial->reviewUrls()->create([
                    'platform' => 'google',
                    'url' => $url,
                    'external_id' => $payload['external_id'],
                ]);
                $changed++;
            } else {
                $patch = [];
                if ($payload['external_id'] && $existingGoogle->external_id !== $payload['external_id']) {
                    $patch['external_id'] = $payload['external_id'];
                }
                if ($payload['url'] && $existingGoogle->url !== $payload['url']) {
                    $patch['url'] = $payload['url'];
                }
                if (! empty($patch)) {
                    $existingGoogle->update($patch);
                    $changed++;
                }
            }
        }

        if ($changed > 0) {
            $this->line("Updated: #{$testimonial->id} {$testimonial->reviewer_name}");
        }

        return $changed;
    }

    private function buildFallbackGoogleUrl(?string $reviewId, string $placeId): string
    {
        if ($placeId === '') {
            return 'https://www.google.com/maps';
        }

        $base = 'https://search.google.com/local/reviews?placeid='.urlencode($placeId);

        return $reviewId ? $base.'&reviewId='.urlencode($reviewId) : $base;
    }

    /**
     * Match names like "M. Smith" to "Mary Smith" by comparing first-letter
     * initials of the first and last tokens. Caller enforces date equality.
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
