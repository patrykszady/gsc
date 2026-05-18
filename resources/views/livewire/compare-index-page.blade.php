<div class="bg-white dark:bg-gray-950">
    <x-breadcrumb-schema :items="[
        ['name' => 'Compare Remodeling Contractors'],
    ]" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Compare Contractors</span>
                </li>
            </ol>
        </nav>
    </div>

    <main class="mx-auto max-w-7xl px-6 pb-16 lg:px-8">
        <div class="mx-auto max-w-3xl text-center">
            <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Compare</p>
            <h1 class="mt-2 font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                Compare Chicago-area remodeling contractors
            </h1>
            <p class="mt-6 text-lg text-zinc-600 dark:text-zinc-300">
                Researching options? Compare GS Construction to other Chicago-area remodeling companies using
                factual criteria like service area, project focus, communication, and verified reviews.
            </p>
        </div>

        <ul class="mx-auto mt-12 grid max-w-4xl grid-cols-1 gap-6 sm:grid-cols-2">
            @foreach($competitors as $competitor)
                <li>
                    <a href="{{ route('compare.show', ['competitor' => $competitor['slug']]) }}"
                       wire:navigate
                       class="block rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Alternative to</p>
                        <h2 class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">
                            GS Construction vs {{ $competitor['name'] }}
                        </h2>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $competitor['location'] ?? '' }} · {{ $competitor['focus'] ?? '' }}
                        </p>
                        <p class="mt-4 text-sm font-medium text-sky-700 dark:text-sky-400">
                            See comparison &rarr;
                        </p>
                    </a>
                </li>
            @endforeach
        </ul>

        <p class="mx-auto mt-10 max-w-2xl text-center text-xs text-zinc-500 dark:text-zinc-400">
            We compare publicly available information only. Always verify details directly with each company before making a decision.
        </p>
    </main>
</div>
