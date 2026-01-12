@props([
    'service' => [],
    'area' => null,
])

@php
$city = $area?->city;
$areaServed = $city 
    ? ['@type' => 'City', 'name' => $city, 'addressRegion' => 'IL', 'addressCountry' => 'US']
    : ['@type' => 'State', 'name' => 'Illinois', 'addressCountry' => 'US'];

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

// Build URL - use area page URL if area provided
$serviceUrl = $area 
    ? $area->pageUrl($serviceSlug)
    : url("/services/{$serviceSlug}");

// Build feature list for schema - handle both string and array formats
$featureList = [];
if (!empty($service['features'])) {
    foreach ($service['features'] as $feature) {
        if (is_string($feature)) {
            $featureList[] = [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => $feature,
                ],
            ];
        } elseif (is_array($feature) && isset($feature['title'])) {
            $featureList[] = [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => $feature['title'],
                    'description' => $feature['description'] ?? '',
                ],
            ];
        }
    }
}

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => $city ? "{$city} {$serviceData['name']}" : $serviceData['name'],
    'description' => $service['description'] ?? $service['metaDescription'] ?? $serviceData['description'],
    'serviceType' => $serviceData['serviceType'],
    'category' => $serviceData['category'],
    'provider' => [
        '@type' => 'HomeAndConstructionBusiness',
        '@id' => 'https://gs.construction/#business',
        'name' => 'GS Construction & Remodeling',
        'telephone' => '+1-847-430-4439',
        'url' => 'https://gs.construction',
    ],
    'areaServed' => $areaServed,
    'url' => $serviceUrl,
    'offers' => [
        '@type' => 'Offer',
        'availability' => 'https://schema.org/InStock',
        'priceSpecification' => [
            '@type' => 'PriceSpecification',
            'priceCurrency' => 'USD',
            'eligibleTransactionVolume' => [
                '@type' => 'PriceSpecification',
                'description' => 'Free estimates available',
            ],
        ],
    ],
    'termsOfService' => 'https://gs.construction/contact',
];

// Only add hasOfferCatalog if we have features
if (!empty($featureList)) {
    $schema['hasOfferCatalog'] = [
        '@type' => 'OfferCatalog',
        'name' => "{$serviceData['name']} Services",
        'itemListElement' => $featureList,
    ];
}

// Note: aggregateRating is NOT valid on Service type for Google rich results.
// Valid parent types are: Book, Course, Event, Game, HowTo, LocalBusiness, Movie,
// Organization, Product, Recipe, SoftwareApplication, etc.
// The LocalBusiness schema in schema-org.blade.php already includes aggregateRating
// and reviews, which is the correct place for them.
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
