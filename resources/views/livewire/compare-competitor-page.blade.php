@php
    // ?bg=grid|floorplan|mesh|aurora previews the other decor variants locally.
    $decor = app()->environment('local') ? request()->query('bg', 'grid') : 'grid';
@endphp
<div class="relative isolate overflow-x-clip bg-white pb-20 dark:bg-gray-950 lg:pb-0">
    <x-page-decor :variant="$decor" />

    <x-breadcrumbs :items="[
        ['label' => 'Compare', 'url' => route('compare.index')],
        ['label' => 'GS Construction vs ' . ($competitor['name'] ?? '')],
    ]" />

    <div class="mx-auto max-w-3xl px-6 pt-2 text-center lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Alternative to {{ $competitor['name'] }}</p>
    </div>

    <x-page-hero
        title="GS Construction vs {{ $competitor['name'] }}"
        :key-suffix="'compare-' . $competitor['slug']"
    />

    {{-- Trust signals --}}
    <div class="mx-auto mt-6 max-w-5xl px-6 lg:px-8">
        <p class="mb-4 text-center text-sm font-bold uppercase tracking-widest text-sky-600 dark:text-sky-400">
            GS Construction &amp; Remodeling
        </p>
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Family-owned</dt>
                <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">Father &amp; Son</dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Combined experience</dt>
                <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">40+ Years</dd>
            </div>
            {{-- Whole card links to the reviews page. The anchor lives inside the
                 <dd> and stretches over the card, so the dl/dt/dd structure stays
                 valid for screen readers while the full tile stays clickable. --}}
            <div class="group relative rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Verified reviews</dt>
                <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 transition group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-400">
                    {{ $reviewCount }}+
                    <a href="{{ route('reviews.index') }}" wire:navigate
                       class="absolute inset-0 rounded-xl focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-2"
                       aria-label="Read all {{ $reviewCount }}+ verified reviews"></a>
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Licensed &amp; insured</dt>
                <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">Yes</dd>
            </div>
        </dl>
    </div>

    <main class="mx-auto max-w-5xl px-6 pb-6 lg:px-8">
        <header class="mx-auto mt-10 max-w-3xl text-center">
            <p class="text-lg text-zinc-600 dark:text-zinc-300">
                Considering {{ $competitor['name'] }} for your kitchen, bathroom, or whole-home remodel?
                Here is a factual side-by-side so you can compare options before requesting estimates.
            </p>

            {{-- Shared CTA button component: same look, sizing, and click
                 tracking as every other primary CTA on the site. --}}
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <x-buttons.cta href="/contact" variant="primary" size="lg">
                    Get a free estimate
                </x-buttons.cta>
                <a href="tel:2247354200" class="inline-flex items-center justify-center rounded-lg px-6 py-3 text-base font-semibold uppercase tracking-wide text-sky-700 ring-1 ring-sky-300 transition hover:bg-sky-50 dark:text-sky-300 dark:ring-sky-700 dark:hover:bg-sky-950/40">
                    Call (224) 735-4200
                </a>
            </div>
        </header>

        <section class="mt-12 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
            <div class="overflow-x-auto">
                <table class="w-full min-w-160 text-left text-sm">
                    <thead class="bg-zinc-50 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Criteria</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-sky-700 dark:text-sky-400">GS Construction &amp; Remodeling</th>
                            <th scope="col" class="px-4 py-3 font-semibold">{{ $competitor['name'] }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                        @foreach($criteria as $row)
                            <tr>
                                <th scope="row" class="px-4 py-3 align-top font-medium text-zinc-700 dark:text-zinc-200">
                                    {{ $row['label'] }}
                                    @if(!empty($row['why']))
                                        <span class="mt-1 block text-xs font-normal text-zinc-400 dark:text-zinc-500">{{ $row['why'] }}</span>
                                    @endif
                                </th>
                                <td class="px-4 py-3 align-top text-zinc-700 dark:text-zinc-300">
                                    @if(($row['key'] ?? '') === 'public_reviews')
                                        {{-- Platforms link out to the real profiles, driven by the
                                             `review` flag in config/socials.php (same source as the footer). --}}
                                        @php $reviewProfiles = array_values(array_filter(config('socials'), fn ($s) => $s['review'] ?? false)); @endphp
                                        Verified reviews on
                                        @foreach($reviewProfiles as $profile)
                                            <a href="{{ $profile['url'] }}" target="_blank" rel="noopener noreferrer external"
                                               class="font-medium text-sky-700 underline hover:text-sky-600 dark:text-sky-400">{{ $profile['label'] }}</a>@if(! $loop->last){{ $loop->remaining === 1 ? ', and' : ',' }}@else.@endif
                                        @endforeach
                                    @else
                                        {{ $row['us'] }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top text-zinc-600 dark:text-zinc-400">{{ $row['them'] }}@if(! empty($row['them_source']))<x-source-note :source="$row['them_source']" />@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @php
            // Full column width (no max-w-2xl) so the notice fits two lines.
            $showSource = $competitor['slug'] !== 'kitchen-village' && ! empty($competitor['website']);
            $sourceHost = $showSource ? preg_replace('#^www\.#', '', parse_url($competitor['website'], PHP_URL_HOST)) : null;
            $verifiedAt = ! empty($lastVerified) ? \Illuminate\Support\Carbon::parse($lastVerified)->format('F Y') : null;
        @endphp
        <p class="mt-4 text-center text-xs text-zinc-500 dark:text-zinc-400">
            Informational comparison of publicly available information about {{ $competitor['name'] }}. Trademarks belong to their respective owners — verify details directly with each company.
            @if($verifiedAt || $showSource)
                <span class="mt-1 block">
                    @if($verifiedAt)Verified {{ $verifiedAt }}@endif
                    @if($verifiedAt && $showSource) · @endif
                    @if($showSource)Source: <a href="{{ $competitor['website'] }}" target="_blank" rel="noopener nofollow" class="underline hover:text-sky-600 dark:hover:text-sky-400">{{ $sourceHost }}</a>
                        · <a href="https://web.archive.org/web/*/{{ $sourceHost }}" target="_blank" rel="noopener nofollow" class="underline hover:text-sky-600 dark:hover:text-sky-400">archived copies</a>@endif
                </span>
            @endif
        </p>

        @if(!empty($competitor['comparison_note']))
        <section class="mt-12">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">
                How GS Construction compares to {{ $competitor['name'] }}
            </h2>
            <p class="mt-4 text-zinc-700 dark:text-zinc-300">
                {{ $competitor['comparison_note'] }}
            </p>
        </section>
        @endif

        <x-mid-cta />

        <section class="mt-12">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">The GS Construction difference</h2>
            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                <div class="group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <h3 class="font-heading text-lg font-semibold text-balance text-sky-700 dark:text-sky-400">Your design, your decisions</h3>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        Bring your own designer or architect, use one of our trusted design partners, or be your
                        own designer with our material sources &mdash; never funneled into one in-house look.
                    </p>
                </div>
                <div class="group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <h3 class="font-heading text-lg font-semibold text-balance text-sky-700 dark:text-sky-400">Transparent pricing, no labor upcharge</h3>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        Many firms hire out the trades and mark up the labor on top. We give you an itemized scope and
                        clear pricing, so you know exactly what you are paying for and who is doing the work.
                    </p>
                </div>
                <div class="group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <h3 class="font-heading text-lg font-semibold text-balance text-sky-700 dark:text-sky-400">One team, start to finish</h3>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        Greg and Patryk Szady are your first <em>and</em> last point of contact. With bigger firms a new
                        coordinator owns each phase — and every hand-off is a chance for confusion and costly mistakes.
                    </p>
                </div>
                <div class="group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <h3 class="font-heading text-lg font-semibold text-balance text-sky-700 dark:text-sky-400">Live updates, not phone tag</h3>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        Your private Daily portal keeps your schedule, change orders, and balances current as the project
                        moves. Check it whenever you want, instead of calling to ask and waiting for someone to look it up.
                    </p>
                </div>
            </div>
        </section>
    </main>

    {{-- Customer reviews — same column width as the page (the component brings
         its own max-width + padding, so pass the width instead of wrapping). --}}
    <livewire:testimonials-section
        :show-header="true"
        max-width-class="max-w-5xl"
        section-classes="relative isolate overflow-hidden mt-2 pb-6"
        :key="'compare-reviews-'.$competitor['slug']"
    />

    <main class="mx-auto max-w-5xl px-6 pb-16 lg:px-8">
        <section class="mt-12 grid gap-8 md:grid-cols-2">
            <div>
                {{-- Block span keeps the brand on its own line at every width while
                     the heading text stays one readable string for search and AT. --}}
                <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">Why homeowners choose <span class="block">GS Construction</span></h2>
                <x-marker-list marker="check" :items="[
                    'Family-owned father-son team; you talk directly to the owners.',
                    $reviewCount . '+ verified reviews across Google, Houzz, Yelp, and Angi.',
                    'Bring your own designer or architect, use one of our trusted partners, or be your own designer with our material sources.',
                    'Itemized estimate and transparent pricing before work begins.',
                    'Permit pulling and inspection coordination handled for you.',
                    'Daily client portal to track your schedule, change orders, and balances in real time.',
                    'Hundreds of in-progress and completed project photos on this site.',
                ]" />
            </div>
            <div>
                <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">How to decide</h2>
                @php
                    // Built here, not inline: the last item contains an <a> whose
                    // double quotes would terminate the Blade :items attribute.
                    $agHref = 'https://illinoisattorneygeneral.gov/Consumer-Protection/Home-Repair/';
                    $agLinkClasses = 'font-medium text-sky-700 underline hover:text-sky-600 dark:text-sky-400';
                    $decideSteps = [
                        'Request itemized estimates from at least two contractors.',
                        'Ask whether you can bring your own designer or architect.',
                        'Confirm who self-performs the work vs. who is subcontracted and marked up.',
                        'Confirm who pulls permits and how inspections are scheduled.',
                        'Ask who your single point of contact is — and whether it changes each phase.',
                        'Ask whether you get a live portal for your schedule, change orders, and balances.',
                        'Review the Illinois Attorney General\'s <a href="' . $agHref . '" target="_blank" rel="noopener nofollow" class="' . $agLinkClasses . '">home-repair guidance</a> so you know you are protected.',
                    ];
                @endphp
                <x-marker-list marker="circle" tag="ol" class="list-none" :items="$decideSteps" />
            </div>

            {{-- Spans both columns: verbatim AG guidance (verified July 2026 at
                 illinoisattorneygeneral.gov/Consumer-Protection/Home-Repair):
                 "Get more than one estimate and get them in writing." --}}
            <p class="text-sm text-zinc-600 md:col-span-2 dark:text-zinc-400">
                Do your homework: the
                <a href="https://illinoisattorneygeneral.gov/Consumer-Protection/Home-Repair/" target="_blank" rel="noopener nofollow"
                   class="font-medium text-sky-700 underline hover:text-sky-600 dark:text-sky-400">Illinois Attorney General's Consumer Protection</a>
                office explains how to avoid home-repair fraud and choose a reliable contractor. Its guidance for
                homeowners is explicit &mdash;
                <strong class="font-semibold text-zinc-800 dark:text-zinc-200">&ldquo;Get more than one estimate and get them in writing&rdquo;</strong>
                &mdash; so comparing side by side before you sign is the state's own advice.
            </p>
        </section>

        <section class="mt-12">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">Recent GS Construction projects</h2>
            {{-- Same card idiom as the homepage/projects grids: group hover
                 zooms the image (scale-105) and lifts the shadow. --}}
            <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($projects as $project)
                    @php
                        $cover = $project->relationLoaded('images') && $project->images->isNotEmpty()
                            ? ($project->images->firstWhere('is_cover', true) ?? $project->images->first())
                            : null;
                    @endphp
                    <a href="{{ route('projects.show', $project) }}" wire:navigate
                       class="group flex flex-col overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                        @if($cover)
                            <div class="relative aspect-[4/3] overflow-hidden">
                                <x-lqip-image
                                    :image="$cover"
                                    size="medium" width="600" height="450"
                                    class="h-full w-full transition duration-300 group-hover:scale-105" />
                            </div>
                        @endif
                        <div class="p-4">
                            <h3 class="font-semibold text-zinc-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-400">
                                {{ $project->title }}
                            </h3>
                            @if($project->location)
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $project->location }}</p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    </main>

    {{-- FAQ (visible + FAQPage schema) --}}
    <x-faq-section
        :faqs="$faqs"
        heading="Frequently asked questions (FAQs)"
        :collapsed="true"
        outer-max-width="max-w-5xl"
        content-max-width="max-w-none"
        section-classes="bg-transparent py-0"
    />

    <div class="mt-6">
        <x-cta-section
            variant="blue"
            heading="Get a second opinion and a free estimate"
            description="It is smart to compare. We are happy to give you a no-pressure estimate even if you are already talking to {{ $competitor['name'] }}."
            primaryText="Request a free estimate"
            primaryHref="/contact"
            secondaryText="Call (224) 735-4200"
            secondaryHref="tel:2247354200"
        />
    </div>

    {{-- Second audience for this page: trades, designers, and suppliers who
         already work with the competitor. Deliberately compact and placed below
         the homeowner CTA so it never competes with the primary conversion. --}}
    {{-- mb-12 because the page root drops its bottom padding at lg (pb-20 lg:pb-0
         exists only to clear the sticky mobile CTA), leaving this flush against
         the footer on desktop. --}}
    <div class="mx-auto mt-8 mb-12 max-w-5xl px-6 lg:px-8">
        <a href="{{ route('trades.index') }}" wire:navigate
           class="group flex flex-col items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-5 py-4 text-sm transition hover:border-sky-300 hover:bg-sky-50 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500 dark:hover:bg-sky-950/30">
            <span class="text-zinc-700 dark:text-zinc-300">
                <span class="font-semibold text-zinc-900 dark:text-white">Work with {{ $competitor['name'] }}?</span>
                Tradespeople, designers, and suppliers &mdash; get in touch to see how we can work together.
            </span>
            <span class="shrink-0 font-semibold text-sky-700 group-hover:text-sky-600 dark:text-sky-400">
                Partner with GS Construction &rarr;
            </span>
        </a>
    </div>

    {{-- Sticky mobile CTA bar --}}
    <div class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden dark:border-zinc-800 dark:bg-gray-950/95">
        <div class="flex items-center gap-3">
            <a href="/contact" wire:navigate class="flex-1 rounded-md bg-sky-600 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-sky-500">
                Free estimate
            </a>
            <a href="tel:2247354200" class="rounded-md px-4 py-2.5 text-center text-sm font-semibold text-sky-700 ring-1 ring-sky-300 dark:text-sky-300 dark:ring-sky-700">
                Call
            </a>
        </div>
    </div>
</div>
