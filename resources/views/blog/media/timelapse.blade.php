@php $tl = $project->timelapses->first(fn ($t) => $t->frames->count() >= 2); @endphp
@if ($tl)
    {{-- The real timelapse player — same component the project page uses — not a strip of frames. --}}
    <div class="not-prose clear-both my-10">
        <h3 class="mb-3 font-heading text-lg font-bold text-zinc-900 dark:text-white">{{ $tl->title ?: 'Watch the build' }}</h3>
        <livewire:timelapse-section :timelapse-id="$tl->id" :key="'blog-timelapse-' . $tl->id" />
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $tl->frames->count() }} frames · <a href="{{ route('projects.show', $project) }}#timelapse" wire:navigate class="text-sky-700 hover:underline dark:text-sky-400">all timelapses for this project</a></p>
    </div>
@endif
