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
        'mudroom-remodeling'  => 'mudroom',
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
        'mudroom-remodeling' => [
            'name'        => 'Mudroom & Laundry Remodeling',
            'description' => 'Custom mudroom and laundry room remodels: built-in lockers, benches, cubbies, drop zones, durable tile floors, utility sinks, and combined laundry/mudroom layouts.',
            'image'       => 'images/services/mudroom-hero.jpg',
            'lowPrice'    => '8000',
            'highPrice'   => '25000',
        ],
    ];
    $c = $copy[$serviceSlug] ?? $copy['kitchen-remodeling'];

    $city  = $area?->city;
    $name  = $city ? "{$c['name']} in {$city}, IL" : "{$c['name']} — Chicago Suburbs";
    $url   = $area ? $area->serviceUrl($serviceSlug) : url("/services/{$serviceSlug}");

    // Keep the AggregateOffer "fresh": a rolling 1-year window so Google never
    // treats the price range as an expired offer. validFrom is pinned to today.
    $validFrom       = now()->toDateString();
    $priceValidUntil = now()->addYear()->toDateString();

    // Reusable closure: aggregate + top reviews for an optional project_type.
    $pullReviews = function (?string $type) {
        $q = Testimonial::query()->visible();
        if ($type !== null) {
            $q->where('project_type', $type);
        }
        return [
            'count' => (clone $q)->count(),
            'avg'   => round((float) ((clone $q)->avg('star_rating') ?: 5.0), 1),
            'items' => (clone $q)
                ->orderByDesc('review_date')
                ->limit(5)
                ->get(['id', 'reviewer_name', 'star_rating', 'review_description', 'review_date', 'created_at']),
        ];
    };

    // Service-specific reviews (cache 6h).
    $serviceData = Cache::remember("product_schema:reviews:{$projectType}", 21600, fn () => $pullReviews($projectType));

    // Company-wide fallback. Thin service buckets (e.g. basement, additions,
    // mudroom) have <3 service-specific reviews and would otherwise emit a
    // Product node WITHOUT aggregateRating/review — which Google flags as
    // "Missing field aggregateRating / review". GS Construction is a single
    // contractor, so its overall review pool legitimately backs each service
    // Product when service-specific reviews are sparse.
    $companyData = Cache::remember('product_schema:reviews:_company', 21600, fn () => $pullReviews(null));

    // Use the service-specific aggregate only when it has enough volume to be
    // meaningful; otherwise fall back to the company-wide rating.
    $minServiceReviews = 3;
    $useService = ($serviceData['count'] ?? 0) >= $minServiceReviews;

    // Lead with service-specific testimonials, then top up from the company
    // pool (deduped) so the node always carries at least one review.
    $reviewItems = collect($serviceData['items'])
        ->concat($companyData['items'])
        ->unique('id')
        ->take(5)
        ->values();

    $data = [
        'count' => $useService ? $serviceData['count'] : $companyData['count'],
        'avg'   => $useService ? $serviceData['avg']   : $companyData['avg'],
        'items' => $reviewItems,
    ];

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
        // Truthful, on-page differentiators (stated across /projects FAQ, ZIP
        // pages, and the workmanship-warranty HowTo). Surfaced as structured
        // attributes so Google + AI Overviews can cite them. NOT fabricated
        // retail attributes (gtin/mpn/sku) — this is a service, not a SKU.
        'slogan' => 'Quality remodeling, family-owned since 2015.',
        'award'  => '5-star rated on Google, Yelp & Houzz',
        'audience' => [
            '@type'          => 'Audience',
            'audienceType'   => 'Residential homeowners',
            'geographicArea' => [
                '@type' => 'AdministrativeArea',
                'name'  => $city ? "{$city}, IL" : 'Chicagoland',
            ],
        ],
        'additionalProperty' => [
            ['@type' => 'PropertyValue', 'name' => 'Licensed, bonded & insured', 'value' => 'Yes'],
            ['@type' => 'PropertyValue', 'name' => 'Free in-home estimate',      'value' => 'Yes'],
            ['@type' => 'PropertyValue', 'name' => 'Written workmanship warranty', 'value' => 'Yes'],
            ['@type' => 'PropertyValue', 'name' => 'Experience',                  'value' => '40+ years combined'],
            ['@type' => 'PropertyValue', 'name' => 'Family-owned',                'value' => 'Yes'],
        ],
        'offers' => [
            '@type'         => 'AggregateOffer',
            'priceCurrency' => 'USD',
            'lowPrice'      => $c['lowPrice'],
            'highPrice'     => $c['highPrice'],
            'offerCount'    => '1',
            'availability'  => 'https://schema.org/InStock',
            // Accurately marks this as a service-provision offer (GoodRelations).
            'businessFunction' => 'http://purl.org/goodrelations/v1#ProvideService',
            'validFrom'        => $validFrom,
            'priceValidUntil'  => $priceValidUntil,
            'url'           => $url,
            'seller'        => ['@id' => 'https://gs.construction/#business'],
            'areaServed'    => $city
                ? ['@type' => 'City', 'name' => $city, 'addressRegion' => 'IL', 'addressCountry' => 'US']
                : ['@type' => 'State', 'name' => 'Illinois', 'addressCountry' => 'US'],
        ],
    ];

    if (($data['count'] ?? 0) >= 1 && $data['items']->isNotEmpty()) {
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
            $review = [
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
                'datePublished' => optional($t->review_date ?? $t->created_at)->toDateString(),
            ];

            $body = trim((string) ($t->review_description ?? ''));
            if ($body !== '') {
                $review['reviewBody'] = \Illuminate\Support\Str::limit($body, 500);
            }

            return $review;
        })->values()->all();
    }
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
