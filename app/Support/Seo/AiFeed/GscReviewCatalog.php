<?php

namespace App\Support\Seo\AiFeed;

use App\Models\Testimonial;
use SsSystems\Platform\Seo\AiFeed\Contracts\ReviewCatalog;

/** Moved verbatim out of the old `AiFeedController` closure — same query, same row shape. */
final class GscReviewCatalog implements ReviewCatalog
{
    public function reviews(): array
    {
        return Testimonial::visible()
            ->latest('review_date')
            ->limit(50)
            ->get()
            ->map(fn (Testimonial $t): array => array_filter([
                'id' => $t->id,
                'author' => $t->display_name,
                'rating' => $t->star_rating,
                'review_date' => optional($t->review_date)->toDateString(),
                'project_type' => $t->project_type,
                'location' => $t->project_location,
                'body' => $t->review_description,
                'sources' => $t->reviewUrls->map(fn ($u) => [
                    'platform' => $u->platform,
                    'url' => $u->url,
                ])->values()->all(),
            ]))
            ->values()
            ->all();
    }
}
