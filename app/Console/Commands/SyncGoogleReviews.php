<?php

namespace App\Console\Commands;

use App\Models\ReviewUrl;
use App\Models\Testimonial;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncGoogleReviews extends Command
{
    protected $signature = 'google-business-profile:sync-reviews
        {--dry-run : Show what would be synced without writing to DB}';

    protected $description = 'Fetch Google Business Profile reviews and sync new ones to testimonials.';

    /**
     * Map Google's star rating enum to a numeric value.
     */
    protected const STAR_RATINGS = [
        'ONE' => 1,
        'TWO' => 2,
        'THREE' => 3,
        'FOUR' => 4,
        'FIVE' => 5,
    ];

    public function handle(GoogleBusinessProfileService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('Google Business Profile is not fully configured.');

            return self::FAILURE;
        }

        $this->info('Fetching reviews from Google Business Profile...');

        $reviews = $service->fetchAllReviews();

        if (empty($reviews)) {
            $error = $service->getLastError();
            if ($error) {
                $this->error('Failed to fetch reviews: ' . ($error['message'] ?? 'Unknown error'));
                return self::FAILURE;
            }

            $this->info('No reviews found.');
            return self::SUCCESS;
        }

        $this->info(count($reviews) . ' review(s) fetched from Google.');

        $created = 0;
        $skipped = 0;

        foreach ($reviews as $review) {
            $reviewId = $this->extractReviewId($review['name'] ?? '');

            if (! $reviewId) {
                $this->warn('Skipping review with missing name.');
                $skipped++;
                continue;
            }

            // Skip reviews we already have (by review_urls.external_id)
            if (ReviewUrl::where('platform', 'google')->where('external_id', $reviewId)->exists()) {
                $skipped++;
                continue;
            }

            $comment = $review['comment'] ?? '';
            $displayName = $review['reviewer']['displayName'] ?? 'Google Reviewer';
            $starRating = self::STAR_RATINGS[$review['starRating'] ?? ''] ?? null;
            $reviewDate = isset($review['createTime'])
                ? Carbon::parse($review['createTime'])->toDateString()
                : null;

            // If a testimonial with the same name+date already exists, attach Google identity to it.
            if ($reviewDate) {
                $sameNameDate = Testimonial::where('reviewer_name', $displayName)
                    ->where('review_date', $reviewDate)
                    ->first();

                if ($sameNameDate) {
                    if (! $this->option('dry-run')) {
                        $this->upsertGoogleReviewReference($sameNameDate, $reviewId);
                        if (! $sameNameDate->star_rating && $starRating) {
                            $sameNameDate->update(['star_rating' => $starRating]);
                        }
                    }
                    $skipped++;
                    continue;
                }
            }

            // Skip if a testimonial with matching review text already exists (cross-platform dupes)
            // Normalize to ASCII so mojibake-encoded entries still match clean Google text.
            if (mb_strlen($comment) > 20) {
                $normalized = $this->normalizeForComparison($comment, 80);
                $existing = Testimonial::all()->first(function ($t) use ($normalized) {
                    return $this->normalizeForComparison($t->review_description, 80) === $normalized;
                });

                if ($existing) {
                    // Store Google identity on review_urls.
                    if (! $this->option('dry-run')) {
                        $this->upsertGoogleReviewReference($existing, $reviewId);
                    }
                    if (! $existing->star_rating && $starRating) {
                        $existing->update(['star_rating' => $starRating]);
                    }
                    $skipped++;
                    continue;
                }
            }

            if ($this->option('dry-run')) {
                $this->line("[DRY RUN] Would create: {$displayName} — {$starRating}★ — " . mb_substr($comment, 0, 60) . '...');
                $created++;
                continue;
            }

            $testimonial = Testimonial::create([
                'reviewer_name' => $displayName,
                'review_description' => $comment ?: 'Left a ' . ($starRating ?? 5) . '-star review.',
                'review_date' => $reviewDate,
                'star_rating' => $starRating,
            ]);

            $this->upsertGoogleReviewReference($testimonial, $reviewId);

            $created++;
            $this->line("Created: {$displayName} — {$starRating}★");
        }

        $this->newLine();
        $this->info("Done. Created: {$created}, Skipped (already exists): {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Extract the review ID from the GBP resource name.
     * e.g. "accounts/123/locations/456/reviews/AbCdEfGh" → "AbCdEfGh"
     */
    protected function extractReviewId(string $resourceName): ?string
    {
        if (! $resourceName) {
            return null;
        }

        $parts = explode('/', $resourceName);

        return end($parts) ?: null;
    }

    /**
     * Normalize text for duplicate comparison by stripping non-ASCII,
     * collapsing whitespace, and truncating to a fixed length.
     * This ensures mojibake-encoded text still matches clean Google text.
     */
    protected function normalizeForComparison(string $text, int $length = 80): string
    {
        // Transliterate to ASCII (handles curly quotes, em dashes, etc.)
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        // Strip anything that's not alphanumeric or space
        $text = preg_replace('/[^a-zA-Z0-9 ]/', '', $text);
        // Collapse whitespace and lowercase
        $text = mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));

        return mb_substr($text, 0, $length);
    }

    protected function upsertGoogleReviewReference(Testimonial $testimonial, string $reviewId, ?string $googleMapsUri = null): void
    {
        $url = $googleMapsUri ?: $this->buildFallbackGoogleUrl($reviewId);

        $reviewUrl = $testimonial->reviewUrls()->where('platform', 'google')->first();
        if (! $reviewUrl) {
            $testimonial->reviewUrls()->create([
                'platform' => 'google',
                'url' => $url,
                'external_id' => $reviewId,
            ]);

            return;
        }

        $reviewUrl->update([
            'url' => $googleMapsUri ?: $reviewUrl->url ?: $url,
            'external_id' => $reviewId,
        ]);
    }

    protected function buildFallbackGoogleUrl(string $reviewId): string
    {
        $placeId = (string) config('services.google.business_profile.place_id', '');

        if ($placeId !== '') {
            return 'https://search.google.com/local/reviews?placeid='.$placeId;
        }

        return 'https://www.google.com/maps/reviews?reviewid='.$reviewId;
    }
}
