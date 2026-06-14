@php
use App\Models\Testimonial;
use App\Models\AreaServed;
use App\Models\ProjectImage;

$reviewCount = Testimonial::count();
$areas = AreaServed::pluck('city')->toArray();

// Curated gallery for the LocalBusiness entity image. Google frequently sources
// the SERP result thumbnail from the business `image` — actual remodeling photos
// from our FEATURED projects represent the company far better than the logo or
// owner headshot. Provide several (Google accepts an array and may choose the
// best per query/format). Cached 1h; falls back to the owner photo only when no
// project covers exist at all.
$businessImages = cache()->remember('schema:business_images', 3600, function () {
    return ProjectImage::curatedCovers(null, 6)
        ->map(fn ($img) => $img->url)
        ->filter()
        ->values()
        ->all();
});
if (empty($businessImages)) {
    $businessImages = [asset('images/greg-patryk.jpg')];
}

// Raster logo for Organization/logo structured data. Google's logo guidelines
// require a crawlable raster (PNG/JPG) — an SVG can be ignored — so we point the
// machine-readable logo at the PNG app icon while the UI still uses the SVG.
$logoPng = asset('android-chrome-512x512.png');

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
            'name' => $item->display_name,
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
    'name' => 'GS Construction',
    'alternateName' => 'GS Construction & Remodeling',
    'description' => 'Professional kitchen, bathroom, and home remodeling services. Family-owned business serving the Chicagoland area with over 40 years of combined experience.',
    'url' => 'https://gs.construction',
    'logo' => [
        '@type' => 'ImageObject',
        'url' => $logoPng,
        'contentUrl' => $logoPng,
    ],
    'image' => array_map(fn ($url) => [
        '@type' => 'ImageObject',
        'url' => $url,
        'contentUrl' => $url,
        'caption' => 'GS Construction — Kitchen & Bathroom Remodeling in Chicago Suburbs',
    ], $businessImages),
    'telephone' => '+1-224-735-4200',
    'email' => 'crew@gs.construction',
    'foundingDate' => '2015',
    'foundingLocation' => [
        '@type' => 'Place',
        'name' => 'Prospect Heights, IL',
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => 'Prospect Heights',
            'addressRegion' => 'IL',
            'postalCode' => '60070',
            'addressCountry' => 'US',
        ],
    ],
    'numberOfEmployees' => [
        '@type' => 'QuantitativeValue',
        'minValue' => 5,
        'maxValue' => 15,
    ],
    'knowsLanguage' => ['English', 'Polish'],
    'founder' => [
        ['@id' => 'https://gs.construction/#person-gregory'],
        ['@id' => 'https://gs.construction/#person-patryk'],
    ],
    'employee' => [
        ['@id' => 'https://gs.construction/#person-gregory'],
        ['@id' => 'https://gs.construction/#person-patryk'],
    ],
    'slogan' => 'Quality remodeling, family-owned since 2015.',
    'paymentAccepted' => 'Cash, Check, Credit Card, ACH Transfer',
    'currenciesAccepted' => 'USD',
    // $$$ = mid-to-high range; $$$$ overstates and hurts AI Overview accuracy.
    'priceRange' => '$$$',
    'award' => array_values(array_filter([
        '40+ years combined remodeling experience',
        '5-star rated on Google, Yelp, and Houzz',
    ])),
    'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => 'Prospect Heights',
        'addressRegion' => 'IL',
        'postalCode' => '60070',
        'addressCountry' => 'US',
    ],
    'geo' => [
        '@type' => 'GeoCoordinates',
        'latitude' => 42.0953,
        'longitude' => -87.9376,
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
                    'url' => url('/services/kitchen-remodeling'),
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => [
                        '@type' => 'State',
                        'name' => 'Illinois',
                        'addressCountry' => 'US',
                    ],
                    'serviceType' => 'Kitchen Remodeling',
                ],
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Bathroom Remodeling',
                    'description' => 'Full bathroom renovation services including tile work, vanities, showers, bathtubs, and fixtures.',
                    'url' => url('/services/bathroom-remodeling'),
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => [
                        '@type' => 'State',
                        'name' => 'Illinois',
                        'addressCountry' => 'US',
                    ],
                    'serviceType' => 'Bathroom Remodeling',
                ],
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Home Remodeling',
                    'description' => 'Comprehensive home renovation and remodeling services for complete home transformations.',
                    'url' => url('/services/home-remodeling'),
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => [
                        '@type' => 'State',
                        'name' => 'Illinois',
                        'addressCountry' => 'US',
                    ],
                    'serviceType' => 'Home Remodeling',
                ],
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Basement Remodeling',
                    'description' => 'Basement finishing and renovation services to transform unused space into living areas.',
                    'url' => url('/services/basement-remodeling'),
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => [
                        '@type' => 'State',
                        'name' => 'Illinois',
                        'addressCountry' => 'US',
                    ],
                    'serviceType' => 'Basement Remodeling',
                ],
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Home Additions',
                    'description' => 'Room additions and home expansion services to increase your living space.',
                    'url' => url('/services/home-additions'),
                    'provider' => ['@id' => 'https://gs.construction/#business'],
                    'areaServed' => [
                        '@type' => 'State',
                        'name' => 'Illinois',
                        'addressCountry' => 'US',
                    ],
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
    '@id' => 'https://gs.construction/#organization',
    'name' => 'GS Construction',
    'alternateName' => 'GS Construction & Remodeling',
    'url' => 'https://gs.construction',
    'logo' => [
        '@type' => 'ImageObject',
        '@id' => 'https://gs.construction/#logo',
        'url' => $logoPng,
        'contentUrl' => $logoPng,
        'caption' => 'GS Construction',
    ],
    'image' => ['@id' => 'https://gs.construction/#logo'],
    'sameAs' => array_filter([
        config('socials.facebook.url'),
        config('socials.instagram.url'),
        config('socials.google.url'),
        config('socials.houzz.url'),
        config('socials.yelp.url'),
        config('socials.angi.url'),
    ]),
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'telephone' => '+1-224-735-4200',
        'contactType' => 'customer service',
        'email' => 'crew@gs.construction',
        'areaServed' => 'US',
        'availableLanguage' => ['English', 'Polish'],
    ],
    'founder' => [
        ['@id' => 'https://gs.construction/#person-gregory'],
        ['@id' => 'https://gs.construction/#person-patryk'],
    ],
    'employee' => [
        ['@id' => 'https://gs.construction/#person-gregory'],
        ['@id' => 'https://gs.construction/#person-patryk'],
    ],
];

// WebSite schema for sitelinks search + site name display.
//   • Google site-name rules (https://developers.google.com/search/docs/appearance/site-names):
//       - WebSite schema on homepage only
//       - `name` must be a short, brand-consistent string (no LLC / domain)
//       - `alternateName` is for ALTERNATE variants — never repeat `name`
//       - Reinforce with og:site_name + a publisher (Organization) reference
//   • `image` here is the Organization logo — helps Google build the entity card.
$website = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    '@id' => 'https://gs.construction/#website',
    'name' => 'GS Construction',
    'alternateName' => 'GS Construction & Remodeling',
    'url' => 'https://gs.construction/',
    'publisher' => ['@id' => 'https://gs.construction/#organization'],
    'image' => ['@id' => 'https://gs.construction/#logo'],
    'inLanguage' => 'en-US',
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => [
            '@type' => 'EntryPoint',
            'urlTemplate' => 'https://gs.construction/projects?search={search_term_string}',
        ],
        'query-input' => 'required name=search_term_string',
    ],
];

// WebPage schema — per-page entity with the actual page title and the same
// hero/og image we send via meta tags. `primaryImageOfPage` is the strongest
// signal we can give Google for the SERP thumbnail. Also carries the speakable
// hints for voice assistants.
$__seoBuilderInstance = app(\App\Support\SEO\SEOBuilder::class);
$__pageData = $__seoBuilderInstance->build();
$pageImage = $__pageData->image ?: asset('android-chrome-512x512.png');
$pageTitle = $__pageData->title ?: 'GS Construction';

$speakable = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    '@id' => url()->current() . '#webpage',
    'url' => url()->current(),
    'name' => $pageTitle,
    'isPartOf' => ['@id' => 'https://gs.construction/#website'],
    'about' => ['@id' => 'https://gs.construction/#business'],
    'primaryImageOfPage' => [
        '@type' => 'ImageObject',
        'url' => $pageImage,
        'contentUrl' => $pageImage,
    ],
    'image' => $pageImage,
    'inLanguage' => 'en-US',
    'speakable' => [
        '@type' => 'SpeakableSpecification',
        'cssSelector' => ['h1', 'h2', '.speakable', '[data-speakable]', '[role="main"] p:first-of-type'],
        'xpath' => ['/html/head/title'],
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

{{-- WebSite Schema (homepage-only, per Google site-names guidance:
     https://developers.google.com/search/docs/appearance/site-names) --}}
@if(request()->path() === '/')
<script type="application/ld+json">
{!! json_encode($website, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endif

{{-- Speakable Schema (AEO) --}}
<script type="application/ld+json">
{!! json_encode($speakable, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
