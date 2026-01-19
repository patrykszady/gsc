<x-layouts.app
    title="Contact GS Construction"
    metaDescription="Get in touch with GS Construction for your home remodeling project. Free consultations for kitchen, bathroom, and whole-home renovations in Chicagoland."
>
    {{-- Breadcrumb Schema --}}
    <x-breadcrumb-schema :items="[
        ['name' => 'Contact'],
    ]" />

    {{-- Visual Breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-1 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Contact</span>
                </li>
            </ol>
        </nav>
    </div>

    <main>
        {{-- Hero Section --}}
        @php
            $homeSlides = [
                [
                    'title' => "Schedule Your Free Consultation",
                    'subheading' => 'We’ll meet at your home soon to learn about your goals and project needs.',
                    'button' => 'Get a Free Quote',
                    'link' => '#contact-form',
                    'projectType' => 'bathroom',
                    'alt' => 'Home remodeling services in Chicagoland',
                ],
                [
                    'title' => "Let's Start Your Project",
                    'subheading' => 'Ready to transform your home? Schedule a free consultation with Greg & Patryk.',
                    'button' => 'Start Your Project',
                    'link' => '#contact-form',
                    'projectType' => 'home-remodel',
                    'alt' => 'Home remodeling services in Chicagoland',
                ],
                [
                    'title' => "Start Your Home Project",
                    'subheading' => 'Get a free consultation and clear next steps from GS Construction.',
                    'button' => 'Request Free Consultation',
                    'link' => '#contact-form',
                    'projectType' => 'kitchen',
                    'alt' => 'Home remodeling services in Chicagoland',
                ],
            ];

            shuffle($homeSlides);
        @endphp
        <livewire:main-project-hero-slider 
            :slides="$homeSlides"
            height-classes="h-[360px] sm:h-[380px] lg:h-[420px]"
            :autoplay-interval="8000"
        />

        {{-- Contact Section --}}
        <div id="contact-form" class="scroll-mt-24">
            <livewire:contact-section />
        </div>

        {{-- Map Section --}}
        <livewire:map-section />

        {{-- Testimonials Section --}}
        <livewire:testimonials-section />

        {{-- Services Section --}}
        @include('partials.services-grid')
    </main>
</x-layouts.app>
