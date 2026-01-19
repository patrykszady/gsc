@php
use App\Models\Testimonial;
use App\Models\AreaServed;

$reviewCount = Testimonial::count();
$areas = AreaServed::pluck('city')->toArray();

// Get featured testimonials for embedding in LocalBusiness schema
// Google requires reviews to be nested in the parent entity, not standalone
$featuredTestimonials = Testimonial::latest('review_date')
    ->take(5)
    ->get();

// Build review array for LocalBusiness schema
$reviews = $featuredTestimonials->map(function ($item) {
    return [
        '@type' => 'Review',
        'reviewRating' => [
            '@type' => 'Rating',
            'ratingValue' => '5',
            'bestRating' => '5',
            'worstRating' => '1',
        ],
        'author' => [
            '@type' => 'Person',
            'name' => $item->reviewer_name,
        ],
        'reviewBody' => $item->review_description,
        'datePublished' => ($item->review_date ?? $item->created_at)->toIso8601String(),
    ];
})->toArray();

// Build the structured data
$localBusiness = [
    '@context' => 'https://schema.org',
    '@type' => 'HomeAndConstructionBusiness',
    '@id' => 'https://gs.construction/#business',
    'name' => 'GS Construction & Remodeling',
    'alternateName' => 'GS Construction',
    'description' => 'Professional kitchen, bathroom, and home remodeling services. Family-owned business serving the Chicagoland area with over 40 years of combined experience.',
    'url' => 'https://gs.construction',
    'logo' => asset('images/logo.svg'),
    'image' => asset('images/greg-patryk.jpg'),
    'telephone' => '+1-847-430-4439',
    'email' => 'patryk@gs.construction',
    'foundingDate' => '2015',
    'priceRange' => '$$$$',
    'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => 'Arlington Heights',
        'addressRegion' => 'IL',
        'addressCountry' => 'US',
    ],
    'geo' => [
        '@type' => 'GeoCoordinates',
        'latitude' => 42.0884,
        'longitude' => -87.9806,
    ],
    'areaServed' => array_map(fn($city) => [
        '@type' => 'City',
        'name' => $city,
        'addressRegion' => 'IL',
        'addressCountry' => 'US',
    ], array_slice($areas, 0, 20)), // Limit to 20 for performance
    'openingHoursSpecification' => [
        '@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'opens' => '08:00',
        'closes' => '18:00',
    ],
    'sameAs' => array_filter([
        config('socials.facebook.url'),
        config('socials.instagram.url'),
        config('socials.google.url'),
        config('socials.houzz.url'),
        config('socials.yelp.url'),
        config('socials.angi.url'),
    ]),
    'aggregateRating' => [
        '@type' => 'AggregateRating',
        'ratingValue' => '5',
        'bestRating' => '5',
        'worstRating' => '1',
        'ratingCount' => $reviewCount,
        'reviewCount' => $reviewCount,
    ],
    // Reviews must be nested inside the LocalBusiness, not standalone
    // Standalone Review with itemReviewed is invalid for rich results
    'review' => $reviews,
    'hasOfferCatalog' => [
        '@type' => 'OfferCatalog',
        'name' => 'Home Remodeling Services',
        'itemListElement' => [
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Kitchen Remodeling',
                    'description' => 'Complete kitchen renovation and remodeling services including cabinets, countertops, flooring, lighting, and appliance installation.',
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => 'Chicagoland',
                    'serviceType' => 'Kitchen Remodeling',
                ],
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Bathroom Remodeling',
                    'description' => 'Full bathroom renovation services including tile work, vanities, showers, bathtubs, and fixtures.',
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => 'Chicagoland',
                    'serviceType' => 'Bathroom Remodeling',
                ],
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Home Remodeling',
                    'description' => 'Comprehensive home renovation and remodeling services for complete home transformations.',
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => 'Chicagoland',
                    'serviceType' => 'Home Remodeling',
                ],
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Basement Remodeling',
                    'description' => 'Basement finishing and renovation services to transform unused space into living areas.',
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => 'Chicagoland',
                    'serviceType' => 'Basement Remodeling',
                ],
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Home Additions',
                    'description' => 'Room additions and home expansion services to increase your living space.',
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => 'Chicagoland',
                    'serviceType' => 'Home Addition',
                ],
            ],
        ],
    ],
];

// Organization schema
$organization = [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'GS Construction & Remodeling',
    'url' => 'https://gs.construction',
    'logo' => asset('images/logo.svg'),
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'telephone' => '+1-847-430-4439',
        'contactType' => 'customer service',
        'email' => 'patryk@gs.construction',
        'areaServed' => 'US',
        'availableLanguage' => ['English', 'Polish'],
    ],
    'founder' => [
        [
            '@type' => 'Person',
            'name' => 'Gregory',
            'jobTitle' => 'Founder',
        ],
        [
            '@type' => 'Person',
            'name' => 'Patryk',
            'jobTitle' => 'Co-Founder',
        ],
    ],
];

// WebSite schema for sitelinks search
$website = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'GS Construction & Remodeling',
    'url' => 'https://gs.construction',
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => 'https://gs.construction/projects?search={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
];
@endphp

{{-- LocalBusiness Schema --}}
<script type="application/ld+json">
{!! json_encode($localBusiness, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>

{{-- Organization Schema --}}
<script type="application/ld+json">
{!! json_encode($organization, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>

{{-- WebSite Schema --}}
<script type="application/ld+json">
{!! json_encode($website, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
