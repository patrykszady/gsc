<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['testimonials' => collect()]));

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

foreach (array_filter((['testimonials' => collect()]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($testimonials->count() > 0): ?>
<?php
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
?>

<script type="application/ld+json">
<?php echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>

</script>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/review-schema.blade.php ENDPATH**/ ?>