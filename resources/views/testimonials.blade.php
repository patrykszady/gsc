<x-layouts.app
    title="Customer Reviews & Testimonials"
    metaDescription="Read testimonials from our satisfied customers. See what homeowners say about GS Construction's kitchen, bathroom, and home remodeling services in the Chicagoland area."
>
    {{-- Breadcrumb Schema --}}

    <x-breadcrumbs :items="[
        ['name' => 'Reviews'],
    ]" padding="py-4" />

    <section class="mx-auto max-w-7xl px-4 pb-2 sm:px-6 lg:px-8">
        <h1 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
            Customer Reviews & Testimonials
        </h1>
    </section>

    {{-- show-stars: every visible review on this site is 5/5, so the five-star
         graphic states a fact rather than an average it rounds up to. --}}
    <livewire:testimonials-grid :show-stars="true" />

    {{-- Rich citation block: ItemList of recent reviews + per-platform sources --}}
    <x-review-citations />

    {{-- FAQ Section --}}
    @php
        $faqs = [
            ['question' => 'Are your customer reviews real?', 'answer' => 'Yes, all reviews featured on our site are from verified customers. Most come directly from our Google Business Profile and can be independently verified there.'],
            ['question' => 'How many reviews does GS Construction have?', 'answer' => 'We have over 53 five-star reviews on Google from homeowners across the Chicagoland area. Our consistent 5-star rating reflects our commitment to quality and customer satisfaction.'],
            ['question' => 'Can I speak with a past client before hiring you?', 'answer' => 'Absolutely! We are happy to connect you with previous clients who can share their experience working with GS Construction. Just ask during your consultation.'],
            ['question' => 'What areas do your reviewers come from?', 'answer' => 'Our clients come from across Chicagoland including Arlington Heights, Palatine, Mount Prospect, Schaumburg, Buffalo Grove, and 80+ other communities in the Northwest Suburbs and North Shore.'],
        ];
    @endphp
    <x-faq-section :faqs="$faqs" heading="Customer Reviews FAQ" sectionClasses="bg-white pt-2 pb-6 sm:pt-3 sm:pb-8 dark:bg-zinc-900" />

    <livewire:map-section />

    <livewire:testimonials-section :show-header="false" />

    {{-- Highest-intent visitors on the site read reviews — give them the ask. --}}
    <x-cta-section
        variant="blue"
        heading="Join our happy homeowners"
        description="Read enough? Get a free, no-pressure estimate from Greg & Patryk — the same two people every review above talks about."
        primaryText="Request a free estimate"
        primaryHref="/contact"
        secondaryText="Call {{ config('brand.phone') }}"
        secondaryHref="tel:{{ config('brand.phone_href') }}"
    />
</x-layouts.app>
