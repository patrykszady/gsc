{{--
    The project image grid — container + cards, so every grid on the site
    shares one layout as well as one card.

    Was written twice with different columns and gaps: projects-grid used
    grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6, the area page used
    grid-cols-2 sm:grid-cols-3 gap-4. Same grid, two answers.

    Props
      projects  iterable<Project>
      towns     bool — label each card with the project's own town. The area
                       page turns this on when the projects come from
                       NEIGHBOURING towns, so a Barrington page never implies a
                       Palatine job happened in Barrington.
      eagerCount int — how many cards skip lazy-loading (above the fold).
--}}

@props([
    'projects' => [],
    'towns' => false,
    'eagerCount' => 2,
])

<div {{ $attributes->class('grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3') }}>
    @foreach($projects as $project)
        <x-project-card
            :project="$project"
            :town="$towns ? (trim((string) (preg_split('/[,.]/', (string) $project->location)[0] ?? '')) ?: null) : null"
            :eager="$loop->index < $eagerCount"
            wire:key="project-card-{{ $project->id }}" />
    @endforeach
</div>
