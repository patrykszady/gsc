<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'service' => [],
    'area' => null,
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'service' => [],
    'area' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
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

// Route names use short keys (kitchen, bathroom, home, basement)
$routeKey = match($serviceKey) {
    'kitchen' => 'kitchen',
    'bathroom' => 'bathroom',
    'home-remodel' => 'home',
    default => 'basement',
};

$serviceData = $serviceTypes[$serviceSlug] ?? $serviceTypes['kitchen-remodeling'];

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => $city ? "{$city} {$serviceData['name']}" : $serviceData['name'],
    'description' => $service['metaDescription'] ?? $serviceData['description'],
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
    'url' => $area 
        ? route("area.services.{$routeKey}", $area) 
        : route("services.{$routeKey}"),
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
    'hasOfferCatalog' => [
        '@type' => 'OfferCatalog',
        'name' => "{$serviceData['name']} Services",
        'itemListElement' => array_map(fn($feature) => [
            '@type' => 'Offer',
            'itemOffered' => [
                '@type' => 'Service',
                'name' => $feature['title'],
                'description' => $feature['description'],
            ],
        ], $service['features'] ?? []),
    ],
];

// Add aggregate rating if we have testimonials
$reviewCount = \App\Models\Testimonial::where('project_type', $serviceKey)->count();
if ($reviewCount > 0) {
    $schema['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => '5',
        'bestRating' => '5',
        'worstRating' => '1',
        'ratingCount' => $reviewCount,
    ];
}
?>

<script type="application/ld+json">
<?php echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>

</script>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/service-schema.blade.php ENDPATH**/ ?>