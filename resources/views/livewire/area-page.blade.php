<div>
    {{-- Breadcrumb Schema for all area pages --}}
    @php
        $breadcrumbItems = [
            ['name' => 'Areas Served', 'url' => route('areas.index')],
            ['name' => $area->city, 'url' => $area->url],
        ];
        
        if ($page !== 'home') {
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

    {{-- Visual Breadcrumb Navigation --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol role="list" class="flex items-center space-x-2 text-sm">
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
                @if($page !== 'home')
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
            
            <livewire:main-project-hero-slider 
                :slides="$homeSlides"
                :area="$area"
                heading="{{ $area->city }} Kitchen & Bathroom Remodeling"
                subheading="Professional remodeling services for {{ $area->city }} homeowners"
                secondary-cta-text="Schedule Free Consult"
                :secondary-cta-url="$area->pageUrl('contact')"
            />

            <livewire:about-section :area="$area" />

            <livewire:timelapse-section />

            <livewire:testimonials-section :area="$area" />
            
            <livewire:map-section />

            <livewire:contact-section :area="$area" />
            @break

        @case('contact')
            {{-- Area Contact Page --}}
            <x-cta-section 
                heading="Let's Start Your {{ $area->city }} Project"
                description="Ready to transform your {{ $area->city }} home? Schedule a free consultation with Greg & Patryk."
                primaryText="About GS Construction"
                :primaryHref="$area->pageUrl('about')"
                secondaryText="View Our Work"
                :secondaryHref="$area->pageUrl('projects')"
            />

            <livewire:contact-section :area="$area" />

            <livewire:map-section />

            <livewire:testimonials-section :area="$area" />
            @break

        @case('testimonials')
            {{-- Area Testimonials Page --}}
            <livewire:testimonials-grid :area="$area" />

            <livewire:map-section />

            <livewire:testimonials-section :area="$area" :show-header="false" />
            @break

        @case('projects')
            {{-- Area Projects Page --}}
            <livewire:timelapse-section />

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
            <livewire:main-project-hero-slider 
                project-type="mixed"
                :slides="$serviceSlides"
                primary-cta-text="Get a Free Quote"
                :primary-cta-url="$area->pageUrl('contact')"
                secondary-cta-text="View Our Work"
                :secondary-cta-url="$area->pageUrl('projects')"
            />

            {{-- Services Grid --}}
            @include('partials.services-grid', ['area' => $area])
            @break

        @default
            {{-- Fallback to home --}}
            <livewire:about-section />
    @endswitch

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
                <a href="{{ $area->pageUrl('services') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $page === 'services' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Services
                </a>
                <a href="{{ $area->pageUrl('projects') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $page === 'projects' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Projects
                </a>
                <a href="{{ $area->pageUrl('testimonials') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium {{ $page === 'testimonials' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-700 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                    Testimonials
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
