<div>
    {{-- Breadcrumb Schema for all area pages --}}
    @php
        $breadcrumbItems = [
            ['name' => 'Areas Served', 'url' => route('areas.index')],
            ['name' => $area->city, 'url' => $area->url],
        ];
        
        if ($page === 'service' && $service) {
            $serviceNames = [
                'kitchen-remodeling' => 'Kitchen Remodeling',
                'bathroom-remodeling' => 'Bathroom Remodeling',
                'home-remodeling' => 'Home Remodeling',
                'basement-remodeling' => 'Basement Remodeling',
                'home-additions' => 'Home Additions',
            ];
            $breadcrumbItems[] = ['name' => 'Services', 'url' => $area->pageUrl('services')];
            $breadcrumbItems[] = ['name' => $serviceNames[$service] ?? ucfirst($service)];
        } elseif ($page !== 'home') {
            $pageNames = [
                'contact' => 'Contact',
                'testimonials' => 'Testimonials',
                'projects' => 'Projects',
                'about' => 'About',
                'services' => 'Services',
            ];
            $breadcrumbItems[] = ['name' => $pageNames[$page] ?? ucfirst($page)];
        }
    @endphp

    {{-- Per-area LocalBusiness schema (with geo, hours, postalCodes, hasMap) --}}
    <x-area-local-business-schema :area="$area" />

    {{-- ImageGallery schema: surfaces this city's project photos in Google Images / Photos carousel --}}
    <x-area-image-gallery-schema :area="$area" />

    <x-breadcrumbs :items="$breadcrumbItems" padding="py-1" />

    @switch($page)
        @case('home')
            {{-- Area Home Page --}}
            @php
                $toneIndex = abs(crc32($area->slug)) % 3;

                // Full service list derived from nav links so it auto-updates,
                // while preserving explicit phrases like "home remodeling".
                $phraseBySlug = [
                    'kitchen-remodeling' => 'kitchen remodeling',
                    'bathroom-remodeling' => 'bathroom remodeling',
                    'basement-remodeling' => 'basement remodeling',
                    'home-additions' => 'home additions',
                    'mudroom-remodeling' => 'mudroom remodeling',
                    'home-remodeling' => 'home remodeling',
                ];

                $servicePhrases = collect(config('nav.links'))
                    ->filter(fn ($l) => str_starts_with($l['href'] ?? '', '/services/'))
                    ->map(function ($link) use ($phraseBySlug) {
                        $slug = trim((string) \Illuminate\Support\Str::after((string) ($link['href'] ?? ''), '/services/'), '/');

                        if (isset($phraseBySlug[$slug])) {
                            return $phraseBySlug[$slug];
                        }

                        $base = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::singular((string) ($link['label'] ?? 'service')));

                        return str_contains($base, 'addition') ? 'home additions' : ($base . ' remodeling');
                    })
                    ->filter()
                    ->values();

                if (! $servicePhrases->contains('home remodeling')) {
                    $servicePhrases->push('home remodeling');
                }

                $servicePhrases = $servicePhrases->unique()->values();

                $serviceList = $servicePhrases->count() > 1
                    ? $servicePhrases->slice(0, -1)->implode(', ') . ', and ' . $servicePhrases->last()
                    : (string) $servicePhrases->first();

                $nearbyFaqCities = $area->nearestCities(2)->pluck('city')->implode(' and ');
                $homePostalCodes = array_values(array_slice($area->postalCodes(), 0, 10));
                $landmarkLine = filled($area->landmarks)
                    ? 'Local focus: ' . \Illuminate\Support\Str::limit((string) $area->landmarks, 140)
                    : null;
                $permitLine = filled($area->permit_notes)
                    ? 'Permit note: ' . \Illuminate\Support\Str::limit((string) $area->permit_notes, 120)
                    : null;

                $headings = [
                    $area->city . ' Home Remodeling Contractor',
                    $area->city . ' Remodeling Contractor for Kitchen, Bath & Home',
                    $area->city . ' Kitchen, Bathroom & Home Renovation Team',
                ];
                $subheadings = [
                    'Professional remodeling services for ' . $area->city . ' homeowners.',
                    'Family-run remodeling for kitchens, bathrooms, and whole-home projects in ' . $area->city . '.',
                    'Plan and build your next remodel in ' . $area->city . ' with clear scope, timeline, and pricing.',
                ];
                $intentIntros = [
                    "Homeowners in {$area->city} search most for {$serviceList}. Use the links below to jump directly to the exact service page you need.",
                    "This page is structured around real {$area->city} search intent: {$serviceList}.",
                    "If you're planning {$serviceList} in {$area->city}, start with the service links below for scope, timeline, and project examples.",
                ];

                $homeFaqs = [
                    ['question' => "What remodeling services do you offer in {$area->city}?", 'answer' => "We provide kitchen remodeling, bathroom remodeling, basement finishing, home additions, and full home renovation services in {$area->city}, IL."],
                    ['question' => "Do you provide free estimates in {$area->city}?", 'answer' => "Yes. We offer free in-home consultations and written estimates with clear scope, timeline, and pricing guidance for {$area->city} homeowners."],
                    ['question' => "Are you licensed and insured for {$area->city} projects?", 'answer' => "Yes. GS Construction is licensed and insured, and we manage local permitting and code-compliance steps required for your remodel."],
                ];

                if ($nearbyFaqCities !== '') {
                    $homeFaqs[] = [
                        'question' => "Do you also work near {$area->city}?",
                        'answer' => "Yes. In addition to {$area->city}, we frequently serve nearby communities such as {$nearbyFaqCities}.",
                    ];
                }

                if ($permitLine !== null) {
                    $homeFaqs[] = [
                        'question' => "Do you handle permitting in {$area->city}?",
                        'answer' => "Yes. We coordinate permit steps and inspections required for remodeling projects in {$area->city}. {$permitLine}",
                    ];
                }

                $homeSeo = [
                    'heading' => $headings[$toneIndex],
                    'subheading' => $subheadings[$toneIndex],
                    'intent_intro' => trim($intentIntros[$toneIndex] . ' ' . ($landmarkLine ?? '')),
                    'faqs' => $homeFaqs,
                ];

                // Slides deep-link the per-town service spokes (not the generic
                // services hub) — stronger anchors and one less hop.
                $homeSlides = [
                    [
                        'title' => 'Kitchens',
                        'button' => 'Kitchen Remodeling',
                        'link' => $area->serviceUrl('kitchen-remodeling'),
                        'projectType' => 'kitchen',
                        'alt' => "Kitchen remodeling services in {$area->city}",
                    ],
                    [
                        'title' => 'Bathrooms',
                        'button' => 'Bathroom Remodeling',
                        'link' => $area->serviceUrl('bathroom-remodeling'),
                        'projectType' => 'bathroom',
                        'alt' => "Bathroom remodeling services in {$area->city}",
                    ],
                    [
                        'title' => 'Home Remodels',
                        'button' => 'Home Remodeling',
                        'link' => $area->serviceUrl('home-remodeling'),
                        'projectType' => 'home-remodel',
                        'alt' => "Whole home remodeling services in {$area->city}",
                    ],
                ];
            @endphp
            
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                {{-- Visible, area-matched H1: leads with the city + full remodeling
                     scope so the page's primary heading matches its title and intent
                     (was an sr-only, kitchen-only heading). --}}
                <div class="mb-5">
                    <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Remodeling contractor in {{ $area->city }}, IL</p>
                    <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
                        {{ $area->city }} kitchen, bathroom &amp; whole-home remodeling
                    </h1>
                </div>
                <livewire:main-project-hero-slider
                    :slides="$homeSlides"
                    :area="$area"
                    :suppress-h1="true"
                    heading="{{ $homeSeo['heading'] }}"
                    subheading="{{ $homeSeo['subheading'] }}"
                    secondary-cta-text="Schedule Free Consult"
                    :secondary-cta-url="$area->pageUrl('contact')"
                />
            </div>

            <x-city-reviews-badge :area="$area" />

            {{-- Project count statement similar to ZIP code pages --}}
            @if ($projectCount > 0)
                <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                    <div class="rounded-lg bg-sky-50 px-6 py-4 dark:bg-sky-900/20">
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            We've completed <strong>{{ $projectCount }}</strong> projects in and around {{ $area->city }}.
                        </p>
                    </div>
                </section>
            @endif

            {{-- Real local proof: linked cards to actual completed projects in this
                 city. Renders only when we have matched local projects (the priority
                 cities), giving genuine internal links + anchor text to project detail
                 pages rather than templated filler. --}}
            @php
                $cityProjects = $area->localProjects(6);
                $projectsAreLocal = $cityProjects->isNotEmpty();
                if (! $projectsAreLocal) {
                    $cityProjects = $area->nearbyProjects(6);
                }
                $projectsHeading = $projectsAreLocal
                    ? "Remodeling projects we've completed in {$area->city}, IL"
                    : "Remodeling projects we've completed near {$area->city}, IL";
            @endphp
            @if($cityProjects->isNotEmpty())
                <section class="mx-auto max-w-7xl px-4 pb-4 sm:px-6 lg:px-8" aria-label="Projects completed {{ $projectsAreLocal ? 'in' : 'near' }} {{ $area->city }}">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ $projectsHeading }}
                    </h2>
                    {{-- Shared <x-project-grid>: same container and cards as
                         every other project grid. towns=true only when these
                         projects come from NEIGHBOURING towns, so each card
                         states where the job actually was. --}}
                    <x-project-grid
                        :projects="$cityProjects"
                        :towns="! $projectsAreLocal"
                        class="mt-5" />
                </section>
            @endif
            @include('livewire.partials.town-review-quotes')
            {{-- City-scoped Product schema for the services linked below — makes this
                 primary local landing page eligible for review-star / offer rich
                 results on "{service} {city}" searches. @id points at each canonical
                 /areas-served/{area}/services/{slug} detail page. Mirrors the visible
                 service links in the section that follows. --}}
            @foreach (['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling', 'basement-remodeling', 'home-additions'] as $areaHomeServiceSlug)
                <x-product-service-schema :service-slug="$areaHomeServiceSlug" :area="$area" />
            @endforeach

            <section class="bg-zinc-50 py-8 dark:bg-zinc-800/40">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Remodeling Services Homeowners Search for in {{ $area->city }}
                    </h2>
                    <p class="mt-2 max-w-4xl text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $homeSeo['intent_intro'] }}
                    </p>
                    {{-- "Contractor" on the kitchen chip is deliberate anchor-text
                         variation for the highest-intent query, not a typo. --}}
                    <x-service-chips
                        :area="$area"
                        :labels="['kitchen-remodeling' => 'Kitchen Remodeling Contractor']"
                        class="mt-5" />
                    @if(!empty($homePostalCodes))
                        <div class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">ZIP codes we commonly serve near {{ $area->city }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($homePostalCodes as $zip)
                                    <a href="{{ url('/service-area/' . $zip) }}" wire:navigate class="rounded-md bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-600">{{ $zip }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>

            {{-- High-intent pricing guidance (targets “[service] cost {city}” queries
                 and is heavily cited by AI Overviews). Ranges mirror the approved
                 figures in config/geo-answers.php + /faq. --}}
            <x-area-pricing-guide :area="$area" />

            {{-- Lead service line replacement (per-municipality program guide).
                 Summary comes from official-source research; the detail page is
                 noindexed until official info is verified for this town.
                 NOTE: keep this comment free of Blade directive words and keep the
                 summary logic in the PHP block below (not inline conditionals) —
                 the compiler extracts PHP blocks before stripping comments, so a
                 directive word inside a comment swallows everything to the next
                 comment closer. --}}
            @php
                $permitGuide = \App\Support\PermitGuideInfo::forSlug($area->slug);
                $leadInfo = \App\Support\LeadLineInfo::forSlug($area->slug);
                $leadHasCoverage = ($leadInfo['found_official_info'] ?? false)
                    && ! empty($leadInfo['cost_coverage'])
                    && ! preg_match('/not published/i', (string) $leadInfo['cost_coverage']);
                $leadSummary = $leadHasCoverage
                    ? \Illuminate\Support\Str::limit($leadInfo['cost_coverage'], 180) . ' Illinois law requires replacement over time — see how the program works, how to check your own line, and what it means mid-remodel.'
                    : 'Illinois law requires every water system to inventory and replace lead service lines — and many suburbs cover part or all of the cost. See how to check your ' . $area->city . ' line and what it means mid-remodel.';
            @endphp
            {{-- max-w-7xl, matching the 22 other sections on this page. It was the
                 lone max-w-5xl, so it sat visibly narrower than everything
                 above and below it. --}}
            <section class="mx-auto mt-10 max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="group rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900 shadow-sm transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">
                                Lead water pipe replacement in {{ $area->city }}
                            </h2>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                                {{ $leadSummary }}
                            </p>
                        </div>
                        <a href="{{ route('areas.lead-line', ['area' => $area->slug]) }}" wire:navigate
                           class="shrink-0 rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500">
                            {{ $area->city }} lead pipe guide →
                        </a>
                    </div>
                    @if($permitGuide)
                        <p class="mt-4 text-sm">
                            <a href="{{ route('permits.show', ['slug' => $area->slug]) }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">Building permit guide for {{ $area->city }} →</a>
                        </p>
                    @endif
                </div>
            </section>

            {{-- Unique per-city content (renders only when populated in DB).
                 Provides genuine differentiation between area pages — critical to
                 avoid Google's "duplicate content / thin local lander" penalty. --}}
            @if($area->hasUniqueContent() || filled($area->landmarks) || filled($area->permit_notes))
            @include('partials.area-intro-slider')
            @endif

            {{-- lazy: sits below the hero + services fold on every branch;
                 placeholder() renders until it scrolls near. --}}
            <livewire:about-section :area="$area" lazy />

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:timelapse-section />
            </div>

            <livewire:testimonials-section :area="$area" />
            
            <livewire:map-section :area="$area" />

            {{-- Nearby Areas — internal linking + local SEO signal --}}
            @php
                $nearbyHomeAreas = $area->nearestCities(8);
                if ($nearbyHomeAreas->isEmpty()) {
                    $nearbyHomeAreas = \App\Models\AreaServed::where('id', '!=', $area->id)
                        ->inRandomOrder()->take(6)->get();
                }
            @endphp
            @if($nearbyHomeAreas->count() > 0)
            <section class="bg-white py-12 dark:bg-zinc-900">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-2">
                        Remodeling Near {{ $area->city }}, IL
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        We also serve these nearby Chicago suburbs. Click any city for local remodeling info, projects, and reviews.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        @foreach($nearbyHomeAreas as $nearbyArea)
                            <a href="{{ $nearbyArea->url }}" wire:navigate
                               class="inline-flex items-center gap-2 rounded-lg bg-zinc-100 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                               title="Remodeling contractors in {{ $nearbyArea->city }}, IL">
                                <span>{{ $nearbyArea->city }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            <livewire:contact-section :area="$area" />

            @if(!empty($homeSeo['faqs']))
                <x-faq-section :faqs="$homeSeo['faqs']" :heading="'Remodeling FAQ in ' . $area->city" />
            @endif
            @break

        @case('contact')
            {{-- Area Contact Page --}}
            @php
                $homeSlides = [
                    [
                        'title' => "Schedule Your Free Consultation",
                        'subheading' => 'We’ll meet at your home soon to learn about your goals and project needs.',
                        'button' => 'Get a Free Quote',
                        'link' => '#contact-form',
                        'projectType' => 'bathroom',
                        'alt' => "Home remodeling services in {$area->city}",
                    ],
                    [
                        'title' => "Let's Start Your Project",
                        'subheading' => 'Ready to transform your home? Schedule a free consultation with Greg & Patryk.',
                        'button' => 'Start Your Project',
                        'link' => '#contact-form',
                        'projectType' => 'home-remodel',
                        'alt' => "Remodeling contractor in {$area->city}",
                    ],
                    [
                        'title' => "Start Your Home Project",
                        'subheading' => 'Get a free consultation and clear next steps from GS Construction.',
                        'button' => 'Request Free Consultation',
                        'link' => '#contact-form',
                        'projectType' => 'kitchen',
                        'alt' => "{$area->city} remodeling and renovation services",
                    ],
                ];

                shuffle($homeSlides);
            @endphp

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:main-project-hero-slider
                    :slides="$homeSlides"
                    :area="$area"
                    height-classes="h-[360px] sm:h-[380px] lg:h-[420px]"
                    :autoplay-interval="8000"
                />
            </div>

            <div id="contact-form" class="scroll-mt-24">
                <livewire:contact-section :area="$area" />
            </div>

            {{-- Per-city unique content — breaks the 25-cluster /contact near-duplicate
                 group surfaced by seo:area-pages-audit (May 2026). --}}
            @include('partials.area-unique-content', ['area' => $area, 'context' => 'contact'])

            <livewire:map-section :area="$area" />

            <livewire:testimonials-section :area="$area" />

            {{-- Services Section --}}
            @include('partials.services-grid', ['area' => $area])

            {{-- Closing CTA, scoped to this town: About Us goes to the area's
                 own about page, not the company-wide one. --}}
            <x-cta-section
                variant="blue"
                heading="Want to know who you're hiring?"
                :description="'GS Construction is a father-and-son company — Gregory and Patryk are on your ' . $area->city . ' job personally. Read how the company started and how we work.'"
                primaryText="About Us"
                :primaryHref="$area->pageUrl('about')"
                secondaryText="View Our Work"
                :secondaryHref="$area->pageUrl('projects')"
            />
            @break

        @case('testimonials')
            {{-- Area Testimonials Page --}}
            @php
                // Unique per-town proof numbers: local + nearby review counts
                // with real reviewer towns, so each spoke carries text no
                // sibling page shares.
                $spokeLocalQuotes = $area->localTestimonials(3);
                $spokeNearbyQuotes = $spokeLocalQuotes->isEmpty() ? $area->nearbyTestimonials() : collect();
                $spokeReviewerTowns = $spokeLocalQuotes->concat($spokeNearbyQuotes)
                    ->map(fn ($t) => trim(\Illuminate\Support\Str::before((string) $t->project_location, ',')))
                    ->filter()->unique()->take(4)->values();
            @endphp
            <div class="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8 text-center">
                <p class="text-sm font-semibold uppercase tracking-widest text-sky-600 dark:text-sky-400">Testimonials</p>
                <h1 class="mt-2 font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                    {{ $area->city }} Remodeling Reviews
                </h1>
                <p class="mt-4 mx-auto max-w-2xl text-lg text-zinc-600 dark:text-zinc-300">
                    @if($spokeLocalQuotes->isNotEmpty())
                        Verified reviews from {{ $area->city }} homeowners — real projects, real names, photographed by our own crews.
                    @elseif($spokeReviewerTowns->isNotEmpty())
                        What homeowners near {{ $area->city }} — in {{ $spokeReviewerTowns->implode(', ') }} — say about working with GS Construction.
                    @else
                        Read what homeowners across Chicago's northwest suburbs say about working with GS Construction.
                    @endif
                </p>
            </div>

            <x-city-reviews-badge :area="$area" />

            <livewire:testimonials-grid :area="$area" :show-header="false" />

            <livewire:map-section :area="$area" />

            <livewire:testimonials-section :area="$area" :show-header="false" />

            @include('partials.area-service-links', ['area' => $area])
            @break

        @case('projects')
            {{-- Area Projects Page --}}
            @php
                // Data-driven unique intro: real project counts, towns, and
                // types near THIS city. Differentiates the spoke from 80
                // sibling pages that Google was clustering as duplicates.
                $spokeProjects = $area->nearbyProjects(12);
                $spokeTowns = $spokeProjects->map(fn ($p) => trim(\Illuminate\Support\Str::before((string) $p->location, ',')))
                    ->filter()->unique()->take(5)->values();
                $spokeTypes = $spokeProjects->pluck('project_type')->filter()->unique()
                    ->map(fn ($t) => str_replace('-', ' ', (string) $t))->take(4)->values();
            @endphp
            @php
                // Authored here, rendered by the grid BELOW its hero slider —
                // the copy talks about the work the hero is showing, and the
                // gallery's own header should lead the page.
                $spokeNearby = $area->completedProjectsNearby();
                $spokeIntroHeading = $area->city . ' Remodeling Projects';
                $spokeIntroBody = null;

                if ($spokeProjects->isNotEmpty()) {
                    $spokeIntroBody = ($spokeNearby && $spokeNearby['count'] > 0)
                        ? number_format($spokeNearby['count']) . ' projects completed within ' . $spokeNearby['radius']
                            . ' miles of ' . $area->city . '. Browse the ' . $spokeTypes->implode(', ')
                            . ' work we have photographed in and around ' . $area->city
                            . ($spokeTowns->isNotEmpty() ? ' — including ' . $spokeTowns->implode(', ') : '') . '. '
                            . "Every photo below is our own crews' work, with the owners on site from demo to final walkthrough."
                        : 'Browse completed ' . $spokeTypes->implode(', ') . ' projects photographed in and around '
                            . $area->city . ($spokeTowns->isNotEmpty() ? ' — including work in ' . $spokeTowns->implode(', ') : '') . '. '
                            . "Every photo below is our own crews' work, with the owners on site from demo to final walkthrough.";
                }
            @endphp

            <livewire:projects-grid
                :area="$area"
                :show-about="true"
                :intro-heading="$spokeIntroHeading"
                :intro-body="$spokeIntroBody" />

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:timelapse-section />
            </div>

            {{-- Crawlable per-town proof line (completed-project radius count) --}}
            <livewire:map-section :area="$area" />

            <x-cta-section 
                variant="blue"
                heading="Ready to Start Your {{ $area->city }} Project?"
                description="Let's discuss your vision. Schedule a free consultation with Greg & Patryk."
                primary-cta-text="Schedule Free Consultation"
                :primary-cta-url="$area->pageUrl('contact')"
                secondary-cta-text="About Us"
                :secondary-cta-url="$area->pageUrl('about')"
            />

            @include('partials.area-service-links', ['area' => $area])
            @break

        @case('about')
            {{-- Area About Page --}}
            @php
                $galleryImages = \App\Models\ProjectImage::query()
                    ->with('project')
                    ->whereHas('project')
                    ->select('project_images.*')
                    ->join(
                        \DB::raw('(SELECT MIN(id) as min_id FROM project_images GROUP BY project_id ORDER BY RAND() LIMIT 6) as unique_projects'),
                        'project_images.id', '=', 'unique_projects.min_id'
                    )
                    ->inRandomOrder()
                    ->get();
            @endphp
            
            <div class="isolate">
                <!-- Hero section -->
                <div class="relative isolate -z-10">
                    <x-decor-blobs />
                    
                    <div class="overflow-hidden">
                        <div class="mx-auto max-w-7xl px-6 pt-12 pb-16 sm:pt-16 lg:px-8 lg:pt-12">
                            <div class="mx-auto max-w-2xl gap-x-14 lg:mx-0 lg:flex lg:max-w-none lg:items-center">
                                <div class="relative w-full lg:max-w-xl lg:shrink-0 xl:max-w-2xl">
                                    <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">About Us</p>
                                    <h1 class="font-heading mt-2 text-4xl font-bold tracking-tight text-zinc-900 sm:text-6xl dark:text-white">
                                        Serving {{ $area->city }} with Quality Craftsmanship
                                    </h1>
                                    <p class="mt-8 text-lg font-medium text-zinc-600 sm:max-w-md sm:text-xl/8 lg:max-w-none dark:text-zinc-300">
                                        GS Construction & Remodeling is a family business serving {{ $area->city }} homeowners. Run by Gregory and Patryk, a father-son duo with over 40 years of combined experience.
                                    </p>
                                    <p class="mt-4 text-base text-zinc-500 sm:max-w-md lg:max-w-none dark:text-zinc-400">
                                        From the initial consultation to the final walkthrough, we're personally involved in your {{ $area->city }} project. We believe in building lasting relationships with our clients, not just beautiful spaces.
                                    </p>
                                </div>
                                
                @include('partials.about-gallery')
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Same partial /about renders. This page used to carry its
                     own copy of the mission + stats — same sentences, same
                     three figures — so every stat change had to be made
                     twice. The partial is area-aware, so the heading reads
                     "Our Mission in {city}". --}}
                @include('partials.about-mission', ['area' => $area])

                <!-- Greg & Patryk Section -->
                {{-- :area passed so the team blurb can name the town — the main
                     /about does the same, this call was just missing it. --}}
                <livewire:about-section variant="team" :area="$area" />

                {{-- The founders' story and full bios, shared with /about.
                     These pages had only the short team summary; the story of
                     how the company grew, the individual bios, and why the
                     father-son pairing works are the reason someone trusts a
                     contractor, and they were main-page-only. --}}
                @include('partials.about-story')

                @include('partials.about-founder-bios')

                @include('partials.about-pairing')

                {{-- Same partials the main /about renders. Both were already
                     written to be area-aware ("Our Values Serving {city}"), but
                     only /about ever included them, so these pages were missing
                     content that had been authored for them.

                     The comparison block also links /compare and its competitor
                     pages from ~70 more crawled pages, which is exactly what it
                     exists to do. --}}
                @include('partials.about-values', ['area' => $area])

                @include('partials.about-comparison')

                <x-cta-section 
                    variant="blue"
                    heading="Ready to Transform Your {{ $area->city }} Home?"
                    description="Let's discuss your project. Schedule a free consultation and see why {{ $area->city }} homeowners trust GS Construction."
                    primaryText="Schedule Free Consultation"
                    :primaryHref="$area->pageUrl('contact')"
                    secondaryText="View Our Work"
                    :secondaryHref="$area->pageUrl('projects')"
                />
            </div>

            @include('partials.area-service-links', ['area' => $area])
            @break

        @case('services')
            {{-- Area Services Page --}}
            @php
                $serviceSlides = [
                    [
                        'heading' => $area->city . ' Kitchen Remodeling',
                        'subheading' => 'Transform your kitchen with custom cabinets, countertops, and complete renovations',
                        'type' => 'kitchen',
                    ],
                    [
                        'heading' => $area->city . ' Bathroom Remodeling',
                        'subheading' => 'Create your personal spa retreat with luxury showers, tubs, and tile work',
                        'type' => 'bathroom',
                    ],
                    [
                        'heading' => $area->city . ' Home Remodeling',
                        'subheading' => 'Complete home renovations, room additions, and open floor plans',
                        'type' => 'home-remodel',
                    ],
                ];
            @endphp
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:main-project-hero-slider 
                    project-type="mixed"
                    :slides="$serviceSlides"
                    primary-cta-text="Get a Free Quote"
                    :primary-cta-url="$area->pageUrl('contact')"
                    secondary-cta-text="View Our Work"
                    :secondary-cta-url="$area->pageUrl('projects')"
                />
            </div>

            {{-- City-scoped Product schema for every service in the grid below — this
                 area services listing page becomes eligible for review-star / offer
                 rich results on "{service} {city}" searches. @id points at each
                 canonical /areas-served/{area}/services/{slug} detail page. --}}
            @foreach (['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling', 'basement-remodeling', 'home-additions', 'mudroom-remodeling'] as $areaServicesSlug)
                <x-product-service-schema :service-slug="$areaServicesSlug" :area="$area" />
            @endforeach

            {{-- Services Grid --}}
            @include('partials.services-grid', ['area' => $area])
            @break

        @case('kitchen-remodeling')
        @case('bathroom-remodeling')
        @case('home-remodeling')
        @case('basement-remodeling')
        @case('home-additions')
        @case('service')
            {{-- Area-Specific Service Page (e.g., Palatine Bathroom Remodeling) --}}
            @php
                // Map URL slugs to internal service keys
                $requestedService = $service ?? $page;
                $serviceKey = match($requestedService) {
                    'kitchen-remodeling' => 'kitchen-remodeling',
                    'bathroom-remodeling' => 'bathroom-remodeling',
                    'home-remodeling' => 'home-remodeling',
                    'basement-remodeling' => 'basement-remodeling',
                    'home-additions' => 'home-additions',
                    default => $requestedService,
                };
                
                $serviceConfig = [
                    'kitchen-remodeling' => [
                        'label' => 'Kitchen Remodeling',
                        'projectType' => 'kitchen',
                        'urlSlug' => 'kitchen-remodeling',
                        'heading' => $area->city . ' Kitchen Remodeling',
                        'subheading' => 'Transform your kitchen with custom cabinets, countertops, and modern designs',
                        'description' => "Looking for professional kitchen remodeling in {$area->city}? GS Construction specializes in complete kitchen renovations, from cabinet installation to countertop upgrades. Our family-owned business has served {$area->city} homeowners for years with quality craftsmanship.",
                        'features' => [
                            'Custom cabinet design and installation',
                            'Granite, quartz, and marble countertops',
                            'Kitchen island and layout optimization',
                            'Modern lighting and electrical upgrades',
                            'Flooring installation',
                            'Backsplash and tile work',
                        ],
                        'faqs' => [
                            ['question' => "How much does kitchen remodeling cost in {$area->city}?", 'answer' => "Kitchen remodeling in {$area->city} typically runs \$35,000–\$80,000, with custom work above \$100,000 — driven by scope, materials, and whether the layout or plumbing moves. Your estimate is itemized line by line before demo day, so you can see exactly what drives the number."],
                            ['question' => "What is a reasonable budget for a kitchen remodel?", 'answer' => "A good rule of thumb is to budget 5–15% of your home's value for a kitchen remodel. For most {$area->city} homes, that translates to \$30,000–\$80,000. We work with a range of budgets and help you prioritize upgrades that deliver the most impact for your investment."],
                            ['question' => "How long does a kitchen remodel take in {$area->city}?", 'answer' => "Most kitchen remodels in {$area->city} run 8–12 weeks on site, from demolition to the final walkthrough. Simple cosmetic updates can be faster; layout changes, custom cabinetry or structural work sit at the top of that range. We hand you a written schedule before demo day and keep it updated throughout."],
                            ['question' => "Do you handle kitchen remodeling permits in {$area->city}?", 'answer' => "Yes, GS Construction handles all necessary permits for {$area->city} kitchen remodeling projects. Electrical, plumbing, and structural work typically require permits — we're familiar with local building codes and manage the entire permitting process for you."],
                            ['question' => "Can you remodel my kitchen while I live in my {$area->city} home?", 'answer' => "Absolutely. Most of our {$area->city} clients stay in their homes during kitchen remodels. We set up a temporary kitchen area with your microwave, coffee maker, and a prep surface, and we clean up the work area daily to minimize disruption."],
                            ['question' => "What does a full kitchen remodel include?", 'answer' => "A full kitchen remodel with GS Construction typically includes demolition of existing finishes, new cabinetry, countertop installation (quartz, granite, or marble), backsplash tile, flooring, lighting fixtures, plumbing fixtures, electrical updates, and painting. We can also handle layout changes, island additions, and appliance relocation."],
                        ],
                    ],
                    'bathroom-remodeling' => [
                        'label' => 'Bathroom Remodeling',
                        'projectType' => 'bathroom',
                        'urlSlug' => 'bathroom-remodeling',
                        'heading' => $area->city . ' Bathroom Remodeling',
                        'subheading' => 'Create your dream bathroom with custom showers, vanities, and tile work',
                        'description' => "Need bathroom remodeling in {$area->city}? GS Construction delivers stunning bathroom renovations, from walk-in showers to complete master bath transformations. We've helped countless {$area->city} families create beautiful, functional bathrooms.",
                        'features' => [
                            'Walk-in shower and tub installation',
                            'Custom vanity and cabinetry',
                            'Tile flooring and wall installation',
                            'Plumbing fixture upgrades',
                            'Heated flooring systems',
                            'Accessibility modifications',
                        ],
                        'faqs' => [
                            ['question' => "How much does bathroom remodeling cost in {$area->city}?", 'answer' => "Bathroom remodeling costs vary based on the size of your space, finishes, and scope of work. We offer free estimates tailored to your {$area->city} project and vision."],
                            ['question' => "How long does a bathroom remodel take?", 'answer' => "The timeline depends on the scope of your renovation — tile work, fixture changes, and any structural modifications all factor in. We provide a detailed schedule before starting work."],
                            ['question' => "Do you install walk-in showers in {$area->city}?", 'answer' => "Yes! Walk-in showers are one of our most popular requests in {$area->city}. We install frameless glass, custom tile, and accessible designs for all needs."],
                            ['question' => "Can you make my bathroom more accessible?", 'answer' => "Absolutely. We specialize in accessibility modifications including grab bars, walk-in tubs, curbless showers, and wider doorways for {$area->city} homeowners."],
                        ],
                    ],
                    'home-remodeling' => [
                        'label' => 'Home Remodeling',
                        'projectType' => 'home-remodel',
                        'urlSlug' => 'home-remodeling',
                        'heading' => $area->city . ' Home Remodeling',
                        'subheading' => 'Complete home renovations, additions, and whole-house transformations',
                        'description' => "Planning a home remodel in {$area->city}? GS Construction handles complete home renovations, from open floor plan conversions to room additions. Our team brings 40+ years of experience to every {$area->city} project.",
                        'features' => [
                            'Open floor plan conversions',
                            'Room additions and extensions',
                            'Basement finishing',
                            'Interior redesign and layout changes',
                            'Structural modifications',
                            'Complete home renovation',
                        ],
                        'faqs' => [
                            ['question' => "What does whole home remodeling include in {$area->city}?", 'answer' => "Whole home remodeling in {$area->city} can include kitchen and bathroom renovations, open floor plan conversions, room additions, basement finishing, and complete interior updates. We customize every project to your needs."],
                            ['question' => "How long does a whole home remodel take?", 'answer' => "The timeline for a whole home remodel depends entirely on the scope — whether it includes structural changes, additions, or a full interior renovation. We create detailed project timelines and keep you updated throughout."],
                            ['question' => "Do you handle room additions in {$area->city}?", 'answer' => "Yes, we handle room additions including sunrooms, master suites, and second-story additions for {$area->city} homes. We manage everything from design through construction."],
                            ['question' => "Can you convert my {$area->city} home to an open floor plan?", 'answer' => "Open floor plan conversions are one of our specialties! We safely remove walls (including load-bearing walls with proper engineering) to create the modern, open layout you want."],
                        ],
                    ],
                    'basement-remodeling' => [
                        'label' => 'Basement Remodeling',
                        'projectType' => 'basement',
                        'urlSlug' => 'basement-remodeling',
                        'heading' => $area->city . ' Basement Remodeling',
                        'subheading' => 'Finish your basement into a comfortable, code-compliant living space',
                        'description' => "Need basement remodeling in {$area->city}? GS Construction transforms unfinished or outdated basements into practical, beautiful spaces for entertaining, guests, work, and everyday family life.",
                        'features' => [
                            'Basement finishing and layout planning',
                            'Family rooms, theaters, and rec spaces',
                            'Guest bedrooms with egress updates',
                            'Wet bars and basement bathrooms',
                            'Lighting, flooring, and trim carpentry',
                            'Code-compliant electrical and plumbing',
                        ],
                        'faqs' => [
                            ['question' => "How much does basement remodeling cost in {$area->city}?", 'answer' => "Basement remodeling costs depend on square footage, finishes, and whether plumbing or bathroom additions are included. We provide free in-home estimates with a clear scope and pricing."],
                            ['question' => "How long does a basement remodel take?", 'answer' => "Most basement remodels take several weeks depending on complexity, inspections, and finish selections. We share a detailed schedule before construction starts."],
                            ['question' => "Can you add a bathroom or wet bar in my basement?", 'answer' => "Yes. We regularly build basement bathrooms and wet bars, including code-compliant plumbing, electrical, ventilation, and finish work."],
                            ['question' => "Do you handle permits for basement projects in {$area->city}?", 'answer' => "Yes, we manage the permitting process and inspections required for basement remodeling in {$area->city}."],
                        ],
                    ],
                    'home-additions' => [
                        'label' => 'Home Additions',
                        'projectType' => 'addition',
                        'urlSlug' => 'home-additions',
                        'heading' => $area->city . ' Home Additions',
                        'subheading' => 'Expand your home with seamless additions designed to match your existing layout',
                        'description' => "Planning a home addition in {$area->city}? GS Construction builds room additions, expanded living spaces, and major layout upgrades that blend naturally with your existing home.",
                        'features' => [
                            'Room and family-room additions',
                            'Primary suite and bedroom expansions',
                            'Kitchen and dining area extensions',
                            'Sunrooms and enclosed porch conversions',
                            'Structural framing and roof tie-ins',
                            'Permit-ready plans and construction',
                        ],
                        'faqs' => [
                            ['question' => "How much do home additions cost in {$area->city}?", 'answer' => "Addition costs vary by size, structural scope, and finish level. We provide a detailed estimate and phased plan so you understand the full investment."],
                            ['question' => "How long does a home addition take?", 'answer' => "Timelines depend on design, permitting, and construction scope. Most additions take multiple phases, and we provide a project timeline before work begins."],
                            ['question' => "Will a new addition match my current home?", 'answer' => "Yes. We design and build additions to align with your existing rooflines, materials, and architectural style for a cohesive final result."],
                            ['question' => "Do you handle permits and inspections for additions in {$area->city}?", 'answer' => "Absolutely. We coordinate permits, inspections, and code compliance from planning through final walkthrough."],
                        ],
                    ],
                ];
                $config = $serviceConfig[$serviceKey] ?? $serviceConfig['home-remodeling'];
                
                // Get geographically nearest areas for internal linking (Haversine, cached 24h).
                // Falls back to random if coordinates aren't set yet.
                $nearbyAreas = $area->nearestCities(8);
                if ($nearbyAreas->isEmpty()) {
                    $nearbyAreas = \App\Models\AreaServed::where('id', '!=', $area->id)
                        ->inRandomOrder()
                        ->take(6)
                        ->get();
                }

                $serviceTone = abs(crc32($area->slug . '|' . $config['urlSlug'])) % 3;
                $nearbyList = $nearbyAreas->take(3)->pluck('city')->implode(', ');
                $servicePostalCodes = array_values(array_slice($area->postalCodes(), 0, 10));
                $landmarkSnippet = filled($area->landmarks)
                    ? \Illuminate\Support\Str::limit((string) $area->landmarks, 140)
                    : null;
                $permitSnippet = filled($area->permit_notes)
                    ? \Illuminate\Support\Str::limit((string) $area->permit_notes, 120)
                    : null;

                // Tail sentences lead with how the job is run in this town — who
                // is on site, how the scope is priced, who pulls the permits —
                // rather than with finish decisions. The materials are the
                // homeowner's to choose; the job is ours.
                $descriptionTail = match ($serviceTone) {
                    0 => "On a {$area->city} project that means an owner on site daily, an itemized scope before demo day, and the village permits pulled by us.",
                    1 => "Homeowners in {$area->city} compare timeline, scope and permit handling first — all three are handled by the owners rather than passed to a project manager.",
                    default => "For {$config['label']} in {$area->city} we run one scope, one written schedule, and one point of contact from the free in-home estimate to the punch list.",
                };

                if ($landmarkSnippet) {
                    $descriptionTail .= ' Local context: ' . $landmarkSnippet . '.';
                }
                $config['description'] .= ' ' . $descriptionTail;

                $config['contentSections'] = [
                    [
                        'heading' => "Planning {$config['label']} in {$area->city}",
                        'body' => "Most {$area->city} projects start with scope alignment: what to do now, what to phase later, and what the budget actually buys. Gregory or Patryk walks the space themselves, takes real measurements, and prices labor, materials, demolition and disposal line by line — not one number you cannot check.",
                    ],
                    [
                        'heading' => "Execution and permitting in {$area->city}",
                        'body' => trim("We handle sequencing, trade coordination, and code-compliance requirements for {$config['label']} projects in {$area->city}. " . ($permitSnippet ? 'Permit context: ' . $permitSnippet . '.' : 'Permit requirements are reviewed before construction starts.')),
                    ],
                ];

                if ($nearbyList !== '') {
                    $config['contentSections'][] = [
                        'heading' => "Nearby service coverage from {$area->city}",
                        'body' => "In addition to {$area->city}, we regularly complete {$config['label']} projects in nearby communities including {$nearbyList}. This helps keep crews, vendors, and scheduling logistics efficient across the area.",
                    ];
                }

                $dynamicFaqs = [
                    [
                        'question' => "How do you scope {$config['label']} projects in {$area->city}?",
                        'answer' => "We begin with an in-home consultation, define must-have vs. optional scope, and provide a phased plan when needed so {$area->city} homeowners can move forward with confidence.",
                    ],
                    [
                        'question' => "Can you coordinate design and build decisions for {$area->city} projects?",
                        'answer' => "Yes. We help align layout, material, and sequencing decisions early so your {$config['label']} project in {$area->city} moves efficiently from planning to final walkthrough.",
                    ],
                ];

                if ($nearbyList !== '') {
                    $dynamicFaqs[] = [
                        'question' => "Do you serve areas near {$area->city} for {$config['label']}?",
                        'answer' => "Yes. We also support {$config['label']} projects in nearby communities such as {$nearbyList}.",
                    ];
                }

                $config['faqs'] = array_merge($config['faqs'], $dynamicFaqs);
            @endphp
            
            {{-- Service Schema for rich results --}}
            <x-service-schema :service="$config" :area="$area" />

            {{-- Product Schema — required for Google review-star rich results (Service alone won't render stars). --}}
            <x-product-service-schema :service-slug="$config['urlSlug']" :area="$area" />
            
            {{-- Hero with projects slider --}}
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:main-project-hero-slider 
                    :project-type="$config['projectType']"
                    :area="$area"
                    :slides="[
                        [
                            'heading' => $config['heading'],
                            'subheading' => $config['subheading'],
                            'type' => $config['projectType'],
                        ],
                        [
                            'heading' => $config['heading'],
                            'subheading' => $config['subheading'],
                            'type' => $config['projectType'],
                        ],
                        [
                            'heading' => $config['heading'],
                            'subheading' => $config['subheading'],
                            'type' => $config['projectType'],
                        ],
                    ]"
                    primary-cta-text="Get Free Quote"
                    :primary-cta-url="$area->pageUrl('contact')"
                    secondary-cta-text="View {{ $config['label'] }} Projects"
                    :secondary-cta-url="$area->pageUrl('projects')"
                    {{-- Taller on NARROW screens, not shorter: the hero content is
                         bottom-aligned inside an overflow-hidden box, so when
                         "Arlington Heights Bathroom Remodeling" wraps to three
                         lines the heading is clipped off the TOP rather than
                         pushing the box down. Measured worst case below 480px is
                         494px of content; 340px was cutting up to 154px off every
                         area service page, not just the long city names.

                         lg gets its own bump for the same reason: at exactly
                         1024px the type is at its largest but the column is not
                         yet wide enough to hold "Arlington Heights Bathroom
                         Remodeling" on two lines, so 520px clipped 34px. xl
                         drops back to 520px once the line fits. --}}
                    height-classes="h-[520px] min-[480px]:h-[420px] lg:h-[600px] xl:h-[520px]"
                />
            </div>

            {{-- About Section with service-specific keywords --}}
            <livewire:about-section 
                variant="service" 
                :area="$area" 
                :service-title="$config['label']" 
                :service-short-title="$config['label']" 
            />

            {{-- Per-city unique content — breaks the 12 /services/kitchen-remodeling
                 (and adjacent service) near-duplicate clusters surfaced by
                 seo:area-pages-audit (May 2026). Heading + landmarks + permit notes
                 are interpolated per (city, service) so each URL has unique prose. --}}
            @include('partials.area-unique-content', ['area' => $area, 'context' => $config['urlSlug']])


            {{-- REMOVED: the "Long-Form Content Sections (SEO depth)" block —
                 Planning / Execution and permitting / Nearby service coverage.

                 113 words of templated copy rendered on 420 pages (70 areas × 6
                 services), 75.6% identical between two towns for the same
                 service; only the city token and the nearby-town list varied.
                 Added to bulk pages up, but at that scale templated city-swap
                 paragraphs read as doorway content rather than depth.

                 Nothing unique was lost: the genuinely local material
                 (local_intro, landmarks, permit_notes) renders in the intro
                 block above, with the real permit text rather than the
                 mid-word "…in the...." truncation this block produced, and the
                 process paraphrase is superseded by the real six stages below.

                 $config['contentSections'] is still built in this file — left
                 in place so this is a one-line revert if the removal costs
                 rankings. --}}

            {{-- Our process — the SAME six stages as /services/{service} and
                 /process, read from config('services-content.process').

                 Not duplicated here: a visitor who lands on the Schaumburg
                 kitchen page from search should see how the job actually runs,
                 and if these ever differed from the main service page they
                 would describe two different companies. One source, three
                 places that render it. --}}
            @php $areaProcess = (array) config('services-content.process', []); @endphp
            @if($areaProcess !== [])
            <x-process-steps
                :steps="$areaProcess"
                heading="How your {{ $area->city }} project runs"
                class="bg-zinc-50 dark:bg-zinc-800/50" />
            @endif

            {{-- Testimonials, straight after the process: someone who has just
                 read how the job runs is at the point of asking "do people
                 actually get that?" — proof answers it. The coverage/ZIP list
                 is reference material and sits further up with the service
                 detail. --}}
            <livewire:testimonials-section :area="$area" />

            {{-- Timelapse Section --}}
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:timelapse-section />
            </div>

            {{-- Projects for this service type. Same props as /services/{service}
                 — limit + pagination — so the grid behaves identically, and the
                 same "More X Projects" footer so the page has a route onward
                 instead of ending on a grid. --}}
            <livewire:projects-grid
                :area="$area"
                :type="$config['projectType']"
                :limit="3"
                :hide-filters="true"
                :show-pagination="true"
                :hide-when-empty="true"
                :more-projects-type="$config['projectType']" />

            {{-- Town-attributed review quotes (real reviewer towns, never faked) --}}
            @include('livewire.partials.town-review-quotes')

            {{-- Cost-guide cross-link: pairs the money page with its matching
                 cost guide the way searchers actually navigate (service ↔ cost). --}}
            @php
                $costGuideSlug = [
                    'kitchen-remodeling' => 'kitchen-remodel-cost',
                    'bathroom-remodeling' => 'bathroom-remodel-cost',
                    'basement-remodeling' => 'basement-finishing-cost',
                    'home-additions' => 'home-addition-cost',
                ][$service] ?? null;
                $permitGuideUrl = \App\Support\PermitGuideInfo::forSlug($area->slug) !== null
                    ? route('permits.show', ['slug' => $area->slug])
                    : route('permits.index');
            @endphp
            @if($costGuideSlug)
                <div class="mx-auto max-w-7xl px-4 pb-2 sm:px-6 lg:px-8">
                    <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 dark:border-sky-500/20 dark:bg-sky-500/5">
                        <p class="text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                            <span class="font-semibold text-zinc-900 dark:text-white">Budgeting first?</span>
                            See real {{ now()->year }} price ranges in our
                            <a href="{{ route('costs.show', ['slug' => $costGuideSlug]) }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ strtolower($config['label'] ?? 'remodeling') }} cost guide</a>
                            — and what your {{ $area->city }} permit will add in our
                            <a href="{{ $permitGuideUrl }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">permit guide</a>.
                        </p>
                    </div>
                </div>
            @endif

            {{-- Map Section --}}
            <livewire:map-section :area="$area" />

            {{-- Other Services in This City (cross-service internal linking) --}}
            <section class="bg-zinc-50 py-12 dark:bg-zinc-800/50">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-6">
                        More Remodeling Services in {{ $area->city }}
                    </h2>
                    <x-service-chips :area="$area" :exclude="$config['urlSlug']" />
                </div>
            </section>

            {{-- Contact Section --}}
            <livewire:contact-section :area="$area" />

            {{-- Coverage (ZIPs + nearby towns) sits AFTER the contact form.
                 It is reference material — "do you come to my street?" — not
                 something a visitor reads before deciding to get in touch, and
                 it doubles as the internal-linking block that should sit near
                 the end of the page. --}}
            <section class="bg-zinc-50 py-8 dark:bg-zinc-800/40">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $config['label'] }} Coverage Around {{ $area->city }}</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">We provide {{ strtolower($config['label']) }} in {{ $area->city }} and nearby communities to keep schedules, crews, and permitting coordination efficient.</p>

                    @if(!empty($servicePostalCodes))
                        <div class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Local ZIP coverage</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($servicePostalCodes as $zip)
                                    <a href="{{ url('/service-area/' . $zip) }}" wire:navigate class="rounded-md bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 shadow-sm hover:bg-zinc-100 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ $zip }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Merged in from the old "{Label} in Nearby Areas" section
                         further down the page: it listed the SAME $nearbyAreas
                         with the SAME serviceUrl() links, just uncapped and with
                         distance badges. Two sections asking the reader to pick a
                         neighbouring town. This is the richer version, kept whole. --}}
                    @if($nearbyAreas->count() > 0)
                        <div class="mt-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Nearby communities</p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Click a town for {{ strtolower($config['label']) }} information specific to it.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-3">
                                @foreach($nearbyAreas as $nearbyArea)
                                    <a href="{{ $nearbyArea->serviceUrl($config['urlSlug']) }}" wire:navigate
                                       class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-100 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-700"
                                       title="{{ $config['label'] }} services in {{ $nearbyArea->city }}, IL">
                                        <span>{{ $nearbyArea->city }} {{ $config['label'] }}</span>
                                        @if(isset($nearbyArea->distance_miles))
                                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-3xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                                {{ number_format($nearbyArea->distance_miles, 1) }} mi
                                            </span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>
            @break

        @default
            {{-- Fallback to home --}}
            <livewire:about-section />
    @endswitch

    {{-- FAQ Section (visible + schema — just above footer) --}}
    @if(isset($config) && !empty($config['faqs']))
        <x-faq-section :faqs="$config['faqs']" :heading="$config['label'] . ' FAQ in ' . $area->city" />
    @endif

    {{-- About page: show map above the "Explore {City}" navigation footer. --}}
    @if($page === 'about')
        <livewire:map-section :area="$area" />
    @endif

    {{-- Area Navigation --}}
    <section class="border-t border-zinc-200 bg-zinc-50 py-8 dark:border-zinc-700 dark:bg-zinc-800/50">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="mb-4 text-sm font-medium text-zinc-500 dark:text-zinc-400">
                Explore {{ $area->city }}:
            </p>
            <nav class="flex flex-wrap gap-3">
                <a href="{{ $area->url }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $page === 'home' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Home
                </a>
                <a href="{{ $area->serviceUrl('kitchen-remodeling') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $service === 'kitchen-remodeling' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Kitchen
                </a>
                <a href="{{ $area->serviceUrl('bathroom-remodeling') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $service === 'bathroom-remodeling' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Bathroom
                </a>
                <a href="{{ $area->serviceUrl('home-remodeling') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $service === 'home-remodeling' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Home Remodel
                </a>
                <a href="{{ $area->pageUrl('projects') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $page === 'projects' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Projects
                </a>
                <a href="{{ $area->pageUrl('testimonials') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $page === 'testimonials' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Reviews
                </a>
                <a href="{{ $area->pageUrl('about') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $page === 'about' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    About
                </a>
                <a href="{{ $area->pageUrl('contact') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $page === 'contact' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Contact
                </a>
            </nav>
        </div>
    </section>
</div>
