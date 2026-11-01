<?php

namespace App\Support\Seo;

use App\Models\Testimonial;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Seo\Intel\Contracts\OwnReviewsSource;

/**
 * This site's own Google reviews/testimonials, for the change-intel
 * `business_data` family's cross-check against what the Business Data API
 * reports Google shows (kit 0.19.2, 2026-11-01) — the same
 * `Testimonial`/`review_urls` query {@see GscMapPackContext::ourGoogleReviewCount()}
 * already runs: non-hidden testimonials carrying a `review_urls` row for
 * platform 'google'. averageRating() reads the same testimonials'
 * `star_rating` column (1-5, nullable on an import that had none).
 *
 * Unlike GscMapPackContext::ourGoogleReviewCount() (which answers 0 when
 * `review_urls` is missing, a sensible default for its own chart), this
 * answers null for "unknown" per the OwnReviewsSource contract — a source
 * reading it must omit the comparison, never treat null as a faked 0.
 */
final class GscOwnReviews implements OwnReviewsSource
{
    public function count(): ?int
    {
        if (! Schema::hasTable('review_urls')) {
            return null;
        }

        return Testimonial::query()
            ->where('is_hidden', false)
            ->whereHas('reviewUrls', fn ($q) => $q->where('platform', 'google'))
            ->count();
    }

    public function averageRating(): ?float
    {
        if (! Schema::hasTable('review_urls')) {
            return null;
        }

        $average = Testimonial::query()
            ->where('is_hidden', false)
            ->whereHas('reviewUrls', fn ($q) => $q->where('platform', 'google'))
            ->whereNotNull('star_rating')
            ->avg('star_rating');

        return $average !== null ? (float) $average : null;
    }
}
