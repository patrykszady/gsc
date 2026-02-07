@props(['testimonials' => collect(), 'testimonial' => null])

@php
// Handle single testimonial or collection
$items = $testimonial ? collect([$testimonial]) : $testimonials;
@endphp

@if($items->count() > 0)
@php
$reviews = [];

foreach ($items as $item) {
    $review = [
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
    
    // Add location if available
    if ($item->project_location) {
        $review['locationCreated'] = [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => preg_replace('/,\s*[A-Z]{2}$/', '', $item->project_location),
                'addressRegion' => 'IL',
                'addressCountry' => 'US',
            ],
        ];
    }
    
    // Add about (the service reviewed) if project type is available
    if ($item->project_type) {
        $serviceType = match(strtolower($item->project_type)) {
            'kitchen', 'kitchens' => 'Kitchen Remodeling',
            'bathroom', 'bathrooms' => 'Bathroom Remodeling',
            'basement', 'basements' => 'Basement Remodeling',
            'addition', 'additions' => 'Home Addition',
            'mudroom', 'mudrooms', 'laundry' => 'Mudroom & Laundry Remodeling',
            default => ucfirst($item->project_type) . ' Remodeling',
        };
        
        $review['about'] = [
            '@type' => 'Service',
            'name' => $serviceType,
            'provider' => [
                '@id' => 'https://gs.construction/#business',
            ],
        ];
    }
    
    $reviews[] = $review;
}

// For single review, output directly; for multiple, use @graph
$schema = count($reviews) === 1 
    ? array_merge(['@context' => 'https://schema.org'], $reviews[0])
    : ['@context' => 'https://schema.org', '@graph' => $reviews];
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endif
