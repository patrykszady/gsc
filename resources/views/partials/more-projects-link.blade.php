{{-- "More {Type} Projects" button that sits under a projects grid.

     Shared by /services/{service} and /areas-served/{area}/services/{service},
     so both grids end the same way. Was inline on the service page only, which
     left the area service pages with a grid and no route onward.

     Expects $projectType (the Project::project_type slug). Renders nothing for
     a type with no dedicated index — basement, addition and mudroom live on
     /projects rather than their own page, so a button would 404. --}}
@php
    $moreProjects = [
        'kitchen' => ['label' => 'More Kitchen Projects', 'url' => '/projects/kitchens'],
        'bathroom' => ['label' => 'More Bathroom Projects', 'url' => '/projects/bathrooms'],
        'home-remodel' => ['label' => 'More Home Remodeling Projects', 'url' => '/projects/home-remodeling'],
    ];

    $moreProjectsLink = $moreProjects[$projectType ?? ''] ?? null;
@endphp

@php $inline = $inline ?? false; @endphp

@if($moreProjectsLink)
    {{-- inline: sits in the pagination footer row, so no negative margin and no
         centring — the footer owns the layout. --}}
    <div @class(['relative z-10', '-mt-4 text-center' => ! $inline])>
        <x-buttons.cta
            href="{{ $moreProjectsLink['url'] }}"
            variant="secondary"
            size="lg"
            class="pointer-events-auto">
            {{ $moreProjectsLink['label'] }}
        </x-buttons.cta>
    </div>
@endif
