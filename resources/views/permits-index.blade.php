@php($guides = collect(\App\Support\PermitGuideInfo::all()))
<x-layouts.app
    title="Building Permit Guides for Chicago's NW Suburbs & North Shore | GS Construction"
    metaDescription="Town-by-town building permit guides for remodeling in Chicago's northwest suburbs and North Shore — who needs a permit, fees, review times, inspections, and contractor registration, from official village sources."
>
    <x-breadcrumb-schema :items="[
        ['name' => 'Permit Guides'],
    ]" />

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Permit Guides · {{ now()->year }}</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            Building permit guides for Chicago's NW suburbs &amp; North Shore
        </h1>
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            Nearly every kitchen, bathroom, basement, or addition remodel in the Chicago suburbs
            requires a building permit — and every village runs the process a little differently.
            GS Construction pulls and manages the permits for our remodeling clients as part of
            every project, so these town-by-town guides show you what your village requires,
            what it costs, and how long review typically takes — all from official municipal sources.
        </p>

        {{-- Town guide cards --}}
        <div class="mt-10 grid gap-5 sm:grid-cols-2">
            @foreach($guides as $slug => $guide)
                <a href="{{ route('permits.show', ['slug' => $slug]) }}" wire:navigate
                   class="flex h-full flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $guide['town'] }}</h2>
                    <p class="mt-2 grow text-sm text-zinc-600 dark:text-zinc-400">Who needs a permit, fees, review times &amp; inspections in {{ $guide['town'] }}.</p>
                    <p class="mt-4 text-sm font-semibold text-sky-600 dark:text-sky-400">Full {{ $guide['town'] }} permit guide →</p>
                </a>
            @endforeach
        </div>

        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">How GS handles permits for you</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                When you remodel with GS Construction, permits are our job, not yours. We prepare
                the drawings and application, register with your village where required, submit and
                track the review, and schedule every inspection through to final sign-off. See
                <a href="{{ route('process') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">how the GS process works</a>
                and <a href="{{ route('costs.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">what remodeling really costs</a>.
            </p>
        </div>
    </div>

    <x-cta-section
        variant="blue"
        heading="Remodeling? We pull the permits."
        description="Free in-home estimate with an itemized scope — permits, drawings, and inspections handled by us on every project."
    />
</x-layouts.app>
