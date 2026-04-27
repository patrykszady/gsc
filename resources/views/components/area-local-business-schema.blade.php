@blaze(memo: true)
@props(['area' => null])

@if($area)
@php
    use App\Models\Testimonial;

    $city = $area->city;
    $slug = $area->slug;
    $entityId = url('/areas-served/' . $slug) . '#localbusiness';

    // Per-area zip codes derived from project locations + CSV (cached 24h).
    $zipCodes = $area->postalCodes();

    // Per-area aggregate rating (only if we have testimonials in this city).
    $areaReviewCount = cache()->remember(
        "area:{$area->id}:review_count",
        86400,
        fn () => Testimonial::visible()
            ->where('project_location', 'LIKE', $area->city . '%')
            ->count()
    );

    $address = [
        '@type' => 'PostalAddress',
        'addressLocality' => $city,
        'addressRegion' => 'IL',
        'addressCountry' => 'US',
    ];
    if (! empty($zipCodes)) {
        // Primary postal code on address; full list on areaServed below.
        $address['postalCode'] = $zipCodes[0];
    }

    $areaServed = [
        '@type' => 'City',
        'name' => $city,
        'addressRegion' => 'IL',
        'addressCountry' => 'US',
    ];
    if (! empty($area->latitude) && ! empty($area->longitude)) {
        $areaServed['geo'] = [
            '@type' => 'GeoCircle',
            'geoMidpoint' => [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $area->latitude,
                'longitude' => (float) $area->longitude,
            ],
            'geoRadius' => '8047',
        ];
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'HomeAndConstructionBusiness',
        '@id' => $entityId,
        'name' => "GS Construction — {$city}",
        'parentOrganization' => ['@id' => 'https://gs.construction/#organization'],
        'brand' => ['@id' => 'https://gs.construction/#business'],
        'description' => "Family-owned kitchen, bathroom, and home remodeling contractor serving {$city}, IL and the surrounding area. 40+ years combined experience, free in-home estimates.",
        'url' => url('/areas-served/' . $slug),
        'telephone' => '+1-224-735-4200',
        'email' => 'crew@gs.construction',
        'priceRange' => '$$$$',
        'image' => asset('images/greg-patryk.jpg'),
        'logo' => asset('images/logo.svg'),
        'address' => $address,
        'areaServed' => $areaServed,
        'openingHoursSpecification' => [
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            'opens' => '08:00',
            'closes' => '18:00',
        ],
        'knowsAbout' => [
            "Kitchen Remodeling in {$city}",
            "Bathroom Remodeling in {$city}",
            "Home Remodeling in {$city}",
            'Basement Finishing',
            'Custom Cabinetry',
        ],
    ];

    if (! empty($area->latitude) && ! empty($area->longitude)) {
        $schema['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $area->latitude,
            'longitude' => (float) $area->longitude,
        ];
        $schema['hasMap'] = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($city . ', IL');
    }

    if (! empty($zipCodes)) {
        // Explicitly enumerated postal codes we serve in this area.
        $schema['serviceArea'] = array_map(fn ($zip) => [
            '@type' => 'PostalCodeSpecification',
            'postalCode' => $zip,
            'addressCountry' => 'US',
        ], $zipCodes);
    }

    if ($areaReviewCount >= 3) {
        $schema['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => '5',
            'bestRating' => '5',
            'worstRating' => '1',
            'ratingCount' => $areaReviewCount,
            'reviewCount' => $areaReviewCount,
        ];
    }
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endif
