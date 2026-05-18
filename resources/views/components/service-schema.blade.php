@blaze(memo: true)
@props([
    'service' => [],
    'area' => null,
])

@php
$city = $area?->city;

if ($area && $area->latitude && $area->longitude) {
    // GeoCircle is the strongest "we serve here" signal Google understands.
    // Includes a 5-mile radius around the city centroid plus the city/region/country.
    $areaServed = [
        '@type' => 'GeoCircle',
        'name'  => "{$city}, IL service area",
        'geoMidpoint' => [
            '@type' => 'GeoCoordinates',
            'latitude'  => (float) $area->latitude,
            'longitude' => (float) $area->longitude,
            'addressCountry' => 'US',
        ],
        'geoRadius' => '8047', // ~5 miles in meters
        'description' => "Service coverage centered on {$city}, Illinois.",
    ];
} else {
    $areaServed = $city
        ? ['@type' => 'City', 'name' => $city, 'addressRegion' => 'IL', 'addressCountry' => 'US']
        : ['@type' => 'State', 'name' => 'Illinois', 'addressCountry' => 'US'];
}

$serviceTypes = [
    'kitchen-remodeling' => [
        'name' => 'Kitchen Remodeling',
        'description' => 'Complete kitchen renovation services including cabinet installation, countertop replacement, flooring, lighting, plumbing, and full kitchen redesigns.',
        'serviceType' => 'Kitchen Renovation',
        'category' => 'Home Improvement',
    ],
    'bathroom-remodeling' => [
        'name' => 'Bathroom Remodeling',
        'description' => 'Full bathroom renovation services including tile work, vanity installation, shower/tub replacement, plumbing updates, and accessibility modifications.',
        'serviceType' => 'Bathroom Renovation',
        'category' => 'Home Improvement',
    ],
    'home-remodeling' => [
        'name' => 'Home Remodeling',
        'description' => 'Comprehensive home renovation services including open concept conversions, room additions, whole-home updates, and interior remodeling.',
        'serviceType' => 'Home Renovation',
        'category' => 'Home Improvement',
    ],
    'basement-remodeling' => [
        'name' => 'Basement Remodeling',
        'description' => 'Basement finishing and renovation services transforming unused space into living areas, home offices, entertainment rooms, and guest suites.',
        'serviceType' => 'Basement Finishing',
        'category' => 'Home Improvement',
    ],
];

$serviceKey = $service['projectType'] ?? 'kitchen';
$serviceSlug = match($serviceKey) {
    'kitchen' => 'kitchen-remodeling',
    'bathroom' => 'bathroom-remodeling',
    'home-remodel' => 'home-remodeling',
    default => 'basement-remodeling',
};

$serviceData = $serviceTypes[$serviceSlug] ?? $serviceTypes['kitchen-remodeling'];

// Build URL - use area service URL if area provided
$serviceUrl = $area 
    ? $area->serviceUrl($serviceSlug)
    : url("/services/{$serviceSlug}");

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => $city ? "{$city} {$serviceData['name']}" : $serviceData['name'],
    'description' => $service['description'] ?? $service['metaDescription'] ?? $serviceData['description'],
    'serviceType' => $serviceData['serviceType'],
    'category' => $serviceData['category'],
    'provider' => [
        // Bare JSON-LD reference — the full HomeAndConstructionBusiness node is
        // emitted once globally by <x-schema-org /> in the app layout.
        '@id' => 'https://gs.construction/#business',
    ],
    'areaServed' => $areaServed,
    'url' => $serviceUrl,
];

// Per-service AggregateRating: filter testimonials by project_type.
// Cached 24h. Only emit when ≥3 reviews exist for this service type.
$serviceReviewCount = cache()->remember(
    "service:{$serviceKey}:review_count",
    86400,
    fn () => \App\Models\Testimonial::visible()
        ->where('project_type', $serviceKey)
        ->count()
);
if ($serviceReviewCount >= 3) {
    $schema['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => '5',
        'bestRating' => '5',
        'worstRating' => '1',
        'ratingCount' => $serviceReviewCount,
        'reviewCount' => $serviceReviewCount,
    ];
}

// City-anchored Offer with free-estimate price signal — strengthens local intent matching.
if ($city) {
    $schema['offers'] = [
        '@type' => 'Offer',
        'name'  => "Free in-home {$serviceData['name']} estimate in {$city}, IL",
        'price' => '0',
        'priceCurrency' => 'USD',
        'availability'  => 'https://schema.org/InStock',
        'areaServed'    => $areaServed,
        'url'           => $serviceUrl,
        'seller'        => ['@id' => 'https://gs.construction/#business'],
    ];
}
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
