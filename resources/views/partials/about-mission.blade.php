{{-- Mission + headline stats.

     Shared by /about and every /areas-served/{area}/about. The area pages used
     to carry their own near-identical copy of this — same "transform houses
     into dream homes" sentence, same three figures — which is why the stats
     had to be edited in two files every time they changed.

     Already area-aware: pass $area and the heading becomes "Our Mission in
     {city}" with the town woven through the copy. Without it, the company-wide
     wording. --}}
        <!-- Mission section -->
        <div class="mx-auto mt-8 max-w-7xl px-6 sm:mt-12 lg:px-8">
            <div class="mx-auto max-w-2xl lg:mx-0 lg:max-w-none">
                <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">{{ isset($area) ? 'Our Mission in ' . $area->city : 'Our Mission' }}</h2>
                <div class="mt-6 flex flex-col gap-x-8 gap-y-20 lg:flex-row">
                    <div class="lg:w-full lg:max-w-2xl lg:flex-auto">
                        <p class="text-xl/8 text-zinc-700 dark:text-zinc-200">
                            @if(isset($area))
                            To transform {{ $area->city }} houses into dream homes while building genuine relationships with every homeowner we serve. We believe that a remodel should be an exciting journey, not a stressful ordeal.
                            @else
                            To transform houses into dream homes while building genuine relationships with every homeowner we serve. We believe that a remodel should be an exciting journey, not a stressful ordeal.
                            @endif
                        </p>
                        <p class="mt-8 max-w-xl text-base/7 text-zinc-600 dark:text-zinc-400">
                            @if(isset($area))
                            With deep roots in {{ $area->city }} and throughout Chicagoland, Northwest Suburbs, and North Shore, we understand the unique needs of local homeowners. From historic home renovations to modern kitchen makeovers, we bring the same level of care and craftsmanship to every project—big or small.
                            @else
                            With roots throughout Chicagoland, Northwest Suburbs, and North Shore, we understand the unique needs of local homeowners. From historic home renovations to modern kitchen makeovers, we bring the same level of care and craftsmanship to every project—big or small.
                            @endif
                        </p>
                        <p class="mt-4 max-w-xl text-base/7 text-zinc-600 dark:text-zinc-400">
                            Our approach is simple: treat every home as if it were our own. That means attention to detail, transparent communication, and always being on-site to ensure everything meets our high standards.
                        </p>
                    </div>
                    <div class="lg:flex lg:flex-auto lg:justify-center">
                        <dl class="w-64 space-y-8 xl:w-80">
                            <div class="flex flex-col-reverse gap-y-4">
                                <dt class="text-base/7 text-zinc-600 dark:text-zinc-400">Years of combined experience</dt>
                                <dd class="font-heading text-5xl font-bold tracking-tight text-zinc-900 dark:text-white">40+</dd>
                            </div>
                            <div class="flex flex-col-reverse gap-y-4">
                                {{-- flex-col-reverse: DOM order renders bottom-up, so this pair
                                     appears BELOW the number. Label and note are wrapped together
                                     so the container's gap-y-4 does not push them apart — the
                                     note qualifies the label and should sit tight to it. --}}
                                <div>
                                    <dt class="text-base/7 text-zinc-600 dark:text-zinc-400">Projects completed in the last {{ \App\Support\CompanyStats::projectYears() }} years</dt>
                                    @if(\App\Support\CompanyStats::projectCadenceLabel())
                                        <dd class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ \App\Support\CompanyStats::projectsPerYearLabel() }} a year &mdash; a project completed {{ \App\Support\CompanyStats::projectCadenceLabel() }}</dd>
                                    @endif
                                </div>
                                <dd class="font-heading text-5xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ \App\Support\CompanyStats::projectsCompletedLabel() }}</dd>
                            </div>
                            <div class="flex flex-col-reverse gap-y-4">
                                <dt class="text-base/7 text-zinc-600 dark:text-zinc-400">5-star reviews</dt>
                                <dd class="font-heading text-5xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ \App\Support\CompanyStats::reviewsCountLabel() }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
