<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['project' => null]));

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

foreach (array_filter((['project' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project): ?>
<?php
$coverImage = $project->images->where('is_cover', true)->first() ?? $project->images->first();
$images = $project->images->map(fn($img) => $img->url)->toArray();

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'CreativeWork',
    'name' => $project->title,
    'description' => $project->description ?? "A {$project->project_type} remodeling project by GS Construction",
    'creator' => [
        '@type' => 'HomeAndConstructionBusiness',
        '@id' => 'https://gs.construction/#business',
        'name' => 'GS Construction & Remodeling',
    ],
    'dateCreated' => $project->completed_at?->toIso8601String() ?? $project->created_at->toIso8601String(),
    'genre' => ucfirst(str_replace('-', ' ', $project->project_type)) . ' Remodeling',
    'keywords' => [
        $project->project_type . ' remodeling',
        'home renovation',
        $project->location ? "{$project->location} remodeling" : null,
        'GS Construction project',
    ],
];

// Add location if available
if ($project->location) {
    $schema['locationCreated'] = [
        '@type' => 'Place',
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => $project->location,
            'addressRegion' => 'IL',
            'addressCountry' => 'US',
        ],
    ];
}

// Add images
if ($coverImage) {
    $schema['image'] = $coverImage->url;
    $schema['thumbnailUrl'] = $coverImage->getThumbnailUrl('medium');
}

// Add all project images as associated media
if (count($images) > 0) {
    $schema['associatedMedia'] = array_map(fn($url, $i) => [
        '@type' => 'ImageObject',
        'url' => $url,
        'position' => $i + 1,
    ], $images, array_keys($images));
}

// Filter null values from keywords
$schema['keywords'] = array_values(array_filter($schema['keywords']));
?>

<script type="application/ld+json">
<?php echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>

</script>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/project-schema.blade.php ENDPATH**/ ?>