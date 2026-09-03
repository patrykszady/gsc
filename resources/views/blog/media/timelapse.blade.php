@php $tls = $project->timelapses->filter(fn ($t) => $t->frames->count() >= 2)->values(); @endphp
@if ($tls->isNotEmpty())
    {{-- The project page's timelapse cards, verbatim, via the shared component. --}}
    <div class="not-prose clear-both my-10">
        <x-timelapse-cards :timelapses="$tls" :project="$project" />
    </div>
@endif
