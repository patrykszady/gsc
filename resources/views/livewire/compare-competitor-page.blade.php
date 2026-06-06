<div class="bg-white pb-20 dark:bg-gray-950 lg:pb-0">
    <x-breadcrumb-schema :items="[
        ['name' => 'Compare Contractors', 'url' => route('compare.index')],
        ['name' => 'GS Construction vs ' . ($competitor['name'] ?? '')],
    ]" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <a href="{{ route('compare.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Compare</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">GS Construction vs {{ $competitor['name'] }}</span>
                </li>
            </ol>
        </nav>
    </div>

    <div class="mx-auto max-w-3xl px-6 pt-2 text-center lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Alternative to {{ $competitor['name'] }}</p>
    </div>

    <div class="mx-auto mt-4 w-full max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-2xl">
            <livewire:main-project-hero-slider
                :images-only="true"
                height-classes="h-[340px] sm:h-[420px] lg:h-[500px]"
                :slides="[
                    ['projectType' => 'kitchen'],
                    ['projectType' => 'bathroom'],
                    ['projectType' => 'home-remodel'],
                ]"
                :key="'compare-hero-'.$competitor['slug']"
            />
            <div class="pointer-events-none absolute inset-0 z-10 flex items-end bg-linear-to-t from-black/80 via-black/40 to-transparent pb-12 sm:pb-16 lg:pb-20">
                <div class="mx-auto w-full max-w-7xl px-6 lg:px-8">
                    <h1 class="font-heading text-4xl font-bold text-white text-shadow-lg sm:text-5xl lg:text-6xl">
                        GS Construction vs {{ $competitor['name'] }}
                    </h1>
                </div>
            </div>
        </div>
    </div>

    {{-- Trust signals --}}
    <div class="mx-auto mt-6 max-w-5xl px-6 lg:px-8">
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Family-owned</dt>
                <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">Father &amp; son</dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Combined experience</dt>
                <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">40+ years</dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Verified reviews</dt>
                <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">{{ $reviewCount }}+</dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Licensed &amp; insured</dt>
                <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">Yes</dd>
            </div>
        </dl>
    </div>

    <main class="mx-auto max-w-5xl px-6 pb-16 lg:px-8">
        <header class="mx-auto max-w-3xl text-center">
            <p class="text-lg text-zinc-600 dark:text-zinc-300">
                Considering {{ $competitor['name'] }} for your kitchen, bathroom, or whole-home remodel?
                Here is a factual side-by-side so you can compare options before requesting estimates.
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-center gap-3 text-sm">
                <a href="/contact" wire:navigate class="rounded-md bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-500">
                    Get a free estimate from GS Construction
                </a>
            </div>
            @if($competitor['slug'] !== 'kitchen-village' && !empty($competitor['website']))
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                    Source: {{ preg_replace('#^www\.#', '', parse_url($competitor['website'], PHP_URL_HOST)) }}
                </p>
            @endif
        </header>

        <section class="mt-12 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
            <div class="overflow-x-auto">
                <table class="w-full min-w-160 text-left text-sm">
                    <thead class="bg-zinc-50 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Criteria</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-sky-700 dark:text-sky-400">GS Construction</th>
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
                                <td class="px-4 py-3 align-top text-zinc-700 dark:text-zinc-300">{{ $row['us'] }}</td>
                                <td class="px-4 py-3 align-top text-zinc-600 dark:text-zinc-400">{{ $row['them'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <p class="mx-auto mt-4 max-w-2xl text-center text-xs text-zinc-500 dark:text-zinc-400">
            We compare publicly available information only. Always verify details directly with each company before making a decision.
            @if(!empty($lastVerified))
                <span class="mt-1 block">Information verified {{ \Illuminate\Support\Carbon::parse($lastVerified)->format('F Y') }}.</span>
            @endif
        </p>

        @if(!empty($competitor['comparison_note']))
        <section class="mx-auto mt-12 max-w-3xl">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">
                How GS Construction compares to {{ $competitor['name'] }}
            </h2>
            <p class="mt-4 text-zinc-700 dark:text-zinc-300">
                {{ $competitor['comparison_note'] }}
            </p>
        </section>
        @endif

        {{-- Mid-page CTA --}}
        <div class="mt-12 rounded-2xl bg-sky-50 px-6 py-6 text-center ring-1 ring-sky-100 dark:bg-sky-950/40 dark:ring-sky-900 sm:flex sm:items-center sm:justify-between sm:text-left">
            <p class="font-heading text-lg font-semibold text-zinc-900 dark:text-white">
                Want a second opinion before you decide?
            </p>
            <div class="mt-4 flex flex-wrap items-center justify-center gap-3 sm:mt-0">
                <a href="/contact" wire:navigate class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500">
                    Get a free estimate
                </a>
                <a href="tel:2247354200" class="rounded-md px-4 py-2 text-sm font-semibold text-sky-700 ring-1 ring-sky-300 hover:bg-white dark:text-sky-300 dark:ring-sky-700">
                    Call (224) 735-4200
                </a>
            </div>
        </div>

        <section class="mt-12">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">The GS Construction difference</h2>
            <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h3 class="font-heading text-lg font-semibold text-sky-700 dark:text-sky-400">Your designer, your decisions</h3>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        We build your remodel hand-in-hand with the independent designers and architects <em>you</em> choose —
                        or you can be your own designer. We send you to our trusted material sources, follow your requirements,
                        and install the materials you purchase. You are never funneled into one in-house look — we are flexible.
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h3 class="font-heading text-lg font-semibold text-sky-700 dark:text-sky-400">Transparent pricing — no labor upcharge</h3>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        Many firms hire out the trades and mark up the labor on top. We give you an itemized scope and
                        clear pricing, so you know exactly what you are paying for and who is doing the work.
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h3 class="font-heading text-lg font-semibold text-sky-700 dark:text-sky-400">One team, start to finish</h3>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        Patryk and Greg Szady are your first <em>and</em> last point of contact. With bigger firms a new
                        coordinator owns each phase — and every hand-off is a chance for confusion and costly mistakes.
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h3 class="font-heading text-lg font-semibold text-sky-700 dark:text-sky-400">Daily — your project portal</h3>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        Log in to <strong>Daily</strong> any time to see your past and upcoming schedule, current change
                        orders, and up-to-date balances — no guessing, no waiting for a callback to know where things stand.
                    </p>
                </div>
            </div>
        </section>
    </main>

    {{-- Customer reviews (full viewport width) --}}
    <livewire:testimonials-section
        :show-header="true"
        section-classes="relative isolate overflow-hidden mt-12 py-8"
        :key="'compare-reviews-'.$competitor['slug']"
    />

    <main class="mx-auto max-w-5xl px-6 pb-16 lg:px-8">
        <section class="mt-12 grid gap-8 md:grid-cols-2">
            <div>
                <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">Why homeowners choose GS Construction</h2>
                <ul class="mt-4 space-y-3 text-zinc-700 dark:text-zinc-300">
                    <li>Family-owned father-son team; you talk directly to the owners.</li>
                    <li>{{ $reviewCount }}+ verified reviews across Google, Houzz, Yelp, and Angi.</li>
                    <li>We partner with the independent designer or architect of your choice — or you can be your own designer using our trusted material sources.</li>
                    <li>Itemized estimate and transparent pricing before work begins.</li>
                    <li>Permit pulling and inspection coordination handled for you.</li>
                    <li>Daily client portal to track your schedule, change orders, and balances in real time.</li>
                    <li>Hundreds of in-progress and completed project photos on this site.</li>
                </ul>
            </div>
            <div>
                <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">How to decide</h2>
                <ol class="mt-4 list-decimal space-y-3 pl-5 text-zinc-700 dark:text-zinc-300">
                    <li>Request itemized estimates from at least two contractors.</li>
                    <li>Ask whether you can bring your own designer or architect.</li>
                    <li>Confirm who self-performs the work vs. who is subcontracted and marked up.</li>
                    <li>Confirm who pulls permits and how inspections are scheduled.</li>
                    <li>Ask who your single point of contact is — and whether it changes each phase.</li>
                    <li>Ask whether you get a live portal for your schedule, change orders, and balances.</li>
                </ol>
                <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                    Do your homework: the
                    <a href="https://illinoisattorneygeneral.gov/consumer-protection" target="_blank" rel="noopener nofollow"
                       class="font-medium text-sky-700 underline hover:text-sky-600 dark:text-sky-400">Illinois Attorney General's Consumer Protection office</a>
                    explains how to avoid home-repair fraud and choose a reliable contractor.
                </p>
            </div>
        </section>

        <section class="mt-12">
            <h2 class="font-heading text-2xl font-semibold text-zinc-900 dark:text-white">Recent GS Construction projects</h2>
            <ul class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($projects as $project)
                    @php
                        $cover = $project->relationLoaded('images') && $project->images->isNotEmpty()
                            ? ($project->images->firstWhere('is_cover', true) ?? $project->images->first())
                            : null;
                    @endphp
                    <li class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        @if($cover)
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="block">
                                <x-lqip-image :image="$cover" size="medium" aspectRatio="square" class="w-full" />
                            </a>
                        @endif
                        <div class="p-4">
                            <a href="{{ route('projects.show', $project) }}" wire:navigate
                               class="font-semibold text-zinc-900 hover:text-sky-700 dark:text-white dark:hover:text-sky-400">
                                {{ $project->title }}
                            </a>
                            @if($project->location)
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $project->location }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    </main>

    {{-- FAQ (visible + FAQPage schema) --}}
    <x-faq-section
        :faqs="$faqs"
        heading="Frequently asked questions"
        :collapsed="true"
        content-max-width="max-w-3xl"
        section-classes="bg-white pt-4 pb-8 dark:bg-gray-950"
    />

    <div class="mt-12">
        <x-cta-section
            variant="blue"
            heading="Get a second opinion or free estimate"
            description="It is smart to compare. We are happy to give you a no-pressure estimate even if you are already talking to {{ $competitor['name'] }}."
            primaryText="Request a free estimate"
            primaryHref="/contact"
            secondaryText="Call (224) 735-4200"
            secondaryHref="tel:2247354200"
        />
    </div>

    <div class="mx-auto max-w-5xl px-6 pb-16 lg:px-8">
        <p class="mx-auto mt-10 max-w-2xl text-center text-xs text-zinc-500 dark:text-zinc-400">
            This page compares publicly available information about {{ $competitor['name'] }} for informational purposes.
            All trademarks belong to their respective owners. Always verify details directly with each company.
        </p>
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
