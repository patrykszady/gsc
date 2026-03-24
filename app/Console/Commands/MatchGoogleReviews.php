<?php

namespace App\Console\Commands;

use App\Models\ReviewUrl;
use App\Models\Testimonial;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MatchGoogleReviews extends Command
{
    protected $signature = 'google-business-profile:match-reviews
        {--dry-run : Show what would be changed without writing to DB}
        {--clean-urls : Remove generic Google review URLs after matching}';

    protected $description = 'Match Google Business Profile reviews with existing testimonials and assign google_review_id.';

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

        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

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
        $this->newLine();

        // Load all testimonials
        $testimonials = Testimonial::all();
        $matched = 0;
        $alreadyMatched = 0;
        $unmatched = [];

        foreach ($reviews as $review) {
            $reviewId = $this->extractReviewId($review['name'] ?? '');
            if (! $reviewId) {
                continue;
            }

            $displayName = $review['reviewer']['displayName'] ?? 'Google Reviewer';
            $formattedName = $this->formatReviewerName($displayName);
            $starRating = self::STAR_RATINGS[$review['starRating'] ?? ''] ?? null;
            $reviewDate = isset($review['createTime'])
                ? Carbon::parse($review['createTime'])->toDateString()
                : null;
            $comment = $review['comment'] ?? '';

            // Check if already matched by google_review_id
            $existing = $testimonials->firstWhere('google_review_id', $reviewId);
            if ($existing) {
                $alreadyMatched++;

                continue;
            }

            // Try to match by name + date
            $match = $this->findMatch($testimonials, $formattedName, $displayName, $reviewDate, $comment);

            if ($match) {
                $this->line("{$prefix}Match: Google \"{$displayName}\" ({$reviewDate}) → DB #{$match->id} \"{$match->reviewer_name}\" ({$match->review_date?->format('Y-m-d')})");
                if (! $dryRun) {
                    $match->update(['google_review_id' => $reviewId]);
                }
                $matched++;
            } else {
                $unmatched[] = [
                    'name' => $displayName,
                    'formatted' => $formattedName,
                    'date' => $reviewDate,
                    'stars' => $starRating,
                    'comment' => mb_substr($comment, 0, 60),
                    'review_id' => $reviewId,
                ];
            }
        }

        // Clean up generic Google review URLs if requested
        $urlsCleaned = 0;
        if ($this->option('clean-urls')) {
            $this->newLine();
            $this->info('Cleaning generic Google review URLs...');
            $genericUrls = ReviewUrl::where('platform', 'google')
                ->where('url', 'like', '%maps.app.goo.gl%')
                ->get();

            foreach ($genericUrls as $url) {
                $this->line("{$prefix}Remove: #{$url->id} (testimonial #{$url->testimonial_id}) → {$url->url}");
                if (! $dryRun) {
                    $url->delete();
                }
                $urlsCleaned++;
            }
        }

        // ── Fetch review URLs from Places API (New) ──
        $this->newLine();
        $this->info('Fetching review URLs from Google Places API...');
        $urlsLinked = 0;

        // Auto-detect Place ID if not configured
        if (! config('services.google.business_profile.place_id')) {
            $this->line('Place ID not set in .env, fetching from GBP API...');
            $detectedPlaceId = $service->fetchPlaceId();
            if ($detectedPlaceId) {
                $this->info("Detected Place ID: {$detectedPlaceId}");
                config(['services.google.business_profile.place_id' => $detectedPlaceId]);
                $this->warn("Add this to your .env: GOOGLE_BUSINESS_PROFILE_PLACE_ID={$detectedPlaceId}");
            } else {
                $error = $service->getLastError();
                $this->warn('Could not auto-detect Place ID: ' . ($error['message'] ?? 'Unknown error'));
            }
        }

        $placeReviews = $service->fetchPlaceReviews();

        if ($placeReviews === null) {
            $error = $service->getLastError();
            $this->warn('Could not fetch Places API reviews: ' . ($error['message'] ?? 'Unknown error'));
            $this->warn('Set GOOGLE_BUSINESS_PROFILE_PLACE_ID in .env to enable review URL fetching.');
        } else {
            $this->info(count($placeReviews) . ' review(s) fetched from Places API (max 5).');

            // Reload testimonials to include any newly matched google_review_ids
            $testimonials = Testimonial::with('reviewUrls')->get();

            foreach ($placeReviews as $placeReview) {
                if (empty($placeReview['googleMapsUri'])) {
                    continue;
                }

                $match = $this->matchPlaceReviewToTestimonial($testimonials, $placeReview);

                if (! $match) {
                    $this->line("  No match for Places review by \"{$placeReview['authorName']}\"");

                    continue;
                }

                // Check if this testimonial already has this exact URL
                $existingUrl = $match->reviewUrls->first(fn ($u) => $u->url === $placeReview['googleMapsUri']);
                if ($existingUrl) {
                    continue;
                }

                $this->line("{$prefix}Link: #{$match->id} \"{$match->reviewer_name}\" → {$placeReview['googleMapsUri']}");
                if (! $dryRun) {
                    // Remove any existing generic Google URL for this testimonial
                    $match->reviewUrls()->where('platform', 'google')->delete();
                    $match->reviewUrls()->create([
                        'platform' => 'google',
                        'url' => $placeReview['googleMapsUri'],
                    ]);
                }
                $urlsLinked++;
            }
        }

        // Summary
        $this->newLine();
        $this->info("{$prefix}Summary:");
        $this->line("  Already matched (has google_review_id): {$alreadyMatched}");
        $this->line("  Newly matched: {$matched}");
        $this->line("  Unmatched Google reviews: " . count($unmatched));
        if ($urlsCleaned) {
            $this->line("  Generic URLs removed: {$urlsCleaned}");
        }
        $this->line("  Review URLs linked (Places API): {$urlsLinked}");

        if (count($unmatched)) {
            $this->newLine();
            $this->warn('Unmatched Google reviews (no DB testimonial found):');
            foreach ($unmatched as $u) {
                $this->line("  {$u['name']} ({$u['formatted']}) — {$u['date']} — {$u['stars']}★ — \"{$u['comment']}...\"");
            }
        }

        // Show testimonials still without google_review_id
        $stillMissing = Testimonial::whereNull('google_review_id')->count();
        if ($stillMissing) {
            $this->newLine();
            $this->warn("{$stillMissing} testimonial(s) still without google_review_id (may be manually added reviews).");
        }

        return self::SUCCESS;
    }

    /**
     * Try to match a Google review to an existing testimonial.
     */
    protected function findMatch($testimonials, string $formattedName, string $displayName, ?string $reviewDate, string $comment)
    {
        $unmatched = $testimonials->whereNull('google_review_id');

        // 1. Exact formatted name + date
        if ($reviewDate) {
            $match = $unmatched->first(function ($t) use ($formattedName, $reviewDate) {
                return mb_strtolower($t->reviewer_name) === mb_strtolower($formattedName)
                    && $t->review_date?->toDateString() === $reviewDate;
            });
            if ($match) {
                return $match;
            }
        }

        // 2. Exact formatted name (no date check)
        $nameMatches = $unmatched->filter(fn ($t) => mb_strtolower($t->reviewer_name) === mb_strtolower($formattedName));
        if ($nameMatches->count() === 1) {
            return $nameMatches->first();
        }

        // 3. First name match (for "John D." matching "John D." with different casing etc.)
        $firstNameMatches = $unmatched->filter(function ($t) use ($formattedName) {
            $dbFirst = mb_strtolower(explode(' ', $t->reviewer_name)[0] ?? '');
            $apiFirst = mb_strtolower(explode(' ', $formattedName)[0] ?? '');

            return $dbFirst === $apiFirst
                && mb_strtolower(mb_substr($t->reviewer_name, -2)) === mb_strtolower(mb_substr($formattedName, -2));
        });
        if ($firstNameMatches->count() === 1) {
            return $firstNameMatches->first();
        }

        // 4. Content similarity (substring of comment matches review_description)
        if (mb_strlen($comment) > 20) {
            $snippet = mb_strtolower(mb_substr($comment, 0, 50));
            $contentMatch = $unmatched->first(fn ($t) => str_contains(mb_strtolower($t->review_description), $snippet));
            if ($contentMatch) {
                return $contentMatch;
            }
        }

        return null;
    }

    /**
     * Match a Places API review to a DB testimonial by author name and content.
     */
    protected function matchPlaceReviewToTestimonial($testimonials, array $placeReview)
    {
        $authorName = $placeReview['authorName'] ?? '';
        $formattedName = $this->formatReviewerName($authorName);
        $text = $placeReview['text'] ?? '';
        $publishDate = ! empty($placeReview['publishTime'])
            ? Carbon::parse($placeReview['publishTime'])->toDateString()
            : null;

        // 1. Match by formatted name + date
        if ($publishDate) {
            $match = $testimonials->first(function ($t) use ($formattedName, $publishDate) {
                return mb_strtolower($t->reviewer_name) === mb_strtolower($formattedName)
                    && $t->review_date?->toDateString() === $publishDate;
            });
            if ($match) {
                return $match;
            }
        }

        // 2. Match by formatted name (unique)
        $nameMatches = $testimonials->filter(fn ($t) => mb_strtolower($t->reviewer_name) === mb_strtolower($formattedName));
        if ($nameMatches->count() === 1) {
            return $nameMatches->first();
        }

        // 3. Match by content (first 50 chars)
        if (mb_strlen($text) > 20) {
            $snippet = mb_strtolower(mb_substr($text, 0, 50));
            $contentMatch = $testimonials->first(fn ($t) => str_contains(mb_strtolower($t->review_description), $snippet));
            if ($contentMatch) {
                return $contentMatch;
            }
        }

        return null;
    }

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

    protected function extractReviewId(string $resourceName): ?string
    {
        if (! $resourceName) {
            return null;
        }

        $parts = explode('/', $resourceName);

        return end($parts) ?: null;
    }
}
