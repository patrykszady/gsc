<x-layouts.app
    title="Remodeling FAQ — Chicago Suburbs | GS Construction"
    metaDescription="Straight answers on kitchen, bathroom, basement & whole-home remodeling in the Chicago suburbs — pricing, permits, timelines, and how GS Construction works."
>

    <x-breadcrumbs :items="[['label' => 'FAQ']]" maxWidth="max-w-3xl" padding="pt-8 pb-0" />

    {{-- Hero image band. No overlay text on purpose: this page already owns a
         single H1 below, and a second one would be an SEO regression. --}}
    <x-hero-carousel :slides="\App\Support\HeroSlides::fromProjects(null, 5)" eager />

    {{-- Curated Q&A doubles as GEO content: the same answers served to AI engines
         at /geo/answers.json render here as a visible, schema-marked FAQ that
         Google, AI Overviews, ChatGPT and Perplexity can cite directly. --}}
    @php
        $answers = collect(config('geo-answers.answers', []))
            ->filter(fn ($a) => filled($a['q'] ?? null) && filled($a['a'] ?? null))
            ->map(fn ($a) => ['question' => $a['q'], 'answer' => $a['a'], 'link' => $a['link'] ?? null])
            ->values()
            ->all();
    @endphp

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Remodeling FAQ</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            Questions homeowners ask before hiring a remodeler
        </h1>
        <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            Straight answers on pricing, permits, timelines and how we work across Chicago's
            suburbs. Don't see yours?
            <a href="{{ url('/contact') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">Ask us directly</a>.
        </p>
    </div>

    <x-faq-section
        :faqs="$answers"
        heading="Remodeling questions & answers"
        :collapsed="false"
        contentMaxWidth="max-w-3xl"
    />

    {{-- Soft CTA — <x-mid-cta>, the component documented as owning every
         mid-page "soft ask", instead of a third hand-rolled variant of it. --}}
    <div class="mx-auto max-w-3xl px-4 pb-14 sm:px-6 lg:px-8">
        <x-mid-cta heading="Ready to scope your project?" />
    </div>
</x-layouts.app>
