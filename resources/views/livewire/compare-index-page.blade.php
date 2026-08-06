@php
    // ?bg=grid|floorplan|mesh|aurora previews the other decor variants locally.
    $decor = app()->environment('local') ? request()->query('bg', 'grid') : 'grid';
@endphp
<div class="relative isolate overflow-x-clip bg-white dark:bg-zinc-950">
    <x-page-decor :variant="$decor" />

    <x-breadcrumbs :items="[['label' => 'Compare Contractors']]" />

    <div class="mx-auto max-w-3xl px-6 pt-2 text-center lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Compare</p>
    </div>

    <x-page-hero
        title="Compare Chicago-area remodeling contractors"
        key-suffix="compare-index"
    />

    <div class="mx-auto max-w-7xl px-6 pb-16 lg:px-8">
        <div class="mx-auto max-w-3xl text-center">
            <p class="mt-8 text-lg text-zinc-600 dark:text-zinc-300">
                Researching options? See how GS Construction works on the criteria that decide a remodel —
                service area, project focus, communication, and verified reviews — then check each other
                company's details directly with them.
            </p>
        </div>

        <ul class="mx-auto mt-12 grid max-w-4xl grid-cols-1 gap-6 sm:grid-cols-2">
            @foreach($competitors as $competitor)
                @continue(empty($competitor['slug']))
                <li>
                    <x-link-card :href="route('compare.show', ['slug' => $competitor['slug']])">
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Alternative to</p>
                        <h2 class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">
                            GS Construction vs {{ $competitor['name'] }}
                        </h2>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $competitor['location'] ?? '' }} · {{ $competitor['focus'] ?? '' }}
                        </p>
                        <p class="mt-4 text-sm font-medium text-sky-700 dark:text-sky-400">
                            See what we offer &rarr;
                        </p>
                    </x-link-card>
                </li>
            @endforeach
        </ul>
        {{-- How to choose — evergreen guidance that makes this hub a resource, not just a link list --}}
        <section class="mx-auto mt-16 max-w-4xl">
            <h2 class="font-heading text-2xl font-bold text-zinc-900 dark:text-white sm:text-3xl">
                How to compare remodeling contractors in the Chicago suburbs
            </h2>
            <p class="mt-3 text-zinc-600 dark:text-zinc-300">
                Websites and showrooms can look alike. These are the questions that actually separate contractors —
                ask every company the same ones and compare the answers side by side.
            </p>
            @php
                // Each card is a whole-card link, matching the competitor cards
                // above: same border/hover treatment and a "See … →" affordance.
                // Inline links were folded into the card destination so there is
                // no anchor nested inside an anchor.
                $howToCards = [
                    [
                        'title' => 'Who actually does the work?',
                        'body' => 'Many firms sell the job, then subcontract every trade. Ask who performs each trade, who supervises them on site each day, and whose warranty covers the finished work — one company\'s, or five subs\'.',
                        'cta' => 'See our trade partners',
                        'href' => route('trades.index'),
                    ],
                    [
                        'title' => 'Is the estimate itemized?',
                        'body' => 'A single lump-sum number hides markups and makes bids impossible to compare. Insist on an itemized scope, then compare line by line. Our cost guides show what typical projects run in this area.',
                        'cta' => 'See cost guides',
                        'href' => route('costs.index'),
                    ],
                    [
                        'title' => 'Who pulls the permits?',
                        'body' => 'Unpermitted work can stall a home sale and void insurance. Confirm the contractor pulls the permit under their own registration and schedules every inspection — our village permit guides cover what each suburb requires.',
                        'cta' => 'See village permit guides',
                        'href' => route('permits.index'),
                    ],
                    [
                        'title' => 'How will you communicate?',
                        'body' => 'Ask whether you get a live portal showing the schedule, change orders, and balances — or occasional phone calls. Then ask who your day-to-day contact is, and whether that person changes once the contract is signed.',
                        'cta' => 'See how we work',
                        'href' => route('process'),
                    ],
                    [
                        'title' => 'Are the reviews independent?',
                        'body' => 'Testimonials on a company\'s own site are easy to curate. Look for consistent reviews across Google, Houzz, Yelp, and Angi — and read the recent ones, not just the average. Ours show names and towns.',
                        'cta' => 'See our reviews',
                        'href' => route('reviews.index'),
                    ],
                    [
                        'title' => 'Can you keep design control?',
                        'body' => 'Design-build firms often require a design retainer and steer material choices to their showroom. If you want your own designer, architect, or materials, confirm up front that the contractor will build to your plans without a package upcharge.',
                        'cta' => 'Read the full guide',
                        'href' => route('guide.choose-contractor'),
                    ],
                ];
            @endphp
            <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2">
                @foreach($howToCards as $card)
                    <x-link-card :href="$card['href']">
                        <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $card['title'] }}</h3>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $card['body'] }}</p>
                        <p class="mt-4 text-sm font-medium text-sky-700 dark:text-sky-400">{{ $card['cta'] }} &rarr;</p>
                    </x-link-card>
                @endforeach
            </div>

            {{-- Verbatim AG guidance (verified July 2026 at
                 illinoisattorneygeneral.gov/Consumer-Protection/Home-Repair). --}}
            <p class="mt-8 rounded-xl bg-zinc-50 p-5 text-sm text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                Do your homework: the
                <a href="https://illinoisattorneygeneral.gov/Consumer-Protection/Home-Repair/" target="_blank" rel="noopener nofollow"
                   class="font-medium text-sky-700 underline hover:text-sky-600 dark:text-sky-400">Illinois Attorney General's Consumer Protection</a>
                office explains how to avoid home-repair fraud and choose a reliable contractor. Its guidance for
                homeowners is explicit &mdash;
                <strong class="font-semibold text-zinc-800 dark:text-zinc-200">&ldquo;Get more than one estimate and get them in writing&rdquo;</strong>
                &mdash; so comparing side by side before you sign is the state's own advice.
            </p>
        </section>
    </div>

    <div class="mt-12">
        <x-cta-section
            variant="blue"
            heading="Request a free estimate"
            description="Comparing your options? Get a no-pressure, itemized estimate from GS Construction and see the difference for yourself."
            primaryText="Request a free estimate"
            primaryHref="/contact"
            secondaryText="Call {{ config('brand.phone') }}"
            secondaryHref="tel:{{ config('brand.phone_href') }}"
        />
    </div>

    <div class="mx-auto max-w-7xl px-6 pb-16 lg:px-8">
        <p class="mx-auto mt-10 max-w-2xl text-center text-xs text-zinc-500 dark:text-zinc-400">
            We compare publicly available information only. Always verify details directly with each company before making a decision.
            @if(config('competitors.last_verified'))
                <span class="mt-1 block">Information verified {{ \Illuminate\Support\Carbon::parse(config('competitors.last_verified'))->format('F Y') }}.</span>
            @endif
        </p>
    </div>
</div>
