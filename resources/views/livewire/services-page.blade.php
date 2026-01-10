<div>
    {{-- Breadcrumb Schema --}}
    <x-breadcrumb-schema :items="[
        ['name' => 'Services'],
    ]" />

    {{-- Visual Breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Services</span>
                </li>
            </ol>
        </nav>
    </div>

    {{-- Hero Section with Image Slider --}}
    <livewire:main-project-hero-slider 
        project-type="mixed"
        :slides="[
            [
                'heading' => 'Kitchen Remodeling Contractors',
                'subheading' => 'Transform your kitchen with custom cabinets, countertops, and complete renovations',
                'type' => 'kitchen',
            ],
            [
                'heading' => 'Bathroom Remodeling Contractors',
                'subheading' => 'Create your personal spa retreat with luxury showers, tubs, and tile work',
                'type' => 'bathroom',
            ],
            [
                'heading' => 'Home Remodeling Contractors',
                'subheading' => 'Complete home renovations, room additions, and open floor plans',
                'type' => 'home-remodel',
            ],
        ]"
        primary-cta-text="Get a Free Quote"
        primary-cta-url="/contact"
        secondary-cta-text="View Our Work"
        secondary-cta-url="/projects"
    />

    {{-- Services Grid --}}
    <section class="py-16 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3 lg:gap-8">
                @foreach ($this->services as $service)
                    <div class="group relative overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-200 transition hover:shadow-xl dark:bg-zinc-800 dark:ring-zinc-700">
                        <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br {{ $service['gradient'] }}">
                            <img 
                                src="{{ $service['image'] }}" 
                                alt="{{ $service['title'] }}"
                                width="800"
                                height="450"
                                loading="lazy"
                                class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                            >
                        </div>
                        <div class="p-6 lg:p-8">
                            <h2 class="text-xl font-bold text-zinc-900 lg:text-2xl dark:text-white">
                                {{ $service['title'] }}
                            </h2>
                            <p class="mt-3 text-sm leading-6 text-zinc-600 lg:mt-4 lg:text-base lg:leading-7 dark:text-zinc-400">
                                {{ $service['description'] }}
                            </p>
                            <ul class="mt-4 space-y-2 text-sm text-zinc-600 lg:mt-6 dark:text-zinc-400">
                                @foreach ($service['features'] as $feature)
                                    <li class="flex items-start gap-2">
                                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                        </svg>
                                        <span>{{ $feature }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="mt-6 lg:mt-8">
                                <a 
                                    href="/services/{{ $service['slug'] }}" 
                                    wire:navigate
                                    class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600 lg:px-6 lg:py-3"
                                >
                                    Learn More
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA Section --}}
    <x-cta-section 
        variant="blue"
        heading="Ready to Start Your Project?"
        description="Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life."
        primaryText="Get Free Quote"
        primaryHref="{{ route('contact') }}"
        secondaryText="View Our Work"
        secondaryHref="{{ route('projects.index') }}"
    />
</div>
