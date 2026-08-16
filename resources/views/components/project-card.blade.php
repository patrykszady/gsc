@blaze
{{--
    Project card — the single source for how a project is presented in a grid.

    Was written twice: once in livewire/projects-grid.blade.php and again,
    slightly differently, in the area page's local-projects block (shadow-md vs
    shadow-lg, no featured badge, its own title treatment). Two cards that were
    meant to look the same and did not.

    Props
      project   Project (required)
      town      string|null  — shown under the title. The area page uses it to
                              label projects from a NEARBY town, so a Barrington
                              page never implies a Palatine job was in Barrington.
      eager     bool         — skip lazy-loading for above-the-fold cards.
--}}

@props([
    'project',
    'town' => null,
    'eager' => false,
])

<a
    href="{{ route('projects.show', $project) }}"
    wire:navigate
    {{ $attributes->class('group relative flex h-full flex-col overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10') }}
>
    <div class="relative aspect-4/3 overflow-hidden">
        @if($project->cover())
            <x-lqip-image
                :image="$project->cover()"
                size="medium"
                width="600"
                height="450"
                :eager="$eager"
                :alt="$project->title . ' — remodeling project' . ($town ? ' in ' . $town . ', IL' : '')"
                class="h-full w-full transition duration-300 group-hover:scale-105"
            />
        @else
            <div class="flex h-full w-full items-center justify-center bg-zinc-100 dark:bg-zinc-700">
                <svg class="h-12 w-12 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                </svg>
            </div>
        @endif

        {{-- Project title, top-left over the image — the same treatment as the
             hero slider and the area intro slider. A <span>, not an <a>: the
             whole card is already a link and nested anchors are invalid.
             The chevron still reads as "this goes somewhere". --}}
        <div class="absolute top-3 left-3 right-3 z-10">
            <span class="inline-flex max-w-full items-center gap-1.5 rounded-lg bg-black/50 px-3 py-1.5 text-sm font-medium text-white backdrop-blur-sm transition group-hover:bg-black/70">
                <span class="truncate">{{ $project->title }}</span>
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </div>

        {{-- Featured sits bottom-right now; top-left belongs to the title. --}}
        @if($project->is_featured)
            <div class="absolute bottom-3 right-3">
                <span class="inline-flex items-center rounded-full bg-sky-500 px-2.5 py-1 text-xs font-medium text-white">Featured</span>
            </div>
        @endif

        {{-- Bottom-left cluster: the town (only when this card is shown on
             ANOTHER town's page — the card is image-only, so it still has to
             say where the job actually was) and the project type.

             One flex row rather than two corners: the type chip used to sit at
             top-14 right-3, so a card could carry a label in three different
             corners at once. Wrapping them together also means they can never
             overlap when both are present. --}}
        @if($town || $project->project_type)
            <div class="absolute bottom-3 left-3 flex flex-wrap items-center gap-2">
                @if($town)
                    <span class="inline-flex items-center rounded-full bg-white/90 px-2.5 py-1 text-xs font-medium text-zinc-700 backdrop-blur dark:bg-zinc-900/90 dark:text-zinc-300">
                        {{ $town }}, IL
                    </span>
                @endif

                @if($project->project_type)
                    {{-- str_replace before ucwords: the slug is 'home-remodel',
                         and ucfirst alone rendered it as "Home-remodel". --}}
                    <span class="inline-flex items-center rounded-full bg-white/90 px-2.5 py-1 text-xs font-medium text-zinc-700 backdrop-blur dark:bg-zinc-900/90 dark:text-zinc-300">
                        {{ ucwords(str_replace('-', ' ', $project->project_type)) }}
                    </span>
                @endif
            </div>
        @endif
    </div>

    @if($town)<span class="sr-only">Project in {{ $town }}, IL</span>@endif
</a>
