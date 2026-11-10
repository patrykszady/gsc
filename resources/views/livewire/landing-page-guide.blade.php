{{--
    A guide page (/remodeling/{slug}, LandingPage template "guide"): the
    long-form answer to one search that is not a town search, written by
    content:build-guides from the site's own facts. Built from the same
    pieces as the cost and permit guides (narrow article column, hero band,
    FAQ with FAQPage schema, blue CTA) plus the town template's proof cards
    and town chips, so a brand-new URL is never an orphan. The town template
    keeps livewire/landing-page-show.blade.php untouched.
--}}
@php
    $p = $page;
    $serviceLabel = $p->serviceLabel();
    $projectType = $p->proofProjectType();
    $costGuide = $p->costGuide();
    $sections = $p->sectionBlocks();
    $introParagraphs = preg_split('/\n\s*\n+/', trim((string) $p->intro)) ?: [];

    // The hero shows this page's own proof — the covers of the projects it
    // draws on — and falls back to the service's curated covers.
    $heroSlides = \App\Support\HeroSlides::fromImages($projects->map(fn ($project) => $project->cover())->filter());
    if ($heroSlides === []) {
        $heroSlides = \App\Support\HeroSlides::fromProjects($projectType, 5);
    }

    // Map the stored {q,a} FAQ shape to the {question,answer} shape the
    // shared FAQ component expects; it emits the FAQPage JSON-LD itself.
    $faqForComponent = collect($p->faq ?? [])
        ->map(fn ($f) => ['question' => $f['q'] ?? '', 'answer' => $f['a'] ?? ''])
        ->filter(fn ($f) => $f['question'] !== '' && $f['answer'] !== '')
        ->values()
        ->all();
    $faqHeading = $p->target_query ? 'Common questions: ' . $p->target_query : $serviceLabel . ' — common questions';

    // Article structured data (proof-gated pages only, matching robots).
    $articleSchema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $p->h1,
        'description' => $p->meta_description,
        'image' => $p->hero_image,
        'inLanguage' => 'en-US',
        'author' => ['@id' => 'https://gs.construction/#business'],
        'publisher' => ['@id' => 'https://gs.construction/#business'],
        'isPartOf' => ['@id' => 'https://gs.construction/#website'],
        'mainEntityOfPage' => $p->url(),
        'about' => array_filter([
            '@type' => 'Service',
            'name' => $serviceLabel,
            'provider' => ['@id' => 'https://gs.construction/#business'],
            'url' => $p->servicePageUrl(),
        ]),
        'datePublished' => $p->published_at?->toIso8601String(),
        'dateModified' => $p->updated_at?->toIso8601String(),
    ], fn ($value) => $value !== null && $value !== '');
@endphp

<div data-landing-page-guide>
    @if ($p->shouldIndex())
        <script type="application/ld+json">{!! json_encode($articleSchema, JSON_UNESCAPED_SLASHES) !!}</script>
    @endif

    {{-- One trail, one BreadcrumbList: Services → the service → this guide. --}}
    <x-breadcrumbs :items="array_values(array_filter([
        ['label' => 'Services', 'url' => route('services.index')],
        $p->servicePageUrl() ? ['label' => $serviceLabel, 'url' => $p->servicePageUrl()] : null,
        ['label' => $p->h1 ?: $p->title],
    ]))" maxWidth="max-w-3xl" padding="pt-8 pb-0" />

    {{-- Hero image band. No overlay text on purpose: the page owns a single
         H1 below, exactly like /costs and /faq. --}}
    <x-hero-carousel :slides="$heroSlides" eager />

    <article class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">{{ $serviceLabel }} guide · {{ now()->year }}</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            {{ $p->h1 }}
        </h1>

        {{-- Direct answer first — the paragraph AI answers and voice search quote. --}}
        @foreach ($introParagraphs as $paragraph)
            <p class="{{ $loop->first ? 'speakable ' : '' }}mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ trim($paragraph) }}</p>
        @endforeach

        @foreach ($sections as $section)
            @if ($section['heading'] !== '')
                <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">{{ $section['heading'] }}</h2>
            @endif
            @foreach ($section['blocks'] as $block)
                @if ($block['type'] === 'ul')
                    <ul class="mt-4 list-disc space-y-2 pl-5 text-base leading-7 text-zinc-600 dark:text-zinc-300">
                        @foreach ($block['items'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $block['text'] }}</p>
                @endif
            @endforeach
        @endforeach

        {{-- Where to go next: the service page, its published cost guide,
             the village permit guides and the towns. --}}
        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5" data-guide-links>
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">Keep reading</h2>
            <ul class="mt-3 space-y-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                @if ($p->servicePageUrl())
                    <li><a href="{{ $p->servicePageUrl() }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $serviceLabel }}</a> — what the service includes, what is in the price, and how we run the job.</li>
                @endif
                @if ($costGuide)
                    <li><a href="{{ route('costs.show', ['slug' => $costGuide['slug']]) }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $costGuide['name'] }}</a> — the published price tiers and what moves the number.</li>
                @endif
                <li><a href="{{ route('permits.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">Building permit guides</a> — what each village requires and how long review takes.</li>
                <li><a href="{{ url('/areas-served') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">Areas served</a> — the towns we work in, with local projects and permit notes.</li>
            </ul>
        </div>
    </article>

    {{-- Proof: the real completed projects this guide draws on. --}}
    @if ($projects->isNotEmpty())
        <section class="mt-14 bg-zinc-50 py-14 dark:bg-zinc-900/40" data-guide-proof>
            <div class="mx-auto max-w-6xl px-6">
                <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">Related {{ strtolower($serviceLabel) }} projects</h2>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Completed GS Construction work this guide draws on.</p>
                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($projects as $project)
                        {{-- A project that reaches this service only through one
                             of its areas presents as that area — see
                             Project::presentFor(). --}}
                        @php $presentation = $project->presentFor($projectType); @endphp
                        <a href="{{ $presentation->url() }}" wire:navigate
                           class="group relative flex flex-col overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                            <div class="relative aspect-4/3 overflow-hidden">
                                @if ($presentation->cover())
                                    <x-lqip-image :image="$presentation->cover()" size="medium" width="600" height="450"
                                        class="h-full w-full transition duration-300 group-hover:scale-105" />
                                @endif
                                @if ($presentation->typeLabel())
                                    <div class="absolute top-3 right-3">
                                        <span class="inline-flex items-center rounded-full bg-white/90 px-2.5 py-1 text-xs font-medium text-zinc-700 backdrop-blur dark:bg-zinc-900/90 dark:text-zinc-300">
                                            {{ $presentation->typeLabel() }}
                                        </span>
                                    </div>
                                @endif
                            </div>
                            <div class="p-4">
                                <h3 class="font-semibold text-zinc-900 group-hover:text-sky-600 dark:text-white">{{ $presentation->title() }}</h3>
                                @if ($project->location)
                                    <p class="mt-1 text-sm text-zinc-500">{{ $project->location }}</p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- FAQ — the shared component owns its heading, chrome and the FAQPage schema. --}}
    @if ($faqForComponent !== [])
        <x-faq-section :faqs="$faqForComponent" :heading="$faqHeading" :collapsed="false" />
    @endif

    {{-- The standard contact form, so the reader never has to leave to convert. --}}
    <livewire:contact-section lazy />

    <x-cta-section
        variant="blue"
        heading="Want the answer for your own house?"
        description="Free in-home estimate with an itemized scope — real figures for your actual space, not a range."
    />

    {{-- Town links, same block as the homepage and the town template. --}}
    <div class="mx-auto max-w-7xl px-6 pb-12 lg:px-8">
        <x-area-chips :limit="18" class="mt-4" />
    </div>
</div>
