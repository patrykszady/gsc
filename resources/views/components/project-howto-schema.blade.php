@props(['project' => null])

@php
    if (! $project) return;

    $type   = ucfirst(str_replace('-', ' ', $project->project_type));
    $loc    = $project->location ? ' in '.$project->location.', IL' : '';
    $coverImage = $project->images->where('is_cover', true)->first() ?? $project->images->first();

    /**
     * Generic 6-step remodeling workflow that mirrors how GS Construction actually
     * runs every project. This is the "process" Google can show as a HowTo rich result.
     */
    $steps = [
        [
            'name' => 'Free in-home consultation',
            'text' => 'GS Construction visits your '.($project->location ?: 'home').' to measure the space, listen to your vision, and discuss budget, timeline, and material options.',
        ],
        [
            'name' => 'Design + 3D renderings',
            'text' => 'Our designer drafts a layout and full 3D rendering so you can see the finished '.strtolower($type).' before any work starts.',
        ],
        [
            'name' => 'Material + finish selection',
            'text' => 'You pick cabinets, countertops, tile, flooring, plumbing fixtures, and lighting from vetted suppliers — we order everything in one go to keep the schedule tight.',
        ],
        [
            'name' => 'Permits + demolition',
            'text' => 'We pull all required permits with the local '.($project->location ?: 'municipal').' building department, then carefully demo the existing space and protect the rest of your home from dust.',
        ],
        [
            'name' => 'Construction + inspections',
            'text' => 'Framing, electrical, plumbing, HVAC, drywall, paint, cabinetry, tile, and finish work — all coordinated by one project lead, with city inspections at every required stage.',
        ],
        [
            'name' => 'Final walkthrough + warranty',
            'text' => 'Punch-list walkthrough, deep clean, and handover. Every project is backed by our written workmanship warranty.',
        ],
    ];

    $howTo = [
        '@context'    => 'https://schema.org',
        '@type'       => 'HowTo',
        '@id'         => url('/projects/'.$project->slug).'#howto',
        'name'        => 'How GS Construction completed this '.$type.' remodel'.$loc,
        'description' => 'The 6-step remodeling process GS Construction followed for this '.$type.' project'.$loc.', from first consultation through final walkthrough.',
        'totalTime'   => 'P6W',  // typical 4–8 week range; midpoint
        'estimatedCost' => [
            '@type'        => 'MonetaryAmount',
            'currency'     => 'USD',
            'value'        => [
                '@type'   => 'QuantitativeValue',
                'minValue' => match ($project->project_type) {
                    'bathroom-remodeling' => 18000,
                    'kitchen-remodeling'  => 35000,
                    default               => 25000,
                },
                'maxValue' => match ($project->project_type) {
                    'bathroom-remodeling' => 60000,
                    'kitchen-remodeling'  => 120000,
                    default               => 200000,
                },
            ],
        ],
        'tool' => [
            ['@type' => 'HowToTool', 'name' => 'Licensed general contractor'],
            ['@type' => 'HowToTool', 'name' => 'Licensed plumber'],
            ['@type' => 'HowToTool', 'name' => 'Licensed electrician'],
        ],
        'image'       => $coverImage ? $coverImage->url : asset('images/greg-patryk.jpg'),
        'inLanguage'  => 'en-US',
        'about'       => ['@id' => 'https://gs.construction/#business'],
        'step'        => collect($steps)->map(fn ($s, $i) => [
            '@type'    => 'HowToStep',
            'position' => $i + 1,
            'name'     => $s['name'],
            'text'     => $s['text'],
            'url'      => url('/projects/'.$project->slug).'#step-'.($i + 1),
        ])->all(),
    ];
@endphp

<script type="application/ld+json">
{!! json_encode($howTo, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
