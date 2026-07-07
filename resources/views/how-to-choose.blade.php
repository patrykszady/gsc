<x-layouts.app
    title="How to Choose a Remodeling Contractor (Chicago Suburbs)"
    metaDescription="A plain-English guide to hiring a kitchen, bath or whole-home remodeler in the Chicago suburbs — what to check, the questions to ask, and how to compare estimates fairly."
>
    @php
        // Reuse the same decision criteria that power the comparison tables, but
        // framed neutrally as "what to look for" — educational, non-branded content
        // that captures research intent and is easily cited by AI engines.
        $checks = collect(config('competitors.criteria', []))
            ->filter(fn ($c) => filled($c['why'] ?? null) && filled($c['label'] ?? null))
            ->values();

        $reviewCount = \App\Models\Testimonial::query()->count();

        $questions = [
            'Are you licensed and insured, and can I see the certificates?',
            'Who is my point of contact, and who is actually on site each day?',
            'Do you self-perform the work or subcontract it — and who supervises?',
            'Can I get an itemized estimate I can compare line-by-line?',
            'Who pulls the permits and schedules inspections?',
            'Can I bring my own designer and buy my own materials?',
            'How will I know the schedule and what changed — portal, email, calls?',
            'Where can I see verified reviews and recent project photos?',
        ];

        $faqs = [
            ['question' => 'How many remodeling quotes should I get?',
             'answer' => 'Get two to three itemized estimates. More than that rarely adds clarity and slows you down. What matters is comparing them line-by-line on the same scope — not just the bottom-line number.'],
            ['question' => 'Should I choose a design-build firm or an owner-led contractor?',
             'answer' => 'Design-build firms bundle design and construction under one roof, which is convenient but funnels you into their in-house look and pricing. An owner-led contractor lets you keep your own designer or architect and shop your own materials, so you retain control of the look and the budget. Neither is “better” — it depends on how much control you want.'],
            ['question' => 'What licenses and insurance should a Chicago-area remodeler have?',
             'answer' => 'Ask for proof of general liability insurance and workers’ compensation, plus any local municipal contractor registration your village requires. Proper licensing and insurance protect you if something goes wrong on the job — never skip verifying it.'],
            ['question' => 'How do I compare remodeling estimates fairly?',
             'answer' => 'Put the estimates side-by-side on the same scope and insist on itemized line items (demolition, materials, labor, permits, finishes). A single lump sum hides markups; an itemized scope lets you compare apples-to-apples and see exactly what you are paying for.'],
            ['question' => 'What’s the biggest red flag when hiring a remodeler?',
             'answer' => 'Vague scope and no itemization, no proof of insurance, large upfront deposits, and no verifiable reviews or recent project photos. A trustworthy contractor is transparent about scope, price, permits, and who does the work.'],
        ];
    @endphp

    <x-breadcrumb-schema :items="[['name' => 'How to Choose a Remodeling Contractor']]" />

    {{-- Visual breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex text-sm" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">How to Choose a Contractor</span>
                </li>
            </ol>
        </nav>
    </div>

    {{-- Hero slider with overlay heading + CTA --}}
    <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-2xl">
            <livewire:main-project-hero-slider
                :images-only="true"
                height-classes="h-[360px] sm:h-[460px] lg:h-[540px]"
                :slides="[
                    ['projectType' => 'kitchen'],
                    ['projectType' => 'bathroom'],
                    ['projectType' => 'home-remodel'],
                ]"
                :key="'guide-choose-hero'"
            />
            <div class="pointer-events-none absolute inset-0 z-10 flex items-end bg-linear-to-t from-black/80 via-black/40 to-transparent pb-8 sm:pb-12 lg:pb-16">
                <div class="mx-auto w-full max-w-7xl px-6 lg:px-8">
                    <p class="text-sm font-semibold uppercase tracking-wide text-sky-300">Homeowner guide</p>
                    <h1 class="mt-2 max-w-4xl font-heading text-3xl font-bold text-balance text-white text-shadow-lg sm:text-4xl lg:text-5xl">
                        How to choose a remodeling contractor in the Chicago suburbs
                    </h1>
                    <a href="{{ url('/contact') }}" wire:navigate
                       class="pointer-events-auto mt-5 inline-flex items-center gap-2 rounded-lg bg-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500">
                        Get a free estimate
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Trust signals --}}
    <div class="mx-auto mt-6 max-w-5xl px-6 lg:px-8">
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['Family-owned', 'Father & son'],
                ['Combined experience', '40+ years'],
                ['Verified reviews', $reviewCount . '+'],
                ['Licensed & insured', 'Yes'],
            ] as [$label, $value])
                <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
        <p class="text-lg leading-8 text-zinc-600 dark:text-zinc-300">
            Hiring the right contractor decides how your remodel goes more than any single material choice.
            Here's what actually matters, the questions to ask, and how to compare quotes fairly —
            whether or not you end up working with us.
        </p>

        <h2 class="mt-12 font-heading text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">What to check before you hire</h2>
        <ol class="mt-6 space-y-6">
            @foreach ($checks as $i => $c)
                <li class="flex gap-4">
                    <span class="mt-0.5 flex h-7 w-7 flex-none items-center justify-center rounded-full bg-sky-100 text-sm font-bold text-sky-700 tabular-nums dark:bg-sky-900/40 dark:text-sky-300">{{ $i + 1 }}</span>
                    <div>
                        <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $c['label'] }}</h3>
                        <p class="mt-1 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $c['why'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>

        <h2 class="mt-12 font-heading text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Questions to ask every contractor</h2>
        <ul class="mt-6 space-y-3">
            @foreach ($questions as $q)
                <li class="flex gap-3 text-base leading-7 text-zinc-700 dark:text-zinc-200">
                    <svg class="mt-1.5 h-4 w-4 flex-none text-sky-600 dark:text-sky-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                    </svg>
                    <span>{{ $q }}</span>
                </li>
            @endforeach
        </ul>

        <div class="mt-12 rounded-2xl bg-zinc-50 p-6 dark:bg-zinc-800/40">
            <p class="text-base text-zinc-700 dark:text-zinc-200">
                Comparing GS Construction with a specific firm? See our
                <a href="{{ route('compare.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">factual side-by-side comparisons</a>,
                read <a href="{{ url('/reviews') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">verified reviews</a>,
                or <a href="{{ url('/contact') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">request a free itemized estimate</a>.
            </p>
        </div>
    </main>

    <x-faq-section
        :faqs="$faqs"
        heading="Choosing a contractor: common questions"
        :collapsed="false"
        contentMaxWidth="max-w-3xl"
    />

    <x-cta-section
        variant="blue"
        heading="Ready to scope your remodel?"
        description="Get a free, no-pressure in-home estimate with an itemized scope from Greg & Patryk — the owners who'll run your project start to finish."
        primaryText="Schedule Free Consultation"
        primaryHref="/contact"
        secondaryText="View Our Work"
        secondaryHref="/projects"
    />
</x-layouts.app>
