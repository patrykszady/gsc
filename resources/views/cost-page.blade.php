{{-- Rendered by the /costs/{slug} route with $guide from config/remodel-costs.php --}}
<x-layouts.app
    :title="$guide['name'] . ' in Chicago\'s Suburbs (' . now()->year . ' Guide)'"
    :metaDescription="\Illuminate\Support\Str::limit($guide['answer'], 155)"
>
    <x-breadcrumb-schema :items="[
        ['name' => 'Remodeling Costs', 'url' => route('costs.index')],
        ['name' => $guide['name']],
    ]" />

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <nav class="flex text-sm" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <a href="{{ route('costs.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Remodeling Costs</a>
                </li>
            </ol>
        </nav>

        <p class="mt-6 text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">{{ $guide['name'] }} · {{ now()->year }}</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            {{ $guide['h1'] }}
        </h1>
        {{-- Direct answer — the paragraph AI answers and voice search quote. --}}
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            {{ $guide['answer'] }}
        </p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Typical price tiers</h2>
        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <th class="py-2 pr-4">Tier</th>
                        <th class="py-2 pr-4">Range</th>
                        <th class="py-2">What that buys</th>
                    </tr>
                </thead>
                <tbody class="text-zinc-700 dark:text-zinc-300">
                    @foreach($guide['tiers'] as $tier)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                            <td class="py-3 pr-4 font-medium whitespace-nowrap">{{ $tier['tier'] }}</td>
                            <td class="py-3 pr-4 font-semibold whitespace-nowrap text-sky-700 dark:text-sky-400">{{ $tier['range'] }}</td>
                            <td class="py-3">{{ $tier['includes'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">What moves the number</h2>
        <div class="mt-5 space-y-4">
            @foreach($guide['drivers'] as $driver)
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $driver['factor'] }}</h3>
                    <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $driver['note'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">Timeline</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                {{ $guide['timeline'] }} See the full <a href="{{ route('process') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">GS process step by step</a>,
                or how homeowners <a href="{{ route('financing') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">pay for a remodel</a>.
            </p>
        </div>
    </div>

    <div class="mx-auto mt-12 max-w-3xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="{{ $guide['name'] }} — common questions"
            :collapsed="false"
            :faqs="$guide['faq']"
        />
    </div>

    <x-cta-section
        variant="blue"
        heading="Want your exact number?"
        description="Free in-home estimate with an itemized scope — real figures for your actual space, not a range."
    />
</x-layouts.app>
