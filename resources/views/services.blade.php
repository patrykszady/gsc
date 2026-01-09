<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {!! SEO::generate() !!}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white dark:bg-zinc-900">
    <livewire:navbar />

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
            // [
            //     'heading' => 'Basement Finishing & Remodeling',
            //     'subheading' => 'Unlock your basement\'s potential with expert finishing services',
            //     'type' => 'basement',
            // ],
        ]"
        primary-cta-text="Get a Free Quote"
        primary-cta-url="/contact"
        secondary-cta-text="View Our Work"
        secondary-cta-url="/projects"
    />

    {{-- Services Grid --}}
    <section class="py-16 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:grid-cols-2 lg:gap-12">
                {{-- Kitchen Remodeling --}}
                <div class="group relative overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-200 transition hover:shadow-xl dark:bg-zinc-800 dark:ring-zinc-700">
                    <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br from-sky-500 to-blue-600">
                        <img 
                            src="{{ asset('images/services/kitchen-hero.jpg') }}" 
                            alt="Kitchen Remodeling" 
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                            onerror="this.style.display='none'"
                        >
                    </div>
                    <div class="p-8">
                        <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">
                            Kitchen Remodeling
                        </h2>
                        <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                            Transform your kitchen into the heart of your home. From custom cabinetry and premium countertops to complete renovations – we create beautiful, functional spaces where families gather and memories are made.
                        </p>
                        <ul class="mt-6 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Custom cabinetry & storage solutions</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Granite, quartz & marble countertops</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Flooring, lighting & complete renovations</span>
                            </li>
                        </ul>
                        <div class="mt-8">
                            <a 
                                href="{{ route('services.kitchen') }}" 
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600"
                            >
                                Learn More
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Bathroom Remodeling --}}
                <div class="group relative overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-200 transition hover:shadow-xl dark:bg-zinc-800 dark:ring-zinc-700">
                    <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br from-indigo-500 to-purple-600">
                        <img 
                            src="{{ asset('images/services/bathroom-hero.jpg') }}" 
                            alt="Bathroom Remodeling" 
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                            onerror="this.style.display='none'"
                        >
                    </div>
                    <div class="p-8">
                        <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">
                            Bathroom Remodeling
                        </h2>
                        <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                            Create your personal spa retreat with expert bathroom renovations. From luxurious walk-in showers and soaking tubs to modern vanities and tile work – we design bathrooms that combine comfort with style.
                        </p>
                        <ul class="mt-6 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Walk-in showers & luxury tubs</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Custom tile work & vanities</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Modern fixtures & lighting</span>
                            </li>
                        </ul>
                        <div class="mt-8">
                            <a 
                                href="{{ route('services.bathroom') }}" 
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600"
                            >
                                Learn More
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Home Remodeling --}}
                <div class="group relative overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-200 transition hover:shadow-xl dark:bg-zinc-800 dark:ring-zinc-700">
                    <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br from-emerald-500 to-teal-600">
                        <img 
                            src="{{ asset('images/services/home-hero.jpg') }}" 
                            alt="Home Remodeling" 
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                            onerror="this.style.display='none'"
                        >
                    </div>
                    <div class="p-8">
                        <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">
                            Home Remodeling
                        </h2>
                        <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                            Comprehensive home renovations that breathe new life into your entire living space. From room additions and open floor plans to complete home makeovers – we handle projects of any scale with precision.
                        </p>
                        <ul class="mt-6 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Room additions & expansions</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Open concept floor plans</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Complete home renovations</span>
                            </li>
                        </ul>
                        <div class="mt-8">
                            <a 
                                href="{{ route('services.home') }}" 
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600"
                            >
                                Learn More
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Basement Remodeling --}}
                <div class="group relative overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-200 transition hover:shadow-xl dark:bg-zinc-800 dark:ring-zinc-700">
                    <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br from-amber-500 to-orange-600">
                        <img 
                            src="{{ asset('images/services/basement-hero.jpg') }}" 
                            alt="Basement Remodeling" 
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                            onerror="this.style.display='none'"
                        >
                    </div>
                    <div class="p-8">
                        <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">
                            Basement Remodeling
                        </h2>
                        <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                            Unlock your basement's potential with expert finishing and renovation services. Whether you envision a home theater, guest suite, or recreation room – we transform unused space into valuable living areas.
                        </p>
                        <ul class="mt-6 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Complete basement finishing</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Home theaters & rec rooms</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-sky-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Guest suites & wet bars</span>
                            </li>
                        </ul>
                        <div class="mt-8">
                            <a 
                                href="{{ route('services.basement') }}" 
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600"
                            >
                                Learn More
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
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

    <x-footer />
</body>
</html>
