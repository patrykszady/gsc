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
    <x-breadcrumb-schema :items="$breadcrumbItems" />

    {{-- Per-area LocalBusiness schema (with geo, hours, postalCodes, hasMap) --}}
    <x-area-local-business-schema :area="$area" />

    {{-- ImageGallery schema: surfaces this city's project photos in Google Images / Photos carousel --}}
    <x-area-image-gallery-schema :area="$area" />

    {{-- Visual Breadcrumb Navigation --}}
    <div class="mx-auto max-w-7xl px-4 py-1 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="{{ route('areas.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Areas Served</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    @if($page === 'home')
                        <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $area->city }}</span>
                    @else
                        <a href="{{ $area->url }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">{{ $area->city }}</a>
                    @endif
                </li>
                @if($page === 'service' && $service)
                    <li class="flex items-center">
                        <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                        </svg>
                        <a href="{{ $area->pageUrl('services') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Services</a>
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                        </svg>
                        @php
                            $serviceLabels = ['kitchen-remodeling' => 'Kitchens', 'bathroom-remodeling' => 'Bathrooms', 'home-remodeling' => 'Home Remodeling'];
                        @endphp
                        <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $serviceLabels[$service] ?? ucfirst($service) }}</span>
                    </li>
                @elseif($page !== 'home')
                    <li class="flex items-center">
                        <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                        </svg>
                        <span class="ml-2 text-gray-700 dark:text-gray-300">{{ ucfirst($page) }}</span>
                    </li>
                @endif
            </ol>
        </nav>
    </div>

    @switch($page)
        @case('home')
            {{-- Area Home Page --}}
            @php
                $homeSlides = [
                    [
                        'title' => 'Kitchens',
                        'button' => 'Kitchen Remodeling',
                        'link' => $area->pageUrl('services'),
                        'projectType' => 'kitchen',
                        'alt' => "Kitchen remodeling services in {$area->city}",
                    ],
                    [
                        'title' => 'Bathrooms',
                        'button' => 'Bathroom Remodeling',
                        'link' => $area->pageUrl('services'),
                        'projectType' => 'bathroom',
                        'alt' => "Bathroom remodeling services in {$area->city}",
                    ],
                    [
                        'title' => 'Home Remodels',
                        'button' => 'Home Remodeling',
                        'link' => $area->pageUrl('services'),
                        'projectType' => 'home-remodel',
                        'alt' => "Whole home remodeling services in {$area->city}",
                    ],
                ];
            @endphp
            
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:main-project-hero-slider 
                    :slides="$homeSlides"
                    :area="$area"
                    heading="{{ $area->city }} Kitchen & Bathroom Remodeling"
                    subheading="Professional remodeling services for {{ $area->city }} homeowners"
                    secondary-cta-text="Schedule Free Consult"
                    :secondary-cta-url="$area->pageUrl('contact')"
                />
            </div>

            <x-city-reviews-badge :area="$area" />

            {{-- Unique per-city content (renders only when populated in DB).
                 Provides genuine differentiation between area pages — critical to
                 avoid Google's "duplicate content / thin local lander" penalty. --}}
            @if($area->hasUniqueContent() || filled($area->landmarks) || filled($area->permit_notes))
            @php
                // Random project image slider for the city section (left column).
                // Mirror layout of the about-section (text/right) — image lives on the LEFT here.
                $citySliderImages = \App\Models\ProjectImage::query()
                    ->whereHas('project')
                    ->select('project_images.*')
                    ->join(
                        \DB::raw('(SELECT MIN(id) as min_id FROM project_images GROUP BY project_id ORDER BY RAND() LIMIT 6) as unique_projects_city'),
                        'project_images.id', '=', 'unique_projects_city.min_id'
                    )
                    ->inRandomOrder()
                    ->get();
            @endphp
            <section class="overflow-hidden bg-white py-10 sm:py-14 dark:bg-zinc-900" aria-label="About {{ $area->city }} remodeling">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="mx-auto grid max-w-2xl grid-cols-1 gap-x-12 gap-y-8 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-start">

                        {{-- LEFT: project image slider --}}
                        <div class="lg:mt-2">
                            @if($citySliderImages->count() > 0)
                                <div
                                    x-data="{
                                        current: 0,
                                        total: {{ $citySliderImages->count() }},
                                        timer: null,
                                        prev() { this.current = (this.current - 1 + this.total) % this.total; },
                                        next() { this.current = (this.current + 1) % this.total; },
                                        start() { this.timer = setInterval(() => this.next(), 3000); },
                                        stop()  { if (this.timer) clearInterval(this.timer); this.timer = null; },
                                    }"
                                    x-init="start()"
                                    @mouseenter="stop()"
                                    @mouseleave="start()"
                                    class="relative overflow-hidden rounded-2xl shadow-lg ring-1 ring-zinc-900/10 dark:ring-white/10"
                                >
                                    <div class="relative aspect-[4/3] w-full bg-zinc-100 dark:bg-zinc-800">
                                        @foreach($citySliderImages as $idx => $img)
                                            <div
                                                x-show="current === {{ $idx }}"
                                                x-transition:enter="transition ease-out duration-700"
                                                x-transition:enter-start="opacity-0"
                                                x-transition:enter-end="opacity-100"
                                                x-transition:leave="transition ease-in duration-700"
                                                x-transition:leave-start="opacity-100"
                                                x-transition:leave-end="opacity-0"
                                                class="absolute inset-0"
                                            >
                                                <x-lqip-image
                                                    :image="$img"
                                                    size="large"
                                                    aspectRatio="4/3"
                                                    rounded="2xl"
                                                    :alt="($img->seo_alt_text ?? $img->alt_text) ?: 'Remodeling project near ' . $area->city . ', IL'"
                                                    class="h-full w-full object-cover" />
                                            </div>
                                        @endforeach
                                    </div>

                                    @if($citySliderImages->count() > 1)
                                        <button
                                            type="button"
                                            @click="prev()"
                                            class="absolute left-3 top-1/2 z-10 -translate-y-1/2 rounded-full bg-white/85 p-2 text-zinc-900 shadow-sm transition hover:bg-white dark:bg-zinc-900/80 dark:text-white dark:hover:bg-zinc-900"
                                            aria-label="Previous slide"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M12.78 15.78a.75.75 0 01-1.06 0l-5.25-5.25a.75.75 0 010-1.06l5.25-5.25a.75.75 0 111.06 1.06L8.06 10l4.72 4.72a.75.75 0 010 1.06z" clip-rule="evenodd" />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            @click="next()"
                                            class="absolute right-3 top-1/2 z-10 -translate-y-1/2 rounded-full bg-white/85 p-2 text-zinc-900 shadow-sm transition hover:bg-white dark:bg-zinc-900/80 dark:text-white dark:hover:bg-zinc-900"
                                            aria-label="Next slide"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 011.06 0l5.25 5.25a.75.75 0 010 1.06l-5.25 5.25a.75.75 0 11-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 010-1.06z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    @endif

                                    {{-- Dots --}}
                                    @if($citySliderImages->count() > 1)
                                        <div class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 gap-2">
                                            @foreach($citySliderImages as $idx => $_img)
                                                <button
                                                    type="button"
                                                    @click="current = {{ $idx }}"
                                                    :class="current === {{ $idx }} ? 'bg-white' : 'bg-white/50 hover:bg-white/80'"
                                                    class="h-2 w-2 rounded-full transition"
                                                    aria-label="Show slide {{ $idx + 1 }}"></button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- RIGHT: city copy --}}
                        <div class="lg:pl-4">
                            <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                                Remodeling in {{ $area->city }}, IL
                            </h2>

                            @if(filled($area->intro))
                                <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                                    {{ $area->intro }}
                                </p>
                            @endif

                            @if(filled($area->local_intro))
                                <div class="mt-4 prose prose-zinc dark:prose-invert max-w-none">
                                    {!! nl2br(e($area->local_intro)) !!}
                                </div>
                            @endif

                            @if(filled($area->landmarks))
                                <div class="mt-6">
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        Neighborhoods &amp; landmarks we serve in {{ $area->city }}
                                    </h3>
                                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">{{ $area->landmarks }}</p>
                                </div>
                            @endif

                            @if(filled($area->permit_notes))
                                <div class="mt-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                                        {{ $area->city }} permits &amp; building codes
                                    </h3>
                                    <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $area->permit_notes }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
            @endif

            <livewire:about-section :area="$area" />

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:timelapse-section />
            </div>

            <livewire:testimonials-section :area="$area" />
            
            <livewire:map-section />

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

            <livewire:map-section />

            <livewire:testimonials-section :area="$area" />

            {{-- Services Section --}}
            @include('partials.services-grid', ['area' => $area])
            @break

        @case('testimonials')
            {{-- Area Testimonials Page --}}
            <div class="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8 text-center">
                <p class="text-sm font-semibold uppercase tracking-widest text-sky-600 dark:text-sky-400">Testimonials</p>
                <h1 class="mt-2 font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                    {{ $area->city }} Remodeling Reviews
                </h1>
                <p class="mt-4 mx-auto max-w-2xl text-lg text-zinc-600 dark:text-zinc-300">
                    Read what {{ $area->city }} homeowners say about working with GS Construction.
                </p>
            </div>

            <x-city-reviews-badge :area="$area" />

            <livewire:testimonials-grid :area="$area" :show-header="false" />

            <livewire:map-section />

            <livewire:testimonials-section :area="$area" :show-header="false" />
            @break

        @case('projects')
            {{-- Area Projects Page --}}
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:timelapse-section />
            </div>

            <livewire:projects-grid :area="$area" />

            <x-cta-section 
                variant="blue"
                heading="Ready to Start Your {{ $area->city }} Project?"
                description="Let's discuss your vision. Schedule a free consultation with Greg & Patryk."
                primary-cta-text="Schedule Free Consultation"
                :primary-cta-url="$area->pageUrl('contact')"
                secondary-cta-text="About Us"
                :secondary-cta-url="$area->pageUrl('about')"
            />
            @break

        @case('about')
            {{-- Area About Page --}}
            @php
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
                                        Serving {{ $area->city }} with Quality Craftsmanship
                                    </h1>
                                    <p class="mt-8 text-lg font-medium text-zinc-600 sm:max-w-md sm:text-xl/8 lg:max-w-none dark:text-zinc-300">
                                        GS Construction & Remodeling is a family business serving {{ $area->city }} homeowners. Run by Gregory and Patryk, a father-son duo with over 40 years of combined experience.
                                    </p>
                                    <p class="mt-4 text-base text-zinc-500 sm:max-w-md lg:max-w-none dark:text-zinc-400">
                                        From the initial consultation to the final walkthrough, we're personally involved in your {{ $area->city }} project. We believe in building lasting relationships with our clients, not just beautiful spaces.
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

                <!-- Stats section -->
                <div class="mx-auto mt-8 max-w-7xl px-6 sm:mt-12 lg:px-8">
                    <div class="mx-auto max-w-2xl lg:mx-0 lg:max-w-none">
                        <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Our Commitment to {{ $area->city }}</h2>
                        <div class="mt-6 flex flex-col gap-x-8 gap-y-20 lg:flex-row">
                            <div class="lg:w-full lg:max-w-2xl lg:flex-auto">
                                <p class="text-xl/8 text-zinc-700 dark:text-zinc-200">
                                    To transform {{ $area->city }} houses into dream homes while building genuine relationships with every homeowner we serve.
                                </p>
                                <p class="mt-8 max-w-xl text-base/7 text-zinc-600 dark:text-zinc-400">
                                    With deep roots in {{ $area->city }} and the greater Chicagoland area, we understand the unique needs of local homeowners. From historic home renovations to modern kitchen makeovers, we bring the same level of care and craftsmanship to every project.
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
                <livewire:about-section variant="team" />

                <x-cta-section 
                    variant="blue"
                    heading="Ready to Transform Your {{ $area->city }} Home?"
                    description="Let's discuss your project. Schedule a free consultation and see why {{ $area->city }} homeowners trust GS Construction."
                    primaryText="Schedule Free Consultation"
                    :primaryHref="$area->pageUrl('contact')"
                    secondaryText="View Our Work"
                    :secondaryHref="$area->pageUrl('projects')"
                />
            </main>
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
                            ['question' => "How much does kitchen remodeling cost in {$area->city}?", 'answer' => "Kitchen remodeling costs in {$area->city} typically range from \$25,000 to \$75,000+ depending on the scope, materials, and size of your kitchen. A minor refresh with cosmetic updates runs less, while a full gut renovation with custom cabinets and premium countertops costs more. We provide free in-home estimates with a detailed, no-surprise breakdown."],
                            ['question' => "What is a reasonable budget for a kitchen remodel?", 'answer' => "A good rule of thumb is to budget 5–15% of your home's value for a kitchen remodel. For most {$area->city} homes, that translates to \$30,000–\$80,000. We work with a range of budgets and help you prioritize upgrades that deliver the most impact for your investment."],
                            ['question' => "How long does a kitchen remodel take in {$area->city}?", 'answer' => "Most kitchen remodels in {$area->city} take 4–8 weeks from demolition to completion. Simple cosmetic updates can be faster, while projects involving layout changes, custom cabinetry, or structural work may take 10–12 weeks. We provide a detailed timeline before starting and keep you updated throughout."],
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
            @endphp
            
            {{-- Service Schema for rich results --}}
            <x-service-schema :service="$config" :area="$area" />
            
            {{-- Hero with projects slider --}}
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:main-project-hero-slider 
                    :project-type="$config['projectType']"
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
                />
            </div>

            {{-- About Section with service-specific keywords --}}
            <livewire:about-section 
                variant="service" 
                :area="$area" 
                :service-title="$config['label']" 
                :service-short-title="$config['label']" 
            />

            {{-- Long-Form Content Sections (SEO depth) --}}
            @if(!empty($config['contentSections']))
            <section class="bg-white py-16 sm:py-20 dark:bg-zinc-900">
                <div class="mx-auto max-w-7xl px-6 lg:px-8">
                    <div class="mx-auto max-w-3xl">
                        @foreach($config['contentSections'] as $section)
                        <div class="{{ !$loop->first ? 'mt-12' : '' }}">
                            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">
                                {{ $section['heading'] }}
                            </h2>
                            <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-400">
                                {{ $section['body'] }}
                            </p>
                        </div>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            {{-- Timelapse Section --}}
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <livewire:timelapse-section />
            </div>

            {{-- Projects for this service type --}}
            <livewire:projects-grid :area="$area" :type="$config['projectType']" />

            {{-- Testimonials --}}
            <livewire:testimonials-section :area="$area" />

            {{-- Map Section --}}
            <livewire:map-section />

            {{-- Other Services in This City (cross-service internal linking) --}}
            <section class="bg-zinc-50 py-12 dark:bg-zinc-800/50">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-6">
                        More Remodeling Services in {{ $area->city }}
                    </h2>
                    <div class="flex flex-wrap gap-3">
                        @foreach(['kitchen-remodeling' => 'Kitchen Remodeling', 'bathroom-remodeling' => 'Bathroom Remodeling', 'home-remodeling' => 'Home Remodeling'] as $slug => $label)
                            @if($config['urlSlug'] !== $slug)
                            <a href="{{ $area->serviceUrl($slug) }}" wire:navigate class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-zinc-700 shadow-sm hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                {{ $area->city }} {{ $label }}
                            </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Nearby Areas Section for Internal Linking --}}
            @if($nearbyAreas->count() > 0)
            <section class="bg-white py-12 dark:bg-zinc-900">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-2">
                        {{ $config['label'] }} in Nearby Areas
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        We also serve these communities near {{ $area->city }}, IL. Click for {{ strtolower($config['label']) }} info specific to each city.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        @foreach($nearbyAreas as $nearbyArea)
                        <a href="{{ $nearbyArea->serviceUrl($config['urlSlug']) }}" wire:navigate
                           class="inline-flex items-center gap-2 rounded-lg bg-zinc-100 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                           title="{{ $config['label'] }} services in {{ $nearbyArea->city }}, IL">
                            <span>{{ $nearbyArea->city }} {{ $config['label'] }}</span>
                            @if(isset($nearbyArea->distance_miles))
                                <span class="rounded bg-white/70 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-900/70 dark:text-zinc-400">
                                    {{ number_format($nearbyArea->distance_miles, 1) }} mi
                                </span>
                            @endif
                        </a>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            {{-- Contact Section --}}
            <livewire:contact-section :area="$area" />
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
        <livewire:map-section />
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
