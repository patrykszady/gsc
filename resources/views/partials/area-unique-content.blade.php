{{--
    Compact per-city unique content block.
    Reused on /areas-served/{city}/contact and /areas-served/{city}/services/*
    to differentiate them from the 88 other cities and break Google's
    near-duplicate clustering (Jaccard ≥ 0.85 on the 5-shingle audit).

    Variables required:
      $area      — App\Models\AreaServed
      $context   — string: 'contact' | 'kitchen-remodeling' | 'bathroom-remodeling'
                   | 'home-remodeling' | 'basement-remodeling' | 'home-additions'
                   (drives the heading + framing copy)
--}}
@php
    $ctx = $context ?? 'contact';

    // Headings vary by context so the H2 itself is unique per (city, service)
    // combo — Google heavily weights H2/H3 in dup detection.
    $heading = match ($ctx) {
        'kitchen-remodeling'  => "Kitchen remodeling in {$area->city}, IL",
        'bathroom-remodeling' => "Bathroom remodeling in {$area->city}, IL",
        'home-remodeling'     => "Whole-home remodeling in {$area->city}, IL",
        'basement-remodeling' => "Basement finishing in {$area->city}, IL",
        'home-additions'      => "Home additions in {$area->city}, IL",
        'contact'             => "Serving {$area->city}, IL homeowners",
        default               => "Remodeling in {$area->city}, IL",
    };

    $serviceLine = match ($ctx) {
        'kitchen-remodeling'  => "kitchen remodels",
        'bathroom-remodeling' => "bathroom remodels",
        'home-remodeling'     => "whole-home remodels",
        'basement-remodeling' => "basement remodels",
        'home-additions'      => "home additions",
        default               => "remodeling projects",
    };
@endphp

@if($area->hasUniqueContent() || filled($area->landmarks) || filled($area->permit_notes))
<section class="bg-white py-10 sm:py-14 dark:bg-zinc-900" aria-label="About {{ $area->city }} {{ $serviceLine }}">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
            {{ $heading }}
        </h2>

        @if(filled($area->local_intro))
            <div class="mt-4 prose prose-zinc dark:prose-invert max-w-none">
                {!! nl2br(e($area->local_intro)) !!}
            </div>
        @elseif(filled($area->intro))
            <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                {{ $area->intro }}
            </p>
        @endif

        @if(filled($area->landmarks))
            <div class="mt-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Neighborhoods &amp; landmarks near our {{ $area->city }} {{ $serviceLine }}
                </h3>
                <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">{{ $area->landmarks }}</p>
            </div>
        @endif

        @if(filled($area->permit_notes))
            <div class="mt-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                    {{ $area->city }} permits &amp; building codes for {{ $serviceLine }}
                </h3>
                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $area->permit_notes }}</p>
            </div>
        @endif
    </div>
</section>
@endif
