@props(['area'])

@php
    $city = $area->city ?? 'the Chicago suburbs';
    // Ranges mirror the business-approved figures in config/geo-answers.php
    // (also served at /geo/answers.json and rendered on /faq). Single source of
    // truth for pricing lives there — keep these in sync if they change.
    $ranges = [
        ['label' => 'Kitchen remodel',    'range' => '$35k–$80k',  'note' => 'Cabinets, countertops & layout changes'],
        ['label' => 'Bathroom remodel',   'range' => '$15k–$60k',  'note' => 'Hall bath to custom primary suite'],
        ['label' => 'Basement finishing', 'range' => '$45k–$150k', 'note' => 'Standard build-out to bath + wet bar'],
        ['label' => 'Home addition',      'range' => '$60k–$350k', 'note' => '≈ $200–$400 per square foot'],
    ];
@endphp

<section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" aria-label="Remodeling cost guide for {{ $city }}">
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-8">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="font-heading text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">
                    What remodeling costs in {{ $city }}
                </h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Typical local ranges — every project is quoted after a free in-home visit.
                </p>
            </div>
            <a href="{{ url('/faq') }}" wire:navigate class="text-sm font-medium text-sky-700 hover:underline dark:text-sky-400">See full FAQ →</a>
        </div>

        <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($ranges as $r)
                <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $r['label'] }}</dt>
                    <dd class="mt-1 font-heading text-2xl font-bold tabular-nums tracking-tight text-zinc-900 dark:text-white">{{ $r['range'] }}</dd>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $r['note'] }}</p>
                </div>
            @endforeach
        </dl>

        <p class="mt-5 text-xs text-zinc-400 dark:text-zinc-500">
            Demolition, debris removal and dumpster fees are included in every {{ $city }}-area estimate — no surprise charges on the final invoice.
        </p>
    </div>
</section>
