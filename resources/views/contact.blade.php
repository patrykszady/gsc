<x-layouts.app
    :title="isset($area) ? 'Contact Us | Home Remodeling in ' . $area->city . ' | GS Construction' : 'Contact Us | GS Construction | Family-Owned Home Remodeling'"
    :metaDescription="isset($area) ? 'Get in touch with GS Construction for your ' . $area->city . ' home remodeling project. Free consultations for kitchen, bathroom, and whole-home renovations.' : 'Get in touch with GS Construction for your home remodeling project. Free consultations for kitchen, bathroom, and whole-home renovations in Chicagoland.'"
>
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

    <div class="relative isolate bg-white dark:bg-zinc-900">
        {{-- Gradient blur background --}}
        <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-30 blur-3xl">
            <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
        </div>
        <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 flex transform-gpu overflow-hidden pt-32 opacity-25 blur-3xl sm:pt-40 xl:justify-end">
            <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[-22rem] aspect-[1313/771] w-[82.0625rem] flex-none origin-top-right rotate-[30deg] bg-linear-to-tr from-sky-300 to-sky-600 xl:mr-[calc(50%-12rem)] xl:ml-0"></div>
        </div>

        {{-- Hero with project images --}}
        <div class="mx-auto max-w-7xl px-6 pt-10 pb-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Get In Touch</p>
                <h1 class="mt-2 font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                    {{ isset($area) ? 'Let\'s Start Your Project in ' . $area->city : 'Let\'s Start Your Project' }}
                </h1>
                <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-300">
                    {{ isset($area) ? 'Ready to transform your ' . $area->city . ' home? Schedule a free consultation with Greg & Patryk.' : 'Ready to transform your home? Schedule a free consultation with Greg & Patryk.' }}
                </p>
                <div class="mt-6">
                    <flux:button href="/about" variant="primary" class="font-semibold uppercase tracking-wide">
                        About GS Construction
                    </flux:button>
                </div>
            </div>

            {{-- Project images strip --}}
            <div class="mt-10 flex justify-center gap-4 overflow-hidden">
                @foreach($galleryImages->take(6) as $image)
                <div class="relative w-28 sm:w-36 flex-none">
                    <img src="{{ $image->getThumbnailUrl('medium') }}" alt="{{ $image->alt_text ?? 'GS Construction project' }}" class="aspect-square w-full rounded-xl bg-zinc-900/5 object-cover shadow-lg dark:bg-zinc-700/5" loading="lazy" />
                    <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Contact Section --}}
        <livewire:contact-section :area="$area ?? null" />

        {{-- Map Section --}}
        <livewire:map-section :area="$area ?? null" />

        {{-- Testimonials Section --}}
        <livewire:testimonials-section :area="$area ?? null" />
    </div>
</x-layouts.app>
