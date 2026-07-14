<x-layouts.app
    title="About GS Construction"
    metaDescription="Meet Gregory and Patryk, the father-son team behind GS Construction. Over 40 years of combined experience in kitchen, bathroom, and home remodeling in the Chicagoland area."
>
    {{-- Preload LCP image for faster paint --}}
    @push('head')
    <link rel="preload" as="image" href="{{ asset('images/greg-patryk.webp') }}" type="image/webp" fetchpriority="high">
    @endpush

    {{-- Breadcrumb Schema --}}
    <x-breadcrumb-schema :items="[
        ['name' => 'About'],
    ]" />

    {{-- Visual Breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">About</span>
                </li>
            </ol>
        </nav>
    </div>

    @php
        // Get one image from each of 6 different projects
        $galleryImages = \App\Models\ProjectImage::query()
            ->whereHas('project')
            ->select('project_images.*')
            ->join(
                \DB::raw('(SELECT MIN(id) as min_id FROM project_images GROUP BY project_id ORDER BY RAND() LIMIT 6) as unique_projects'),
                'project_images.id', '=', 'unique_projects.min_id'
            )
            ->inRandomOrder()
            ->get();
    @endphp
    
    <main class="isolate">
        <!-- Hero section -->
        <div class="relative isolate -z-10">
            {{-- Gradient blur background (same as testimonials) --}}
            <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-30 blur-3xl">
                <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
            </div>
            <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 flex transform-gpu overflow-hidden pt-32 opacity-25 blur-3xl sm:pt-40 xl:justify-end">
                <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[-22rem] aspect-[1313/771] w-[82.0625rem] flex-none origin-top-right rotate-[30deg] bg-linear-to-tr from-sky-300 to-sky-600 xl:mr-[calc(50%-12rem)] xl:ml-0"></div>
            </div>
            
            <div class="overflow-hidden">
                <div class="mx-auto max-w-7xl px-6 pt-12 pb-16 sm:pt-16 lg:px-8 lg:pt-12">
                    <div class="mx-auto max-w-2xl gap-x-14 lg:mx-0 lg:flex lg:max-w-none lg:items-center">
                        <div class="relative w-full lg:max-w-xl lg:shrink-0 xl:max-w-2xl">
                            <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">About Us</p>
                            <h1 class="font-heading mt-2 text-4xl font-bold tracking-tight text-zinc-900 sm:text-6xl dark:text-white">
                                A Family Business Built on Trust
                            </h1>
                            <p class="mt-8 text-lg font-medium text-zinc-600 sm:max-w-md sm:text-xl/8 lg:max-w-none dark:text-zinc-300">
                                GS Construction & Remodeling is more than a business—it's a family legacy. Run by Gregory and Patryk, a father-son duo with over 40 years of combined experience, we bring heart, skill, and dedication to every project.
                            </p>
                            <p class="mt-4 text-base text-zinc-500 sm:max-w-md lg:max-w-none dark:text-zinc-400">
                                From the initial consultation to the final walkthrough, we're personally involved in your project. We believe in building lasting relationships with our clients, not just beautiful spaces.
                            </p>
                        </div>
                        
                        {{-- Image gallery --}}
                        <div class="mt-14 flex justify-end gap-4 sm:-mt-44 sm:justify-start sm:pl-20 lg:mt-0 lg:pl-0">
                            <div class="ml-auto w-40 flex-none space-y-4 pt-32 sm:ml-0 sm:pt-80 lg:order-last lg:pt-36 xl:order-0 xl:pt-80">
                                @if($galleryImages->count() > 0)
                                <div class="relative">
                                    <x-lqip-image :image="$galleryImages[0]" size="medium" aspectRatio="square" rounded="xl" class="w-full shadow-lg" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                @endif
                                @if($galleryImages->count() > 5)
                                <div class="relative">
                                    <x-lqip-image :image="$galleryImages[5]" size="medium" aspectRatio="square" rounded="xl" class="w-full shadow-lg" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                @endif
                            </div>
                            <div class="mr-auto w-40 flex-none space-y-4 sm:mr-0 sm:pt-52 lg:pt-36">
                                @if($galleryImages->count() > 1)
                                <div class="relative">
                                    <x-lqip-image :image="$galleryImages[1]" size="medium" aspectRatio="square" rounded="xl" class="w-full shadow-lg" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                @endif
                                @if($galleryImages->count() > 2)
                                <div class="relative">
                                    <x-lqip-image :image="$galleryImages[2]" size="medium" aspectRatio="square" rounded="xl" class="w-full shadow-lg" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                @endif
                            </div>
                            <div class="w-40 flex-none space-y-4 pt-32 sm:pt-0">
                                @if($galleryImages->count() > 3)
                                <div class="relative">
                                    <x-lqip-image :image="$galleryImages[3]" size="medium" aspectRatio="square" rounded="xl" class="w-full shadow-lg" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                @endif
                                @if($galleryImages->count() > 4)
                                <div class="relative">
                                    <x-lqip-image :image="$galleryImages[4]" size="medium" aspectRatio="square" rounded="xl" class="w-full shadow-lg" />
                                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
                                <dt class="text-base/7 text-zinc-600 dark:text-zinc-400">Projects completed</dt>
                                <dd class="font-heading text-5xl font-bold tracking-tight text-zinc-900 dark:text-white">300+</dd>
                            </div>
                            <div class="flex flex-col-reverse gap-y-4">
                                <dt class="text-base/7 text-zinc-600 dark:text-zinc-400">5-star reviews</dt>
                                <dd class="font-heading text-5xl font-bold tracking-tight text-zinc-900 dark:text-white">70+</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Greg & Patryk Section -->
        <livewire:about-section variant="team" :area="$area ?? null" />

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
                <li class="relative rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Chapter one · New York City</p>
                    <h3 class="mt-2 font-heading text-xl font-bold text-zinc-900 dark:text-white">The Saturday crew</h3>
                    <p class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400">
                        Gregory built his reputation installing custom cabinetry in New York City —
                        exacting work where a sixteenth of an inch shows. When money was tight,
                        Patryk worked Saturdays alongside his dad. That's where the standard was set:
                        measure carefully, finish cleanly, stand behind it.
                    </p>
                </li>
                <li class="relative rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Chapter two · Chicagoland</p>
                    <h3 class="mt-2 font-heading text-xl font-bold text-zinc-900 dark:text-white">The foreman years</h3>
                    <p class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400">
                        In the Chicago area, Gregory spent years as a foreman — running crews,
                        sequencing trades, and learning who actually shows up and does it right.
                        That's where today's <a href="{{ route('trades.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">trade-partner bench</a>
                        comes from: not a directory, a network built job by job.
                    </p>
                </li>
                <li class="relative rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Chapter three · 2015</p>
                    <h3 class="mt-2 font-heading text-xl font-bold text-zinc-900 dark:text-white">GS Construction</h3>
                    <p class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400">
                        Father and son made it official: a family remodeling company serving the
                        North Shore and northwest suburbs. Over two decades of working together,
                        300+ projects, and 70+ five-star reviews later, one thing hasn't changed —
                        one of them is on your job, personally.
                    </p>
                </li>
            </ol>
        </div>

        <!-- Individual cards: Greg & Patryk -->
        <div class="mx-auto mt-16 max-w-7xl px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
                {{-- Gregory --}}
                <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
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
                <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
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

        <!-- How they complete each other -->
        <div class="mx-auto mt-16 max-w-7xl px-6 lg:px-8">
            <div class="rounded-2xl bg-sky-600 px-8 py-10 sm:px-10">
                <div class="mx-auto max-w-2xl lg:mx-0">
                    <h2 class="font-heading text-3xl font-bold tracking-tight text-white">Why the pairing works</h2>
                    <p class="mt-4 text-lg/8 text-sky-100">
                        Most remodels fail in one of two ways: the craft is good but the project drags,
                        or the schedule holds but the finish disappoints. A father who thinks in
                        sixteenths and a son who thinks in sequences cover both.
                    </p>
                </div>
                <dl class="mt-10 grid grid-cols-1 gap-8 sm:grid-cols-3">
                    <div>
                        <dt class="font-semibold text-white">Craft ↔ Coordination</dt>
                        <dd class="mt-1 text-sm/6 text-sky-100/90">Gregory holds the finish standard on site; Patryk holds the schedule around it. Neither gets sacrificed for the other.</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-white">Network ↔ Systems</dt>
                        <dd class="mt-1 text-sm/6 text-sky-100/90">Gregory's decades-deep trade relationships get the right crews; Patryk's logistics get them there in the right order, ready to work.</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-white">Experience ↔ Iteration</dt>
                        <dd class="mt-1 text-sm/6 text-sky-100/90">Forty-plus combined years of knowing what works, paired with a constant push to run the next project better than the last.</dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Values section -->
        <div class="mx-auto mt-10 max-w-7xl px-6 sm:mt-12 lg:px-8">
            <div class="mx-auto max-w-2xl lg:mx-0">
                <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">{{ isset($area) ? 'Our Values Serving ' . $area->city : 'Our Values' }}</h2>
                <p class="mt-6 text-lg/8 text-zinc-600 dark:text-zinc-300">
                    @if(isset($area))
                    These principles guide everything we do for {{ $area->city }} homeowners, from the first phone call to the final nail.
                    @else
                    These principles guide everything we do, from the first phone call to the final nail.
                    @endif
                </p>
            </div>
            <dl class="mx-auto mt-10 grid max-w-2xl grid-cols-1 gap-6 text-base/7 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-3">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Quality Craftsmanship</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">We never cut corners. Every joint, every finish, every detail matters. Our reputation is built on work that stands the test of time.</dd>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Transparent Communication</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">No surprises, no hidden costs. We keep you informed at every stage, so you always know exactly what's happening with your project.</dd>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Respect for Your Home</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">We treat your home like our own. That means protecting your belongings, cleaning up daily, and minimizing disruption to your life.</dd>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Personal Involvement</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">Gregory or Patryk is on-site for every {{ isset($area) ? $area->city : '' }} project. You'll always have a direct line to the owners, not a middleman.</dd>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Honest Pricing</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">We provide detailed, upfront quotes. If something changes, we discuss it with you first. No surprise invoices, ever.</dd>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Community First</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">We're your neighbors. We live and work in the communities we serve, and we take pride in making {{ isset($area) ? $area->city : 'Chicagoland' }} homes beautiful.</dd>
                </div>
            </dl>
        </div>

        {{-- Internal link into the comparison content so it isn't orphaned:
             a real link from a crawled, authoritative page lets /compare (and its
             11 competitor pages) be discovered, gain authority, and be AI-cited. --}}
        <div class="mx-auto max-w-7xl px-4 pb-4 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-800/40 sm:p-8">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">How we compare to other Chicago remodelers</h2>
                <p class="mt-2 max-w-3xl text-zinc-600 dark:text-zinc-300">
                    Getting other quotes? We keep factual, side-by-side comparisons with the region's
                    larger design-build firms — service area, approach, materials and communication —
                    plus a plain-English guide to choosing a contractor, so you can decide with clear information.
                </p>
                <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2">
                    <a href="{{ route('compare.index') }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-semibold text-sky-700 hover:underline dark:text-sky-400">
                        See how GS Construction compares →
                    </a>
                    <a href="{{ url('/how-to-choose-a-remodeling-contractor') }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-semibold text-sky-700 hover:underline dark:text-sky-400">
                        How to choose a remodeling contractor →
                    </a>
                </div>
            </div>
        </div>

        <x-cta-section
            variant="blue"
            heading="Ready to Transform Your Home?"
            description="Let's discuss your project. Schedule a free consultation and see why Chicagoland homeowners trust GS Construction."
            primaryText="Schedule Free Consultation"
            primaryHref="/contact"
            secondaryText="View Our Work"
            secondaryHref="/projects"
        />
    </main>
</x-layouts.app>
