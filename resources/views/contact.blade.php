<x-layouts.app
    :title="'Contact ' . config('brand.name')"
    :metaDescription="'Get in touch with ' . config('brand.name') . ' for your home remodeling project. Free consultations for kitchen, bathroom, and whole-home renovations in Chicagoland.'"
>
    {{-- Breadcrumb Schema --}}

    <x-breadcrumbs :items="[
        ['name' => 'Contact'],
    ]" padding="py-1" />

    <div>
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
                    'subheading' => 'Get a free consultation and clear next steps from ' . config('brand.name') . '.',
                    'button' => 'Request Free Consultation',
                    'link' => '#contact-form',
                    'projectType' => 'kitchen',
                    'alt' => 'Home remodeling services in Chicagoland',
                ],
            ];

            shuffle($homeSlides);
        @endphp
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <livewire:main-project-hero-slider 
                :slides="$homeSlides"
                {{-- Same clipping as the area service hero, just milder: 360px
                     left the heading 4px short at 360px wide. --}}
                height-classes="h-[400px] sm:h-[380px] lg:h-[420px]"
                :autoplay-interval="8000"
            />
        </div>

        {{-- Contact Section --}}
        <div id="contact-form" class="scroll-mt-24">
            <livewire:contact-section />
        </div>

        {{-- Map Section --}}
        <livewire:map-section />

        {{-- Testimonials Section --}}
        <livewire:testimonials-section />

        {{-- FAQ Section --}}
        <x-faq-section 
            :faqs="collect(config('faq.faqs', []))->slice(0, 8)->toArray()" 
            :heading="'Common Questions About ' . config('brand.name')"
            collapsed
        />

        {{-- Services Section --}}
        {{-- showCta false: this page already has the contact form above and a
             CTA of its own below; the partial's own one pointed back here. --}}
        @include('partials.services-grid', ['showCta' => false])

        {{-- Closing CTA. This page had none — it ended on the services grid,
             so the only route onward from a contact page was back up to the
             nav. "Meet the team" is the honest secondary here: someone on
             /contact has already found the form, so pushing them at it again
             adds nothing, whereas who they'd be hiring is the open question. --}}
        <x-cta-section
            variant="blue"
            heading="Want to know who you're hiring?"
            :description="config('brand.name') . ' is a father-and-son company — Gregory and Patryk are on your job personally. Read how the company started and how we work.'"
            primaryText="About Us"
            primaryHref="/about"
            secondaryText="View Our Work"
            secondaryHref="/projects"
        />
    </div>
</x-layouts.app>
