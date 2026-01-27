@props(['project' => null])

@if($project)
@php
$coverImage = $project->images->where('is_cover', true)->first() ?? $project->images->first();

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

// Add all project images as associated media with proper alt text
if ($project->images->isNotEmpty()) {
    $schema['associatedMedia'] = $project->images->map(fn($img, $i) => [
        '@type' => 'ImageObject',
        'url' => $img->url,
        'contentUrl' => $img->url,
        'name' => $img->seo_alt_text,
        'description' => $img->caption ?? $img->seo_alt_text,
        'position' => $i + 1,
    ])->toArray();
}

// Filter null values from keywords
$schema['keywords'] = array_values(array_filter($schema['keywords']));
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endif
