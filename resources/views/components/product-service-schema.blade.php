@blaze(memo: true)
@props([
    'serviceSlug' => 'kitchen-remodeling', // kitchen-remodeling | bathroom-remodeling | home-remodeling | basement-remodeling | home-additions
    'area' => null,                         // optional AreaServed for city-scoped Product
])

{{--
    Product schema per remodeling service.

    Why Product (and not just Service)?
      • Google deprecated self-serving LocalBusiness review rich snippets in 2019.
      • `Service` schema is consumed by Google but does NOT render star ratings in SERPs.
      • `Product` is the only schema type that reliably triggers review snippets for
        contractors today. Google's docs (2023) extended Product to cover services.
      • We continue to emit Service + LocalBusiness elsewhere; this is an additive node.

    Rich-result rules:
      • Reviews are nested under the Product (the parent IS the itemReviewed).
      • Nested Review MUST NOT contain its own `itemReviewed` field — that triggers
        the "directional conflict" warning in Search Console.
      • aggregateRating + offers + image + brand all required for richest treatment.
--}}

@php
    use App\Models\Testimonial;
    use Illuminate\Support\Facades\Cache;

    // Map URL slug → testimonial.project_type bucket.
    $typeMap = [
        'kitchen-remodeling'  => 'kitchen',
        'bathroom-remodeling' => 'bathroom',
        'home-remodeling'     => 'home-remodel',
        'basement-remodeling' => 'basement',
        'home-additions'      => 'addition',
    ];
    $projectType = $typeMap[$serviceSlug] ?? 'kitchen';

    // Display copy per service.
    $copy = [
        'kitchen-remodeling' => [
            'name'        => 'Kitchen Remodeling',
            'description' => 'Full kitchen remodels in the Chicago suburbs: custom cabinetry, quartz/granite countertops, tile backsplashes, flooring, lighting, and appliance installation. Licensed, insured, 40+ years combined experience.',
            'image'       => 'images/services/kitchen-hero.jpg',
            'lowPrice'    => '15000',
            'highPrice'   => '120000',
        ],
        'bathroom-remodeling' => [
            'name'        => 'Bathroom Remodeling',
            'description' => 'Bathroom renovations including tile work, vanities, walk-in showers, tub-to-shower conversions, plumbing updates, and aging-in-place accessibility upgrades.',
            'image'       => 'images/services/bathroom-hero.jpg',
            'lowPrice'    => '8000',
            'highPrice'   => '45000',
        ],
        'home-remodeling' => [
            'name'        => 'Whole-Home Remodeling',
            'description' => 'Comprehensive home renovations: open-concept conversions, full-floor remodels, interior reconfigurations, finishes, and millwork.',
            'image'       => 'images/services/home-hero.jpg',
            'lowPrice'    => '40000',
            'highPrice'   => '350000',
        ],
        'basement-remodeling' => [
            'name'        => 'Basement Remodeling',
            'description' => 'Basement finishing: framing, drywall, flooring, egress, wet bars, home theaters, and in-law suites.',
            'image'       => 'images/services/basement-hero.jpg',
            'lowPrice'    => '20000',
            'highPrice'   => '90000',
        ],
        'home-additions' => [
            'name'        => 'Home Additions',
            'description' => 'Room additions and home expansions: kitchen extensions, primary-suite additions, second-story builds, and mudrooms.',
            'image'       => 'images/services/home-additions-hero.jpg',
            'lowPrice'    => '60000',
            'highPrice'   => '275000',
        ],
    ];
    $c = $copy[$serviceSlug] ?? $copy['kitchen-remodeling'];

    $city  = $area?->city;
    $name  = $city ? "{$c['name']} in {$city}, IL" : "{$c['name']} — Chicago Suburbs";
    $url   = $area ? $area->serviceUrl($serviceSlug) : url("/services/{$serviceSlug}");

    // Pull aggregate + top reviews for this project_type. Cache 6h.
    $cacheKey = "product_schema:reviews:{$projectType}";
    $data = Cache::remember($cacheKey, 21600, function () use ($projectType) {
        $q = Testimonial::query()
            ->visible()
            ->where('project_type', $projectType);
        $count = (clone $q)->count();
        $avg   = (float) ((clone $q)->avg('star_rating') ?: 5.0);
        $items = (clone $q)
            ->whereNotNull('review_description')
            ->where('review_description', '!=', '')
            ->orderByDesc('review_date')
            ->limit(5)
            ->get(['id', 'reviewer_name', 'star_rating', 'review_description', 'review_date', 'created_at']);
        return [
            'count' => $count,
            'avg'   => round($avg, 1),
            'items' => $items,
        ];
    });

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        '@id'         => $url . '#product',
        'name'        => $name,
        'description' => $c['description'],
        'image'       => asset($c['image']),
        'url'         => $url,
        'category'    => 'Home Improvement',
        'brand'       => [
            '@type' => 'Brand',
            'name'  => 'GS Construction',
        ],
        'manufacturer' => [
            '@id' => 'https://gs.construction/#business',
        ],
        'offers' => [
            '@type'         => 'AggregateOffer',
            'priceCurrency' => 'USD',
            'lowPrice'      => $c['lowPrice'],
            'highPrice'     => $c['highPrice'],
            'offerCount'    => '1',
            'availability'  => 'https://schema.org/InStock',
            'url'           => $url,
            'seller'        => ['@id' => 'https://gs.construction/#business'],
            'areaServed'    => $city
                ? ['@type' => 'City', 'name' => $city, 'addressRegion' => 'IL', 'addressCountry' => 'US']
                : ['@type' => 'State', 'name' => 'Illinois', 'addressCountry' => 'US'],
        ],
    ];

    if (($data['count'] ?? 0) >= 3) {
        $schema['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => (string) $data['avg'],
            'bestRating'  => '5',
            'worstRating' => '1',
            'ratingCount' => (string) $data['count'],
            'reviewCount' => (string) $data['count'],
        ];

        $schema['review'] = collect($data['items'])->map(function ($t) {
            // Per Google: nested Review under Product MUST NOT have itemReviewed.
            return [
                '@type'        => 'Review',
                'reviewRating' => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) ($t->star_rating ?? 5),
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ],
                'author'        => [
                    '@type' => 'Person',
                    'name'  => trim((string) ($t->reviewer_name ?? 'Verified Customer')) ?: 'Verified Customer',
                ],
                'reviewBody'    => \Illuminate\Support\Str::limit((string) $t->review_description, 500),
                'datePublished' => optional($t->review_date ?? $t->created_at)->toDateString(),
            ];
        })->values()->all();
    }
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
