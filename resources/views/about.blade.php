<x-layouts.app
    title="About GS Construction"
    metaDescription="Meet Gregory and Patryk, the father-son team behind GS Construction. Over 40 years of combined experience in kitchen, bathroom, and home remodeling in the Chicagoland area."
>
    {{-- greg-patryk.webp used to be preloaded here as the LCP element. The hero
         carousel below now paints above it, so that preload would have raced the
         real LCP for bandwidth at fetchpriority="high" and lost the page time
         rather than saving it. The portrait still loads normally, just not first. --}}

    {{-- Breadcrumb Schema --}}

    <x-breadcrumbs :items="[
        ['name' => 'About'],
    ]" padding="py-4" />

    {{-- Hero image band. No overlay text on purpose: this page already owns a
         single H1 below, and a second one would be an SEO regression. --}}
    <x-hero-carousel :slides="\App\Support\HeroSlides::fromProjects(null, 5)" eager />


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
    
    <div class="isolate">
        <!-- Hero section -->
        <div class="relative isolate -z-10">
            {{-- Gradient blur background (same as testimonials) --}}
            <x-decor-blobs />
            
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
    </div>
</x-layouts.app>
