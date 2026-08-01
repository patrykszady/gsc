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
        // with('project'): every gallery tile links to its own image page, so
        // without eager loading the six links fire six extra queries.
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
                        
                        @include('partials.about-gallery')
                    </div>
                </div>
            </div>
        </div>

        @include('partials.about-mission')

        <!-- Greg & Patryk Section -->
        <livewire:about-section variant="team" :area="$area ?? null" />

        @include('partials.about-story')

        @include('partials.about-founder-bios')

        @include('partials.about-pairing')

        @include('partials.about-values')

        @include('partials.about-comparison')

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
