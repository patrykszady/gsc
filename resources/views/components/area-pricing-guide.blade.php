@props(['area'])

@php
    $city = $area->city ?? 'the Chicago suburbs';

    // Ranges mirror the business-approved figures in config/geo-answers.php
    // (also served at /geo/answers.json and rendered on /faq). Single source of
    // truth for pricing lives there — keep these in sync if they change.
    $ranges = [
        ['label' => 'Kitchen remodel',    'value' => '$35k–$80k',  'note' => 'Cabinets, countertops & layout changes'],
        ['label' => 'Bathroom remodel',   'value' => '$15k–$60k',  'note' => 'Hall bath to custom primary suite'],
        ['label' => 'Basement finishing', 'value' => '$45k–$150k', 'note' => 'Standard build-out to bath + wet bar'],
        ['label' => 'Home addition',      'value' => '$60k–$350k', 'note' => '≈ $200–$400 per square foot'],
    ];
@endphp

{{-- Renders through <x-facts-grid>, the same component behind the service
     pages' "at a glance" strip — and now the same LOOK too: flat, no tinted
     tiles, no border card. The heading, per-row notes and FAQ link are kept,
     since those carry information the service strip does not need. --}}
<x-facts-grid
    :items="$ranges"
    :heading="'What remodeling costs in ' . $city"
    subheading="Typical local ranges — every project is quoted after a free in-home visit."
    link-text="See full FAQ →"
    :link-href="url('/faq')"
    class="border-y border-zinc-200 bg-white py-8 dark:border-zinc-700 dark:bg-zinc-900"
    aria-label="Remodeling cost guide for {{ $city }}">
    Demolition, debris removal and dumpster fees are included in every {{ $city }}-area estimate — no surprise charges on the final invoice.
</x-facts-grid>
