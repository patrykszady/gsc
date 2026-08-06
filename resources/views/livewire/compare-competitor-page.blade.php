@php
    // ?bg=grid|floorplan|mesh|aurora previews the other decor variants locally.
    $decor = app()->environment('local') ? request()->query('bg', 'grid') : 'grid';
@endphp
<div class="relative isolate overflow-x-clip bg-white pb-20 dark:bg-zinc-950 lg:pb-0">
    <x-page-decor :variant="$decor" />

    <x-breadcrumbs :items="[
        ['label' => 'Alternatives', 'url' => route('compare.index')],
        ['label' => ($competitor['name'] ?? '') . ' Alternative'],
    ]" />

    {{-- "vs" framing retired with the comparison itself: the page presents GS
         as an alternative to the company the reader searched for, so the H1
         says that and the eyebrow stops pre-empting it. --}}
    <div class="mx-auto max-w-3xl px-6 pt-2 text-center lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Choosing a remodeler</p>
    </div>

    <x-page-hero
        title="An Alternative to {{ $competitor['name'] }}"
        :key-suffix="'compare-' . $competitor['slug']"
    />

    {{-- Trust signals --}}
    <x-trust-signals class="mt-6" :eyebrow="config('brand.display_name')" />

    <div class="mx-auto max-w-5xl px-6 pb-6 lg:px-8">
        <header class="mx-auto mt-10 max-w-3xl text-center">
            {{-- Promised "a factual side-by-side" until the competitor column
                 came out of the table. The page still names them, because that
                 is what the reader searched for, but everything it now sets out
                 is ours — so it says so rather than describing a comparison it
                 no longer makes. --}}
            <p class="text-lg text-zinc-600 dark:text-zinc-300">
                Considering {{ $competitor['name'] }} for your kitchen, bathroom, addition, basement,
                or whole-home remodel? Here is what {{ config('brand.display_name') }} offers, so you
                know what to weigh before requesting estimates.
            </p>

            {{-- Shared CTA button component: same look, sizing, and click
                 tracking as every other primary CTA on the site. --}}
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <x-buttons.cta href="/contact" variant="primary" size="lg">
                    Get a free estimate
                </x-buttons.cta>
                {{-- Was the one CTA on the site still hand-rolled, on ring-1
                     rather than border, so it sat a hair different from every
                     other outline button. --}}
                <x-buttons.cta href="tel:{{ config('brand.phone_href') }}" variant="outline" size="lg">
                    Call {{ config('brand.phone') }}
                </x-buttons.cta>
            </div>
        </header>

        <section class="group mt-12 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
            <div class="overflow-x-auto">
                {{-- Two columns only. The third column used to restate what the
                     other company does; the table now sets out what WE do and
                     leaves readers to check anyone else directly, which is both
                     what counsel asked for and the only column we can stand
                     behind for every row. min-w drops with the column. --}}
                <table class="w-full min-w-100 text-left text-sm">
                    <thead class="bg-zinc-50 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Criteria</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-sky-700 dark:text-sky-400">GS Construction &amp; Remodeling</th>
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
                                        @php $reviewProfiles = array_values(array_filter(site_config('socials'), fn ($s) => $s['review'] ?? false)); @endphp
                                        Verified reviews on
                                        @foreach($reviewProfiles as $profile)
                                            <a href="{{ $profile['url'] }}" target="_blank" rel="noopener noreferrer external"
                                               class="font-medium text-sky-700 underline hover:text-sky-600 dark:text-sky-400">{{ $profile['label'] }}</a>@if(! $loop->last){{ $loop->remaining === 1 ? ', and' : ',' }}@else.@endif
                                        @endforeach
                                    @else
                                        {{ $row['us'] }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- The "Verified {month} · Source: theirsite.com · archived copies"
             line was here to substantiate the competitor column. With that
             column gone there is nothing of theirs left to source, and citing
             their site for a table of our own practices would imply we are
             still reporting on them. The trademark notice stays, since the
             page still names them. --}}
        <p class="mt-4 text-center text-xs text-zinc-500 dark:text-zinc-400">
            This table describes {{ config('brand.display_name') }}'s own practices.
            {{ $competitor['name'] }} is named for reference only.
        </p>

        @if(!empty($competitor['comparison_note']))
        <section class="mt-12">
            {{-- Counsel's revision after the 2026-08 cease-and-desist: keep the
                 focus positive and on us, rather than framing the section as a
                 verdict on a named competitor. --}}
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">
                The GS Construction Advantage
            </h2>
            <p class="mt-4 text-zinc-700 dark:text-zinc-300">
                {{ $competitor['comparison_note'] }}
            </p>
        </section>
        @endif

        <x-mid-cta />

        <section class="mt-12">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">GS Construction Difference</h2>
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
    </div>

    {{-- Customer reviews — same column width as the page (the component brings
         its own max-width + padding, so pass the width instead of wrapping). --}}
    <livewire:testimonials-section
        :show-header="true"
        max-width-class="max-w-5xl"
        section-classes="relative isolate overflow-hidden mt-2 pb-6"
        :key="'compare-reviews-'.$competitor['slug']"
    />

    <div class="mx-auto max-w-5xl px-6 pb-16 lg:px-8">
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
                            <div class="relative aspect-4/3 overflow-hidden">
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
    </div>

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
            secondaryText="Call {{ config('brand.phone') }}"
            secondaryHref="tel:{{ config('brand.phone_href') }}"
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
    <div class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden dark:border-zinc-800 dark:bg-zinc-950/95">
        <div class="flex items-center gap-3">
            <a href="/contact" wire:navigate class="flex-1 rounded-md bg-sky-600 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-sky-500">
                Free estimate
            </a>
            <a href="tel:{{ config('brand.phone_href') }}" class="rounded-md px-4 py-2.5 text-center text-sm font-semibold text-sky-700 ring-1 ring-sky-300 dark:text-sky-300 dark:ring-sky-700">
                Call
            </a>
        </div>
    </div>
</div>
