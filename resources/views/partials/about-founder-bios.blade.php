{{-- Gregory and Patryk, one card each — the full bios rather than the short
     summary in <livewire:about-section variant="team">.

     Shared by /about and the area about pages. Takes no variables. --}}
        <!-- Individual cards: Greg & Patryk -->
        <div class="mx-auto mt-16 max-w-7xl px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
                {{-- Gregory --}}
                <div class="group flex flex-col rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <div class="flex items-center gap-4">
                        <img src="{{ asset('images/greg-avatar.webp') }}" alt="Gregory, founder of GS Construction"
                             width="320" height="320" loading="lazy"
                             class="size-16 shrink-0 rounded-full object-cover ring-2 ring-sky-600/80">
                        <div>
                            <h3 class="font-heading text-2xl font-bold text-zinc-900 dark:text-white">Gregory</h3>
                            <p class="text-sm font-medium text-sky-600 dark:text-sky-400">Founder · Master craftsman · The network</p>
                        </div>
                    </div>
                    <p class="mt-5 text-base/7 text-zinc-600 dark:text-zinc-300">
                        A businessman by history and a carpenter at heart. Gregory's hands learned the
                        trade on custom cabinet installations in New York City, and his leadership was
                        forged over years as a foreman on Chicago-area jobs — where craft alone isn't
                        enough, and you learn to run a site, read a crew, and hold a standard.
                    </p>
                    <ul class="mt-6 space-y-3">
                        <li class="flex gap-3 text-sm/6 text-zinc-600 dark:text-zinc-400">
                            <svg class="mt-1 h-4 w-4 flex-none text-sky-600 dark:text-sky-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            <span><strong class="text-zinc-900 dark:text-white">Cabinet-grade standards.</strong> When your career starts with custom cabinetry in NYC, "close enough" never enters the vocabulary — and it shows in every trim line and tile course.</span>
                        </li>
                        <li class="flex gap-3 text-sm/6 text-zinc-600 dark:text-zinc-400">
                            <svg class="mt-1 h-4 w-4 flex-none text-sky-600 dark:text-sky-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            <span><strong class="text-zinc-900 dark:text-white">A foreman's eye.</strong> Years running Chicago-area crews means he sees problems before they cost you money — framing that's off, rough-in that won't pass, sequencing that wastes a week.</span>
                        </li>
                        <li class="flex gap-3 text-sm/6 text-zinc-600 dark:text-zinc-400">
                            <svg class="mt-1 h-4 w-4 flex-none text-sky-600 dark:text-sky-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            <span><strong class="text-zinc-900 dark:text-white">The network.</strong> Decades in the trades built a deep bench of electricians, plumbers, tile setters, and masons who answer his calls first — <a href="{{ route('trades.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">the partners behind every GS project</a>.</span>
                        </li>
                    </ul>
                </div>

                {{-- Patryk --}}
                <div class="group flex flex-col rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <div class="flex items-center gap-4">
                        <img src="{{ asset('images/patryk-avatar.webp') }}" alt="Patryk, co-founder of GS Construction"
                             width="320" height="320" loading="lazy"
                             class="size-16 shrink-0 rounded-full object-cover ring-2 ring-sky-600/80">
                        <div>
                            <h3 class="font-heading text-2xl font-bold text-zinc-900 dark:text-white">Patryk</h3>
                            <p class="text-sm font-medium text-sky-600 dark:text-sky-400">Co-founder · Project manager · The logistics</p>
                        </div>
                    </div>
                    <p class="mt-5 text-base/7 text-zinc-600 dark:text-zinc-300">
                        Patryk's apprenticeship started on those NYC Saturdays — and after two decades
                        working beside Gregory, he knows the craft from the tools up. What he brings on
                        top of it is logistics: the systems, scheduling, and communication that keep a
                        remodel moving when the inevitable surprises show up.
                    </p>
                    <ul class="mt-6 space-y-3">
                        <li class="flex gap-3 text-sm/6 text-zinc-600 dark:text-zinc-400">
                            <svg class="mt-1 h-4 w-4 flex-none text-sky-600 dark:text-sky-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            <span><strong class="text-zinc-900 dark:text-white">Setbacks are the job.</strong> Discontinued tile, a surprise behind the drywall, a trade delayed a day — every project has them. Patryk's job is having the next move ready so the schedule bends instead of breaking.</span>
                        </li>
                        <li class="flex gap-3 text-sm/6 text-zinc-600 dark:text-zinc-400">
                            <svg class="mt-1 h-4 w-4 flex-none text-sky-600 dark:text-sky-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            <span><strong class="text-zinc-900 dark:text-white">Always upgrading the system.</strong> Selection deadlines, trade sequencing, client updates — he's constantly refining how GS runs projects, because a smoother process is the difference between 8 weeks and 12.</span>
                        </li>
                        <li class="flex gap-3 text-sm/6 text-zinc-600 dark:text-zinc-400">
                            <svg class="mt-1 h-4 w-4 flex-none text-sky-600 dark:text-sky-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            <span><strong class="text-zinc-900 dark:text-white">Design to walkthrough.</strong> He manages design, planning, and client relationships — so the person who scoped your project is the same one answering your texts during it.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
