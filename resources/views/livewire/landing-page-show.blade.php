@php
    $p = $page;
    $phone = config('geo-answers.meta.phone', '+1-224-735-4200');
    $phoneHref = 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
    $serviceLabel = $p->service ? \Illuminate\Support\Str::of($p->service)->replace('-', ' ')->title() : 'Remodeling';

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

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-zinc-900 text-white">
        @if ($p->hero_image)
            <img src="{{ $p->hero_image }}" alt="{{ $p->h1 }}" class="absolute inset-0 h-full w-full object-cover opacity-40" />
        @endif
        <div class="relative mx-auto max-w-5xl px-6 py-20 sm:py-28">
            <p class="mb-3 text-sm font-semibold uppercase tracking-wide text-sky-300">
                {{ $serviceLabel }}{{ $p->city ? ' · '.$p->city.', IL' : '' }}
            </p>
            <h1 class="text-3xl font-bold leading-tight sm:text-5xl">{{ $p->h1 }}</h1>
            @if ($p->intro)
                <p class="mt-5 max-w-2xl text-lg text-zinc-200">{{ \Illuminate\Support\Str::of($p->intro)->limit(220) }}</p>
            @endif
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('contact') }}" wire:navigate
                   class="inline-flex items-center rounded-lg bg-sky-500 px-6 py-3 font-semibold text-white transition hover:bg-sky-400">
                    Get a free estimate
                </a>
                <a href="{{ $phoneHref }}"
                   class="inline-flex items-center rounded-lg bg-white/10 px-6 py-3 font-semibold text-white ring-1 ring-white/25 transition hover:bg-white/20">
                    Call {{ $phone }}
                </a>
            </div>
            <p class="mt-6 text-sm text-zinc-300">Family-owned · Licensed, bonded &amp; insured · 5-star rated · 40+ yrs combined experience</p>
        </div>
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
                            <div class="relative aspect-[4/3] overflow-hidden">
                                @if ($project->images->first())
                                    <x-lqip-image :image="$project->images->first()" size="medium" width="600" height="450"
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

    {{-- FAQ --}}
    @if (! empty($p->faq))
        <section class="mx-auto max-w-3xl px-6 py-14">
            <h2 class="mb-6 text-2xl font-bold text-zinc-900 dark:text-white">
                {{ $serviceLabel }}{{ $p->city ? ' in '.$p->city : '' }} — common questions
            </h2>
            <x-faq-section :faqs="$faqForComponent" :collapsed="false" />
        </section>
    @endif

    {{-- CTA --}}
    <x-cta-section
        variant="blue"
        heading="Ready to scope your {{ strtolower($serviceLabel) }}?"
    />
</div>
