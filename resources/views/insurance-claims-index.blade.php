@php($claims = collect(config('insurance-claims.claims', [])))
<x-layouts.app
    title="Insurance Claim Repairs & Rebuilds in Chicago's Suburbs | GS Construction"
    metaDescription="Water, roof, siding, storm, and fire damage rebuilds — itemized estimates your adjuster can work with, and one licensed contractor to put your home back to pre-loss condition."
>
    <x-breadcrumb-schema :items="[
        ['name' => 'Insurance Claim Repairs'],
    ]" />

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Insurance Claim Repairs</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            After the adjuster leaves, someone has to rebuild your home. That's us.
        </h1>
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            {{ config('insurance-claims.intro') }}
        </p>

        {{-- How it works with a claim --}}
        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-2xl font-bold text-sky-600 dark:text-sky-400">1</p>
                <h2 class="mt-1 font-semibold text-zinc-900 dark:text-white">Document</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Photo documentation of the full loss — including what's easy to miss — before anything gets covered up.</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-2xl font-bold text-sky-600 dark:text-sky-400">2</p>
                <h2 class="mt-1 font-semibold text-zinc-900 dark:text-white">Itemize</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">A free line-by-line rebuild estimate your adjuster can compare against the claim scope — and we'll meet them on site when it helps.</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-2xl font-bold text-sky-600 dark:text-sky-400">3</p>
                <h2 class="mt-1 font-semibold text-zinc-900 dark:text-white">Rebuild</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">One project lead sequences every trade — to pre-loss condition or better, with our <a href="{{ route('warranty') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">written warranty</a>.</p>
            </div>
        </div>

        {{-- Damage-type cards --}}
        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">What happened at your house?</h2>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            @foreach($claims as $claim)
                <a href="{{ route('insurance-claims.show', ['slug' => $claim['slug']]) }}" wire:navigate
                   class="flex h-full flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $claim['name'] }}</h3>
                    <p class="mt-2 grow text-sm text-zinc-600 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($claim['answer'], 130) }}</p>
                    <p class="mt-4 text-sm font-semibold text-sky-600 dark:text-sky-400">What to do &amp; how we rebuild →</p>
                </a>
            @endforeach
        </div>

        {{-- Compliance / trust note --}}
        <div class="mt-10 rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900/50">
            <h2 class="font-heading text-lg font-bold text-zinc-900 dark:text-white">Straight talk about our role</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                {{ config('insurance-claims.disclaimer') }}
            </p>
        </div>
    </div>

    <div class="mx-auto mt-12 max-w-3xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="Insurance-claim repairs — common questions"
            :collapsed="false"
            :faqs="[
                ['question' => 'Do you work directly with my insurance company?', 'answer' => 'We work for you — documenting the damage, providing an itemized rebuild estimate your adjuster can review line by line, and meeting the adjuster on site when it helps. Coverage decisions stay between you and your insurer; we make sure the rebuild scope reflects the real loss.'],
                ['question' => 'Do you handle emergency mitigation like water extraction or board-up?', 'answer' => 'No — mitigation (drying, extraction, soot removal, board-up) is its own emergency trade and comes first. Our lane is the rebuild after mitigation: everything that was torn out or damaged, put back to pre-loss condition or better.'],
                ['question' => 'What if the insurance scope is lower than the real repair cost?', 'answer' => 'Because our estimate is itemized, the difference is visible line by line — a specific conversation between you and your insurer instead of two mystery lump sums. Supplements based on documented conditions found during the rebuild are a normal part of the process.'],
                ['question' => 'Are you licensed and insured for this work?', 'answer' => 'Yes — GS Construction & Remodeling is a licensed, bonded, and insured general contractor, and specialty work runs through licensed trade partners (Illinois state-licensed roofers and plumbers, municipally licensed electricians).'],
            ]"
        />
    </div>

    <x-cta-section
        variant="blue"
        heading="Start with a free damage assessment"
        description="We'll document what happened, give you an itemized rebuild estimate, and walk you through what comes next — no obligation."
    />
</x-layouts.app>
