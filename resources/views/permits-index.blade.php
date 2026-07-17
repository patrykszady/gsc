@php($guides = collect(\App\Support\PermitGuideInfo::all()))
<x-layouts.app
    title="Building Permit Guides for Chicago's NW Suburbs & North Shore | GS Construction"
    metaDescription="Town-by-town building permit guides for remodeling in Chicago's northwest suburbs and North Shore — who needs a permit, fees, review times, inspections, and contractor registration, from official village sources."
>
    <x-breadcrumb-schema :items="[
        ['name' => 'Permit Guides'],
    ]" />

    <div class="mx-auto max-w-3xl px-6 pt-10 text-center lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Permit Guides · {{ now()->year }}</p>
    </div>

    {{-- Hero --}}
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
                :key="'permits-index-hero'"
            />
            <div class="pointer-events-none absolute inset-0 z-10 flex items-end bg-linear-to-t from-black/80 via-black/40 to-transparent pb-12 sm:pb-16 lg:pb-20">
                <div class="mx-auto w-full max-w-7xl px-6 lg:px-8">
                    <h1 class="font-heading text-4xl font-bold text-white text-shadow-lg sm:text-5xl lg:text-6xl">
                        Building permits, town by town
                    </h1>
                    <p class="mt-3 max-w-2xl text-lg text-white/90 text-shadow-sm">
                        What your village requires, what it costs, and how long review takes —
                        from official municipal sources.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 lg:px-8">
        <p class="speakable text-base leading-7 text-zinc-600 dark:text-zinc-300">
            Nearly every kitchen, bathroom, basement, or addition remodel in the Chicago suburbs
            requires a building permit — and every village runs the process a little differently.
            GS Construction pulls and manages the permits for our remodeling clients as part of
            every project, so these town-by-town guides show you what your village requires,
            what it costs, and how long review typically takes — all from official municipal sources.
        </p>

        {{-- Quick facts strip --}}
        <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <p class="font-heading text-3xl font-bold text-sky-600 dark:text-sky-400">{{ $guides->count() }}</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">town guides, each verified against the village's own permit pages</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <p class="font-heading text-3xl font-bold text-sky-600 dark:text-sky-400">100%</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">of these villages require a permit for kitchen, bath &amp; basement remodels</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <p class="font-heading text-3xl font-bold text-sky-600 dark:text-sky-400">0</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">permit paperwork left to you — GS handles applications and inspections on every project</p>
            </div>
        </div>

        {{-- Town guide cards --}}
        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Pick your town</h2>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            @foreach($guides as $slug => $guide)
                <a href="{{ route('permits.show', ['slug' => $slug]) }}" wire:navigate
                   class="flex h-full flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $guide['town'] }}</h3>
                    <p class="mt-2 grow text-sm text-zinc-600 dark:text-zinc-400">Who needs a permit, fees, review times &amp; inspections in {{ $guide['town'] }}.</p>
                    <p class="mt-4 text-sm font-semibold text-sky-600 dark:text-sky-400">Full {{ $guide['town'] }} permit guide →</p>
                </a>
            @endforeach
        </div>
        <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
            Your town not listed yet? We pull permits across
            <a href="{{ route('areas.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">every community we serve</a> —
            the process below applies almost everywhere.
        </p>

        {{-- What needs a permit vs what usually doesn't --}}
        <h2 class="mt-14 font-heading text-2xl font-bold text-zinc-900 dark:text-white">What usually needs a permit — and what doesn't</h2>
        <p class="mt-3 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            Exact rules vary by village (that's what the town guides are for), but across
            Chicago's suburbs the pattern is consistent:
        </p>
        <div class="mt-6 grid gap-5 sm:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white">
                    <svg class="size-5 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    Permit required
                </h3>
                <ul class="mt-4 space-y-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    <li>Kitchen, bathroom &amp; basement remodels</li>
                    <li>Room additions, porches &amp; sunrooms</li>
                    <li>Moving or adding walls, doors, or windows</li>
                    <li>New or altered electrical wiring and panels</li>
                    <li>Plumbing changes — moving fixtures, new lines, water heaters</li>
                    <li>HVAC replacement and new ductwork</li>
                    <li>Decks, fences, sheds &amp; driveways (most villages)</li>
                    <li>Roof tear-offs and siding replacement (most villages)</li>
                </ul>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white">
                    <svg class="size-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                    Usually exempt
                </h3>
                <ul class="mt-4 space-y-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    <li>Painting and wallpaper</li>
                    <li>Flooring replacement (carpet, hardwood, LVP)</li>
                    <li>Cabinet and countertop swaps in the same layout, with no plumbing or electrical changes</li>
                    <li>Replacing faucets or light fixtures like-for-like</li>
                    <li>Minor trim and interior cosmetic repairs</li>
                </ul>
                <p class="mt-4 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
                    "Usually" is doing real work here — a few villages permit more than you'd expect.
                    When in doubt, check your town's guide or ask us; a five-minute question beats a
                    stop-work order.
                </p>
            </div>
        </div>

        {{-- How the process works --}}
        <h2 class="mt-14 font-heading text-2xl font-bold text-zinc-900 dark:text-white">How the permit process works</h2>
        <ol class="mt-6 space-y-4">
            @foreach([
                ['Apply', 'Drawings, scope of work, and contractor information go to the village — most towns now take everything through an online portal. Contractors typically must be registered (and sometimes bonded) with the village before the application is accepted.'],
                ['Plan review', 'The building department checks the plans against local codes. Simple interior remodels often clear in days; kitchens, baths, basements, and additions typically take one to three weeks depending on the town — each guide lists real review times.'],
                ['Build with inspections', 'Work proceeds in stages, with village inspections at the points that matter: rough framing, electrical, plumbing, and insulation before anything is covered up.'],
                ['Final approval', 'A final inspection signs the project off and closes the permit — the paper trail that protects you at resale and keeps insurance claims clean.'],
            ] as $i => $step)
                <li class="flex gap-4 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-sky-50 text-sm font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">{{ $i + 1 }}</span>
                    <div>
                        <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $step[0] }}</h3>
                        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $step[1] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>

        {{-- Why it matters --}}
        <h2 class="mt-14 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Why skipping the permit costs more</h2>
        <div class="mt-5 space-y-4">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="font-semibold text-zinc-900 dark:text-white">Resale comes with questions</h3>
                <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    Illinois sellers disclose known work, and buyers' attorneys and appraisers ask for
                    permits on remodeled kitchens, baths, and basements. Unpermitted work can stall a
                    closing or come off the price.
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="font-semibold text-zinc-900 dark:text-white">Insurance can push back</h3>
                <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    If unpermitted electrical or plumbing work is implicated in a loss, a claim gets
                    harder. Permitted, inspected work keeps the paper trail on your side — more on that in our
                    <a href="{{ route('insurance-claims.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">insurance claim rebuild guides</a>.
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="font-semibold text-zinc-900 dark:text-white">Retroactive permits are the expensive kind</h3>
                <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    Villages that discover unpermitted work can issue stop-work orders, charge multiplied
                    fees, and require opening finished walls for inspection. Permit fees are a rounding
                    error next to that — see
                    <a href="{{ route('costs.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">what remodeling really costs</a>.
                </p>
            </div>
        </div>

        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">How GS handles permits for you</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                When you remodel with GS Construction, permits are our job, not yours. We prepare
                the drawings and application, register with your village where required, submit and
                track the review, and schedule every inspection through to final sign-off. See
                <a href="{{ route('process') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">how the GS process works</a>.
            </p>
        </div>
    </div>

    <div class="mx-auto mt-6 max-w-3xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="Permit questions homeowners actually ask"
            :collapsed="false"
            :faqs="[
                ['question' => 'Who pulls the permit — the homeowner or the contractor?', 'answer' => 'Either can apply in most villages, but the contractor doing the work should pull the permit — it ties responsibility for code compliance and inspections to the professional. GS Construction pulls and manages permits on every project; villages generally also require the contractor to be registered with them before a permit is issued.'],
                ['question' => 'Do I need a permit for a kitchen or bathroom remodel?', 'answer' => 'In virtually every Chicago-area village, yes. All ten towns covered by these guides explicitly require a building permit for interior remodels such as kitchens, bathrooms, and basements — typically with electrical and plumbing permits rolled in or filed alongside.'],
                ['question' => 'How long does permit review take?', 'answer' => 'It depends on the town and the scope. Simple same-layout remodels can clear in a few days (some villages offer same-day express review for small scopes), while kitchens, baths, basements, and additions typically take one to three weeks. Each town guide lists the review times the village itself publishes.'],
                ['question' => 'How much does a building permit cost?', 'answer' => 'Most villages price permits as a base fee plus an amount tied to construction value, with separate trade fees for electrical and plumbing. For a typical remodel, expect several hundred to a few thousand dollars depending on scope and town — the town guides list each village\'s published fee schedules.'],
                ['question' => 'What happens if I remodel without a permit?', 'answer' => 'The village can issue a stop-work order, charge penalty fees (often a multiple of the original permit fee), and require finished work to be opened up for inspection. Unpermitted work also surfaces at resale and can complicate insurance claims — it is nearly always cheaper to permit the work up front.'],
            ]"
        />
    </div>

    <x-cta-section
        variant="blue"
        heading="Remodeling? We pull the permits."
        description="Free in-home estimate with an itemized scope — permits, drawings, and inspections handled by us on every project."
    />
</x-layouts.app>
