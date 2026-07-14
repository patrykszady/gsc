{{-- Rendered by the /insurance-claims/{slug} route with $claim from config/insurance-claims.php --}}
<x-layouts.app
    :title="$claim['name'] . ' Repairs & Insurance Claim Rebuilds | GS Construction'"
    :metaDescription="\Illuminate\Support\Str::limit($claim['answer'], 155)"
>
    <x-breadcrumb-schema :items="[
        ['name' => 'Insurance Claim Repairs', 'url' => route('insurance-claims.index')],
        ['name' => $claim['name']],
    ]" />

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <nav class="flex text-sm" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <a href="{{ route('insurance-claims.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Insurance Claim Repairs</a>
                </li>
            </ol>
        </nav>

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
                <li class="flex gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-sky-50 text-sm font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">{{ $i + 1 }}</span>
                    <p class="text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $step }}</p>
                </li>
            @endforeach
        </ol>

        {{-- Claim know-how --}}
        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Worth knowing before the adjuster visit</h2>
        <div class="mt-5 space-y-4">
            @foreach($claim['coverage_notes'] as $note)
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
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
