@blaze(memo: true)
@props(['project' => null])

@php
    if (! $project || ! $project->relationLoaded('timelapses')) {
        $timelapses = collect();
    } else {
        $timelapses = $project->timelapses->filter(fn ($t) => $t->frames->isNotEmpty())->values();
    }
@endphp

@foreach($timelapses as $idx => $timelapse)
@php
    $frames = $timelapse->frames->sortBy('sort_order')->values();
    $firstFrame = $frames->first();
    $lastFrame = $frames->last();
    $thumbUrl = $firstFrame?->url ?? asset('images/greg-patryk.jpg');

    $name = $timelapse->title ?: ($project->title . ' — Timelapse ' . ($idx + 1));
    $description = "Before-and-after timelapse of {$project->title}"
        . ($project->location ? " in {$project->location}" : '')
        . ' by GS Construction.';

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'VideoObject',
        'name' => $name,
        'description' => $description,
        'thumbnailUrl' => [$thumbUrl],
        'uploadDate' => optional($timelapse->created_at ?? $project->created_at)->toIso8601String(),
        'contentUrl' => $lastFrame?->url ?? $thumbUrl,
        'embedUrl' => url('/projects/' . $project->slug) . '#timelapse-' . $timelapse->id,
        'inLanguage' => 'en-US',
        'isFamilyFriendly' => true,
        'publisher' => ['@id' => 'https://gs.construction/#organization'],
        'creator' => ['@id' => 'https://gs.construction/#business'],
        'about' => ['@id' => 'https://gs.construction/#business'],
    ];

    if ($project->location) {
        $schema['contentLocation'] = [
            '@type' => 'Place',
            'name' => $project->location,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => preg_replace('/,\s*[A-Z]{2}$/', '', $project->location),
                'addressRegion' => 'IL',
                'addressCountry' => 'US',
            ],
        ];
    }
@endphp
<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endforeach
