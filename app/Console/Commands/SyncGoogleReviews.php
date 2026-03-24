<?php

namespace App\Console\Commands;

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

            // Skip reviews we already have (by google_review_id)
            if (Testimonial::where('google_review_id', $reviewId)->exists()) {
                $skipped++;
                continue;
            }

            $comment = $review['comment'] ?? '';
            $displayName = $review['reviewer']['displayName'] ?? 'Google Reviewer';
            $reviewerName = $this->formatReviewerName($displayName);
            $starRating = self::STAR_RATINGS[$review['starRating'] ?? ''] ?? null;
            $reviewDate = isset($review['createTime'])
                ? Carbon::parse($review['createTime'])->toDateString()
                : null;

            // Skip if a testimonial with the same name and date already exists
            if ($reviewDate && Testimonial::where('reviewer_name', $reviewerName)->where('review_date', $reviewDate)->exists()) {
                $skipped++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("[DRY RUN] Would create: {$reviewerName} — {$starRating}★ — " . mb_substr($comment, 0, 60) . '...');
                $created++;
                continue;
            }

            Testimonial::create([
                'reviewer_name' => $reviewerName,
                'review_description' => $comment ?: 'Left a ' . ($starRating ?? 5) . '-star review.',
                'review_date' => $reviewDate,
                'google_review_id' => $reviewId,
                'star_rating' => $starRating,
            ]);

            $created++;
            $this->line("Created: {$reviewerName} — {$starRating}★");
        }

        $this->newLine();
        $this->info("Done. Created: {$created}, Skipped (already exists): {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Format the display name to "First L." (first name + last initial).
     */
    protected function formatReviewerName(string $displayName): string
    {
        $parts = preg_split('/\s+/', trim($displayName));

        if (count($parts) < 2) {
            return $displayName;
        }

        $firstName = $parts[0];
        $lastInitial = mb_strtoupper(mb_substr(end($parts), 0, 1));

        return "{$firstName} {$lastInitial}.";
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
}
