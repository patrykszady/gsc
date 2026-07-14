@php($guides = collect(config('remodel-costs.guides', [])))
<x-layouts.app
    :title="'Remodeling Costs in Chicago\'s Suburbs (' . now()->year . ' Guide) | GS Construction'"
    metaDescription="Real published price ranges for kitchen, bathroom, basement, and home-addition remodels in the Chicago suburbs — from a contractor that puts its numbers in writing."
>
    <x-breadcrumb-schema :items="[
        ['name' => 'Remodeling Costs'],
    ]" />

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Remodeling Costs · {{ now()->year }}</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            What remodeling really costs in the Chicago suburbs
        </h1>
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            {{ config('remodel-costs.intro') }}
        </p>

        {{-- At-a-glance table --}}
        <div class="mt-8 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <th class="py-2 pr-4">Project</th>
                        <th class="py-2">Typical range</th>
                    </tr>
                </thead>
                <tbody class="text-zinc-700 dark:text-zinc-300">
                    <tr class="border-b border-zinc-100 dark:border-zinc-800"><td class="py-3 pr-4 font-medium">Kitchen remodel</td><td class="py-3">$35,000–$80,000 (custom $100K+)</td></tr>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800"><td class="py-3 pr-4 font-medium">Bathroom remodel</td><td class="py-3">$15,000–$60,000+</td></tr>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800"><td class="py-3 pr-4 font-medium">Basement finishing</td><td class="py-3">$45,000–$150,000+</td></tr>
                    <tr><td class="py-3 pr-4 font-medium">Home addition</td><td class="py-3">$200–$400 per sq ft</td></tr>
                </tbody>
            </table>
        </div>

        {{-- Guide cards --}}
        <div class="mt-10 grid gap-5 sm:grid-cols-2">
            @foreach($guides as $guide)
                <a href="{{ route('costs.show', ['slug' => $guide['slug']]) }}" wire:navigate
                   class="flex h-full flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $guide['name'] }}</h2>
                    <p class="mt-2 grow text-sm text-zinc-600 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($guide['answer'], 140) }}</p>
                    <p class="mt-4 text-sm font-semibold text-sky-600 dark:text-sky-400">Full {{ now()->year }} breakdown →</p>
                </a>
            @endforeach
        </div>

        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">Why we publish our numbers</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                An itemized scope is how you compare contractors apples-to-apples — and it's what your
                lender wants too. See <a href="{{ route('financing') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">how homeowners pay for remodels</a>
                and <a href="{{ route('process') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">how the GS process works</a>.
            </p>
        </div>
    </div>

    <x-cta-section
        variant="blue"
        heading="Want your exact number?"
        description="Free in-home estimate with an itemized scope — real figures for your actual space, not a range."
    />
</x-layouts.app>
