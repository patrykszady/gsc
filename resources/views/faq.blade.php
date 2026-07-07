<x-layouts.app
    title="Remodeling FAQ — Chicago Suburbs | GS Construction"
    metaDescription="Straight answers on kitchen, bathroom, basement & whole-home remodeling in the Chicago suburbs — pricing, permits, timelines, and how GS Construction works."
>
    {{-- Curated Q&A doubles as GEO content: the same answers served to AI engines
         at /geo/answers.json render here as a visible, schema-marked FAQ that
         Google, AI Overviews, ChatGPT and Perplexity can cite directly. --}}
    @php
        $answers = collect(config('geo-answers.answers', []))
            ->filter(fn ($a) => filled($a['q'] ?? null) && filled($a['a'] ?? null))
            ->map(fn ($a) => ['question' => $a['q'], 'answer' => $a['a']])
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
        heading="Remodeling questions &amp; answers"
        :collapsed="false"
        contentMaxWidth="max-w-3xl"
    />

    {{-- Soft CTA to a free estimate --}}
    <div class="mx-auto max-w-3xl px-4 pb-14 sm:px-6 lg:px-8">
        <div class="rounded-2xl bg-sky-50 px-6 py-6 text-center dark:bg-sky-900/20">
            <p class="text-base font-semibold text-zinc-900 dark:text-white">Ready to scope your project?</p>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Free, no-pressure estimate from Greg &amp; Patryk.</p>
            <a href="{{ url('/contact') }}" wire:navigate
               class="mt-4 inline-flex items-center gap-2 rounded-lg bg-sky-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-800">
                Get a free estimate
            </a>
        </div>
    </div>
</x-layouts.app>
