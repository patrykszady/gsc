@php
    $p = $page;
    $phone = config('geo-answers.meta.phone', '+1-224-735-4200');
    $phoneHref = 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
    $serviceLabel = $p->service ? \Illuminate\Support\Str::of($p->service)->replace('-', ' ')->title() : 'Remodeling';
    // Same slides the homepage hero uses, filtered to this page's service.
    $projectType = \App\Services\Seo\LandingPageContentGenerator::SERVICE_PROJECT_TYPE[$p->service] ?? null;

    // Service structured data (proof-gated pages only, matching robots). The
    // FAQ schema is emitted by <x-faq-section> below, so it isn't repeated here.
    $serviceSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Service',
        'name' => $p->h1,
        'serviceType' => $serviceLabel,
        'provider' => ['@id' => 'https://gs.construction/#business'],
        'areaServed' => $p->city ? ['@type' => 'City', 'name' => $p->city, 'addressRegion' => 'IL'] : ['@type' => 'State', 'name' => 'Illinois'],
        'url' => $p->url(),
        'description' => $p->meta_description,
    ];

    // Map the stored {q,a} FAQ shape to the {question,answer} shape the
    // shared FAQ component expects.
    $faqForComponent = collect($p->faq ?? [])
        ->map(fn ($f) => ['question' => $f['q'] ?? '', 'answer' => $f['a'] ?? ''])
        ->filter(fn ($f) => $f['question'] !== '')
        ->values()
        ->all();
@endphp

<div>
    @if ($p->shouldIndex())
        <script type="application/ld+json">{!! json_encode($serviceSchema, JSON_UNESCAPED_SLASHES) !!}</script>
    @endif

    {{-- Hero: the same slider the homepage leads with, filtered to this
         page's service. Filtered mode maps images onto caller-provided slide
         stubs (see service-page.blade.php) — without them it renders nothing,
         which is exactly the empty hero this replaced. --}}
    @php
        $heroSlides = array_fill(0, 4, [
            'heading' => $p->h1,
            'subheading' => 'Family-owned · Licensed & insured · 5-star rated · Free estimates',
            'type' => $projectType,
        ]);
    @endphp
    <section class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
        <livewire:main-project-hero-slider
            :project-type="$projectType"
            :slides="$heroSlides"
            :slide-count="4"
            :heading="$p->h1"
            primary-cta-text="Get a free estimate"
            :primary-cta-url="route('contact')"
            :secondary-cta-text="'Call ' . $phone"
            :secondary-cta-url="$phoneHref"
        />
    </section>

    {{-- Intro prose --}}
    @if ($p->intro)
        <section class="mx-auto max-w-3xl px-6 py-12">
            <p class="text-lg leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $p->intro }}</p>
        </section>
    @endif

    {{-- Unique content sections --}}
    @if (! empty($p->sections))
        <section class="mx-auto max-w-3xl space-y-10 px-6 pb-12">
            @foreach ($p->sections as $section)
                <div>
                    @if (! empty($section['heading']))
                        <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $section['heading'] }}</h2>
                    @endif
                    @if (! empty($section['body']))
                        <div class="mt-3 space-y-4 leading-relaxed text-zinc-700 dark:text-zinc-300">
                            @foreach (preg_split('/\n\n+/', trim($section['body'])) as $para)
                                <p>{{ $para }}</p>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    {{-- Proof: real completed projects (the unique, non-thin content) --}}
    @if ($projects->isNotEmpty())
        <section class="bg-zinc-50 py-14 dark:bg-zinc-900/40">
            <div class="mx-auto max-w-6xl px-6">
                <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">
                    Our {{ strtolower($serviceLabel) }} work{{ $p->city ? ' near '.$p->city : '' }}
                </h2>
                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($projects as $project)
                        <a href="{{ route('projects.show', $project) }}" wire:navigate
                           class="group relative flex flex-col overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                            <div class="relative aspect-4/3 overflow-hidden">
                                @if ($project->cover())
                                    <x-lqip-image :image="$project->cover()" size="medium" width="600" height="450"
                                        class="h-full w-full transition duration-300 group-hover:scale-105" />
                                @endif
                                @if ($project->project_type)
                                    <div class="absolute top-3 right-3">
                                        <span class="inline-flex items-center rounded-full bg-white/90 px-2.5 py-1 text-xs font-medium text-zinc-700 backdrop-blur dark:bg-zinc-900/90 dark:text-zinc-300">
                                            {{ ucfirst($project->project_type) }}
                                        </span>
                                    </div>
                                @endif
                            </div>
                            <div class="p-4">
                                <h3 class="font-semibold text-zinc-900 group-hover:text-sky-600 dark:text-white">{{ $project->title }}</h3>
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

    {{-- The homepage's trust stack: who we are, then rotating reviews.
         Lazy like on the homepage — all below the fold. --}}
    <livewire:about-section lazy />
    <livewire:testimonials-section lazy />

    {{-- FAQ — the shared component owns its heading, width and section
         chrome; wrapping it in another narrower section double-headed and
         double-boxed it. --}}
    @if (! empty($p->faq))
        <x-faq-section
            :faqs="$faqForComponent"
            :heading="$serviceLabel . ($p->city ? ' in ' . $p->city : '') . ' — Common Questions'"
            :collapsed="false"
        />
    @endif

    {{-- Service area map + the actual contact form — the homepage's
         conversion pair, so the searcher never has to leave to convert. --}}
    <livewire:map-section lazy />
    <livewire:contact-section lazy />

    {{-- CTA --}}
    <x-cta-section
        variant="blue"
        heading="Ready to scope your {{ strtolower($serviceLabel) }}?"
    />

    {{-- Town links, same block as the homepage — internal linking a brand-new
         URL needs so it isn't an orphan. --}}
    <div class="mx-auto max-w-7xl px-6 pb-12 lg:px-8">
        <x-area-chips :limit="18" class="mt-4" />
    </div>
</div>
