@props([
    'service' => [],
    'serviceSlug' => null,
])

@php
    $process = $service['process'] ?? [];

    if (empty($process)) {
        return;
    }

    $slug = $serviceSlug ?: ($service['urlSlug'] ?? null);

    if (! $slug) {
        $projectType = $service['projectType'] ?? 'kitchen';
        $slug = match ($projectType) {
            'kitchen' => 'kitchen-remodeling',
            'bathroom' => 'bathroom-remodeling',
            'home-remodel' => 'home-remodeling',
            default => 'home-remodeling',
        };
    }

    $url = url('/services/' . $slug);
    $label = $service['title'] ?? $service['label'] ?? 'Remodeling';

    [$minCost, $maxCost] = match ($slug) {
        'kitchen-remodeling' => [35000, 120000],
        'bathroom-remodeling' => [18000, 60000],
        'home-remodeling' => [50000, 250000],
        'basement-remodeling' => [25000, 90000],
        'home-additions' => [60000, 275000],
        'mudroom-remodeling' => [8000, 25000],
        default => [25000, 100000],
    };

    $steps = collect($process)->values()->map(fn ($s, $i) => [
        '@type' => 'HowToStep',
        'position' => $i + 1,
        'name' => $s['title'] ?? ('Step ' . ($i + 1)),
        'text' => $s['description'] ?? '',
        'url' => $url . '#step-' . ($i + 1),
    ])->all();

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'HowTo',
        '@id' => $url . '#howto',
        'name' => 'How GS Construction completes a ' . $label . ' project',
        'description' => 'Our ' . count($steps) . '-step ' . strtolower($label) . ' process in the Chicago suburbs — consultation, design, build, and final walkthrough.',
        'image' => asset('images/greg-patryk.jpg'),
        'totalTime' => 'P6W',
        'estimatedCost' => [
            '@type' => 'MonetaryAmount',
            'currency' => 'USD',
            'value' => [
                '@type' => 'QuantitativeValue',
                'minValue' => $minCost,
                'maxValue' => $maxCost,
            ],
        ],
        'tool' => [
            ['@type' => 'HowToTool', 'name' => 'Licensed general contractor'],
            ['@type' => 'HowToTool', 'name' => 'Licensed plumber'],
            ['@type' => 'HowToTool', 'name' => 'Licensed electrician'],
        ],
        'inLanguage' => 'en-US',
        'about' => ['@id' => 'https://gs.construction/#business'],
        'step' => $steps,
    ];
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
