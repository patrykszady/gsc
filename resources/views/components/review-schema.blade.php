@props(['testimonials' => collect()])

@if($testimonials->count() > 0)
@php
$reviews = [];

foreach ($testimonials as $testimonial) {
    $reviews[] = [
        '@type' => 'Review',
        'reviewRating' => [
            '@type' => 'Rating',
            'ratingValue' => '5',
            'bestRating' => '5',
            'worstRating' => '1',
        ],
        'author' => [
            '@type' => 'Person',
            'name' => $testimonial->reviewer_name,
        ],
        'reviewBody' => $testimonial->review_description,
        'datePublished' => $testimonial->created_at->toIso8601String(),
        'itemReviewed' => [
            '@type' => 'HomeAndConstructionBusiness',
            'name' => 'GS Construction & Remodeling',
            '@id' => 'https://gs.construction/#business',
        ],
    ];
    
    // Add location if available
    if ($testimonial->project_location) {
        $reviews[count($reviews) - 1]['locationCreated'] = [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $testimonial->project_location,
                'addressRegion' => 'IL',
                'addressCountry' => 'US',
            ],
        ];
    }
}

$schema = [
    '@context' => 'https://schema.org',
    '@graph' => $reviews,
];
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endif
