<div class="bg-white dark:bg-gray-950">
    <x-breadcrumb-schema :items="[
        ['name' => 'Compare Contractors', 'url' => route('compare.index')],
        ['name' => 'GS Construction vs ' . ($competitor['name'] ?? '')],
    ]" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <a href="{{ route('compare.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Compare</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">GS Construction vs {{ $competitor['name'] }}</span>
                </li>
            </ol>
        </nav>
    </div>

    <main class="mx-auto max-w-5xl px-6 pb-16 lg:px-8">
        <header class="mx-auto max-w-3xl text-center">
            <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Alternative to {{ $competitor['name'] }}</p>
            <h1 class="mt-2 font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                GS Construction vs {{ $competitor['name'] }}
            </h1>
            <p class="mt-6 text-lg text-zinc-600 dark:text-zinc-300">
                Considering {{ $competitor['name'] }} for your kitchen, bathroom, or whole-home remodel?
                Here is a factual side-by-side so you can compare options before requesting estimates.
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-center gap-3 text-sm">
                <a href="/contact" wire:navigate class="rounded-md bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-500">
                    Get a free estimate from GS Construction
                </a>
                @if($competitor['slug'] !== 'kitchen-village')
                <a href="{{ $competitor['website'] }}" target="_blank" rel="noopener noreferrer nofollow"
                   class="rounded-md border border-zinc-300 px-4 py-2 font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-900">
                    Visit {{ $competitor['name'] }}'s website
                </a>
                @endif
            </div>
        </header>

        <section class="mt-12 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Criteria</th>
                        <th scope="col" class="px-4 py-3 font-semibold text-sky-700 dark:text-sky-400">GS Construction</th>
                        <th scope="col" class="px-4 py-3 font-semibold">{{ $competitor['name'] }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                    @foreach($criteria as $row)
                        <tr>
                            <th scope="row" class="whitespace-nowrap px-4 py-3 font-medium text-zinc-700 dark:text-zinc-200">{{ $row['label'] }}</th>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ $row['us'] }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $row['them'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="mt-12 grid gap-8 md:grid-cols-2">
            <div>
                <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">Why homeowners choose GS Construction</h2>
                <ul class="mt-4 space-y-3 text-zinc-700 dark:text-zinc-300">
                    <li>Family-owned father-son team; you talk directly to the owners.</li>
                    <li>{{ $reviewCount }}+ verified reviews across Google, Houzz, Yelp, and Angi.</li>
                    <li>Detailed scope and itemized estimate before work begins.</li>
                    <li>Permit pulling and inspection coordination handled for you.</li>
                    <li>Hundreds of in-progress and completed project photos on this site.</li>
                </ul>
            </div>
            <div>
                <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">How to decide</h2>
                <ol class="mt-4 list-decimal space-y-3 pl-5 text-zinc-700 dark:text-zinc-300">
                    <li>Request itemized estimates from at least two contractors.</li>
                    <li>Confirm who pulls permits and how inspections are scheduled.</li>
                    <li>Ask for recent local references and visit a finished project if possible.</li>
                    <li>Compare communication cadence and single point of contact.</li>
                </ol>
            </div>
        </section>

        <section class="mt-12">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">Recent GS Construction projects</h2>
            <ul class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($projects as $project)
                    @php
                        $cover = $project->relationLoaded('images') && $project->images->isNotEmpty()
                            ? ($project->images->firstWhere('is_cover', true) ?? $project->images->first())
                            : null;
                    @endphp
                    <li class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        @if($cover)
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="block">
                                <x-lqip-image :image="$cover" size="medium" aspectRatio="square" class="w-full" />
                            </a>
                        @endif
                        <div class="p-4">
                            <a href="{{ route('projects.show', $project) }}" wire:navigate
                               class="font-semibold text-zinc-900 hover:text-sky-700 dark:text-white dark:hover:text-sky-400">
                                {{ $project->title }}
                            </a>
                            @if($project->location)
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $project->location }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="mt-12 rounded-2xl bg-sky-50 p-8 text-center dark:bg-sky-950">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">
                Get a second opinion or free estimate
            </h2>
            <p class="mx-auto mt-3 max-w-2xl text-zinc-700 dark:text-zinc-200">
                It is smart to compare. We are happy to give you a no-pressure estimate even if you are
                already talking to {{ $competitor['name'] }}.
            </p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3 text-sm">
                <a href="/contact" wire:navigate class="rounded-md bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-500">Request a free estimate</a>
                <a href="tel:2247354200" class="rounded-md border border-zinc-300 px-4 py-2 font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-900">Call (224) 735-4200</a>
            </div>
        </section>

        <p class="mx-auto mt-10 max-w-2xl text-center text-xs text-zinc-500 dark:text-zinc-400">
            This page compares publicly available information about {{ $competitor['name'] }} for informational purposes.
            All trademarks belong to their respective owners. Always verify details directly with each company.
        </p>
    </main>
</div>
