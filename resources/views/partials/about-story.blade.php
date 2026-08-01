{{-- "Our Story — Two decades side by side": how the company grew out of a
     father and son working together, in three chapters.

     Shared by /about and every /areas-served/{area}/about. Takes no variables;
     the story is the same wherever it is told. --}}
        <!-- The story: two decades side by side -->
        <div class="mx-auto mt-16 max-w-7xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl lg:mx-0">
                <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Our Story</p>
                <h2 class="font-heading mt-2 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                    Two decades side by side
                </h2>
                <p class="mt-6 text-lg/8 text-zinc-600 dark:text-zinc-300">
                    GS Construction wasn't started — it grew. Long before there was a company name,
                    there was a father installing custom cabinets in New York City and a son showing
                    up on Saturdays to help when money was tight. More than twenty years later,
                    they're still on the same jobs.
                </p>
            </div>
            <ol class="mx-auto mt-10 grid max-w-2xl grid-cols-1 gap-8 lg:mx-0 lg:max-w-none lg:grid-cols-3">
                <li class="relative group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Chapter one · New York City</p>
                    <h3 class="mt-2 font-heading text-xl font-bold text-zinc-900 dark:text-white">The Saturday crew</h3>
                    <p class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400">
                        Gregory built his reputation installing custom cabinetry in New York City —
                        exacting work where a sixteenth of an inch shows. When money was tight,
                        Patryk worked Saturdays alongside his dad. That's where the standard was set:
                        measure carefully, finish cleanly, stand behind it.
                    </p>
                </li>
                <li class="relative group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Chapter two · Chicagoland</p>
                    <h3 class="mt-2 font-heading text-xl font-bold text-zinc-900 dark:text-white">The foreman years</h3>
                    <p class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400">
                        In the Chicago area, Gregory spent years as a foreman — running crews,
                        sequencing trades, and learning who actually shows up and does it right.
                        That's where today's <a href="{{ route('trades.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">trade-partner bench</a>
                        comes from: not a directory, a network built job by job.
                    </p>
                </li>
                <li class="relative group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Chapter three · 2015</p>
                    <h3 class="mt-2 font-heading text-xl font-bold text-zinc-900 dark:text-white">GS Construction</h3>
                    <p class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400">
                        Father and son made it official: a family remodeling company serving
                        Chicago&rsquo;s North Shore and Northwest Suburbs. Over two decades of working together,
                        {{ \App\Support\CompanyStats::projectsCompletedLabel() }} projects, and {{ \App\Support\CompanyStats::reviewsCountLabel() }} five-star reviews later, one thing hasn't changed —
                        one of them is on your job, personally.
                    </p>
                </li>
            </ol>
        </div>
