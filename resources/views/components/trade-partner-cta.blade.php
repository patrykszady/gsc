@props([
    'heading' => 'Are you a trade? Partner with GS Construction.',
    'description' => 'Electricians, plumbers, tile setters, finish carpenters — if you take pride in your work and show up when you say you will, we want to meet you. Steady projects across the North Shore and northwest suburbs, clear scopes, and fast pay.',
    'buttonText' => 'Work with us →',
    'href' => '/jobs',
])

{{-- Sky-tinted trade-recruiting banner. Reused on /trades and every trade page
     with content tailored to the audience of that page. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-start gap-6 rounded-2xl border border-sky-200 bg-sky-50 p-8 sm:flex-row sm:items-center sm:justify-between dark:border-sky-500/20 dark:bg-sky-500/5']) }}>
    <div>
        <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
            {{ $heading }}
        </h2>
        <p class="mt-2 max-w-2xl text-zinc-600 dark:text-zinc-300">
            {{ $description }}
        </p>
    </div>
    <a href="{{ $href }}" wire:navigate
       class="shrink-0 rounded-lg bg-sky-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500">
        {{ $buttonText }}
    </a>
</div>
