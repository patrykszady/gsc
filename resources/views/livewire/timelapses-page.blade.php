<div class="bg-white dark:bg-zinc-950">
    <x-breadcrumbs :items="[
        ['label' => 'Projects', 'url' => route('projects.index')],
        ['label' => 'Before & After Timelapses'],
    ]" />

    <div class="mx-auto max-w-3xl px-6 pt-2 text-center lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Watch The Build</p>
    </div>

    <x-page-hero
        title="Before & after timelapses"
        key-suffix="timelapses"
    />

    <div class="mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-3xl text-center">
            <p class="mt-8 text-lg text-zinc-600 dark:text-zinc-300">
                @if($timelapses->isNotEmpty())
                    {{ $timelapses->count() }} {{ \Illuminate\Support\Str::plural('remodel', $timelapses->count()) }},
                    photographed from the same spot every day — demolition through final walkthrough. Drag any
                    timelapse to scrub through the build yourself.
                @else
                    Timelapses of our remodels, from demolition through final walkthrough.
                @endif
            </p>
        </div>

        @forelse($timelapses as $timelapse)
            @php
                $project = $timelapse->project;
                $town = $project?->location ? trim(\Illuminate\Support\Str::before($project->location, ',')) : null;
            @endphp

            <section class="mt-14 first:mt-10">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                            {{ $project?->title ?? 'Project timelapse' }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            @if($town){{ $town }}, IL &middot; @endif
                            {{ $timelapse->frames->count() }} {{ \Illuminate\Support\Str::plural('stage', $timelapse->frames->count()) }}
                            @if($project?->project_type)
                                &middot; {{ ucwords(str_replace('-', ' ', $project->project_type)) }}
                            @endif
                        </p>
                    </div>

                    @if($project)
                        <x-buttons.cta :href="route('projects.show', $project)" variant="outline" size="sm">
                            See the project
                        </x-buttons.cta>
                    @endif
                </div>

                {{-- heading="" because each block is titled by its own project
                     above; the component's default line would repeat verbatim
                     down the whole page. --}}
                <div class="mt-5">
                    <livewire:timelapse-section
                        :timelapse-id="$timelapse->id"
                        :heading="null"
                        :key="'timelapse-gallery-'.$timelapse->id" />
                </div>
            </section>
        @empty
            <p class="mt-10 text-center text-lg text-zinc-500 dark:text-zinc-400">
                No timelapses posted yet.
            </p>
        @endforelse
    </div>

    <x-cta-section
        variant="blue"
        heading="Want your remodel documented like this?"
        description="We photograph every job from the same spot each day, so you can see exactly what happened while you were at work."
    />
</div>
