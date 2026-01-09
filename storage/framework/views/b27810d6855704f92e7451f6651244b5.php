<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['testimonials' => collect(), 'testimonial' => null]));

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

foreach (array_filter((['testimonials' => collect(), 'testimonial' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
// Handle single testimonial or collection
$items = $testimonial ? collect([$testimonial]) : $testimonials;
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($items->count() > 0): ?>
<?php
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
        'itemReviewed' => [
            '@type' => 'HomeAndConstructionBusiness',
            'name' => 'GS Construction & Remodeling',
            '@id' => 'https://gs.construction/#business',
        ],
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
?>

<script type="application/ld+json">
<?php echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>

</script>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/review-schema.blade.php ENDPATH**/ ?>