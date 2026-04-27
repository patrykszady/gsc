@blaze(memo: true)
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
    // Resolve a GeoCoordinates payload from the project's location, if available.
    $contentLocation = null;
    if ($project->location) {
        $cityRaw = trim(preg_replace('/,\s*[A-Z]{2}$/', '', $project->location));
        $area = \App\Models\AreaServed::query()
            ->where('city', $cityRaw)
            ->orWhere('city', 'LIKE', $cityRaw.'%')
            ->first();
        if ($area) {
            $contentLocation = [
                '@type'   => 'Place',
                'name'    => $area->city.', IL',
                'address' => [
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $area->city,
                    'addressRegion'   => 'IL',
                    'addressCountry'  => 'US',
                ],
            ];
            if (! empty($area->latitude) && ! empty($area->longitude)) {
                $contentLocation['geo'] = [
                    '@type'     => 'GeoCoordinates',
                    'latitude'  => (float) $area->latitude,
                    'longitude' => (float) $area->longitude,
                ];
            }
        }
    }

    $schema['associatedMedia'] = $project->images->map(function ($img, $i) use ($contentLocation) {
        $obj = [
            '@type'       => 'ImageObject',
            'url'         => $img->url,
            'contentUrl'  => $img->url,
            'name'        => $img->seo_alt_text,
            'description' => $img->caption ?? $img->seo_alt_text,
            'position'    => $i + 1,
        ];
        if ($contentLocation) {
            $obj['contentLocation'] = $contentLocation;
        }
        return $obj;
    })->toArray();
}

// Filter null values from keywords
$schema['keywords'] = array_values(array_filter($schema['keywords']));

// Article schema (BlogPosting-style) for AI Overview "recent work" surfacing.
$canonicalUrl = url('/projects/' . $project->slug);
$article = [
    '@context'        => 'https://schema.org',
    '@type'           => 'Article',
    'headline'        => $project->title,
    'description'     => $project->description ?? "A {$project->project_type} remodeling project by GS Construction",
    'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id'   => $canonicalUrl,
    ],
    'url'             => $canonicalUrl,
    'inLanguage'      => 'en-US',
    'isPartOf'        => ['@id' => 'https://gs.construction/#website'],
    'about'           => ['@id' => 'https://gs.construction/#business'],
    'articleSection'  => ucfirst(str_replace('-', ' ', $project->project_type)) . ' Remodeling',
    'datePublished'   => $project->completed_at?->toIso8601String() ?? $project->created_at->toIso8601String(),
    'dateModified'    => $project->updated_at->toIso8601String(),
    'author'          => [
        '@type' => 'Organization',
        '@id'   => 'https://gs.construction/#organization',
        'name'  => 'GS Construction',
        'url'   => 'https://gs.construction',
    ],
    'publisher'       => ['@id' => 'https://gs.construction/#organization'],
    'image'           => $coverImage ? [$coverImage->url] : [asset('images/greg-patryk.jpg')],
    'keywords'        => $schema['keywords'],
];
@endphp

<script type="application/ld+json">
{!! json_encode($article, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endif
