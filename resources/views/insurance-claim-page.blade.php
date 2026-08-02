{{-- Rendered by the /insurance-claims/{slug} route with $claim from config/insurance-claims.php --}}
<x-layouts.app
    :title="$claim['name'] . ' Repairs & Insurance Claim Rebuilds | GS Construction'"
    :metaDescription="\Illuminate\Support\Str::limit($claim['answer'], 155)"
>
    {{-- One trail, one source: <x-breadcrumbs> renders the visible nav AND
         the BreadcrumbList. The hand-rolled nav here stopped one crumb short
         of the schema, so Google saw a trail the reader did not. --}}
    <x-breadcrumbs :items="[['label' => 'Insurance Claim Repairs', 'url' => route('insurance-claims.index')], ['label' => $claim['name']]]" maxWidth="max-w-3xl" padding="pt-10 pb-0 sm:pt-14" />

    <div class="mx-auto max-w-3xl px-4 pt-4 sm:px-6 lg:px-8">

        <p class="mt-6 text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">{{ $claim['name'] }}</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            {{ $claim['h1'] }}
        </h1>
        {{-- Direct answer — the paragraph AI answers and voice search quote. --}}
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            {{ $claim['answer'] }}
        </p>

        {{-- What to do first --}}
        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">What to do first</h2>
        <ol class="mt-5 space-y-3">
            @foreach($claim['steps'] as $i => $step)
                <li class="group flex gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900 shadow-sm transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-sky-50 text-sm font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">{{ $i + 1 }}</span>
                    <p class="text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $step }}</p>
                </li>
            @endforeach
        </ol>

        {{-- Claim know-how --}}
        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Worth knowing before the adjuster visit</h2>
        <div class="mt-5 space-y-4">
            @foreach($claim['coverage_notes'] as $note)
                <div class="group rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900 shadow-sm transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $note['point'] }}</h3>
                    <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $note['note'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- What GS rebuilds --}}
        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">What we rebuild</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $claim['rebuild_scope'] }}</p>
            <p class="mt-3 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                One contract, one project lead, and our
                <a href="{{ route('warranty') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">written warranty</a> —
                see <a href="{{ route('process') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">how the process works</a>
                and <a href="{{ route('trades.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">the licensed trade partners</a> who deliver it.
            </p>
        </div>

        {{-- Compliance note --}}
        <p class="mt-8 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
            {{ config('insurance-claims.disclaimer') }}
        </p>
    </div>

    <div class="mx-auto mt-12 max-w-3xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="{{ $claim['name'] }} — common questions"
            :collapsed="false"
            :faqs="$claim['faq']"
        />
    </div>

    <x-cta-section
        variant="blue"
        heading="Start with a free damage assessment"
        description="We'll document what happened, give you an itemized rebuild estimate, and walk you through what comes next — no obligation."
    />
</x-layouts.app>
