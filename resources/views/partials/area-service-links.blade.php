{{--
    Reusable city-service spoke link block.
    Surfaces the 5 valid area service spoke pages
    (/areas-served/{city}/services/{slug}) on area sub-pages
    (testimonials, projects, about) so every area page has direct internal
    links into the service spokes — improves crawl reach + local relevance.

    Required:
      $area — App\Models\AreaServed
--}}
@php
    $areaServiceLinks = [
        'kitchen-remodeling'  => 'Kitchen Remodeling',
        'bathroom-remodeling' => 'Bathroom Remodeling',
        'home-remodeling'     => 'Home Remodeling',
        'basement-remodeling' => 'Basement Remodeling',
        'home-additions'      => 'Home Additions',
    ];
@endphp
<section class="bg-zinc-50 py-12 dark:bg-zinc-800/50" aria-label="Remodeling services in {{ $area->city }}">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
            Remodeling Services in {{ $area->city }}
        </h2>
        <p class="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
            Explore our {{ $area->city }} remodeling services — each with local project
            examples, scope, and clear pricing guidance.
        </p>
        <div class="mt-5 flex flex-wrap gap-3">
            @foreach($areaServiceLinks as $slug => $label)
                <a href="{{ $area->serviceUrl($slug) }}" wire:navigate
                   class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-zinc-700 shadow-sm hover:bg-sky-50 hover:text-sky-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-700">
                    {{ $area->city }} {{ $label }}
                </a>
            @endforeach
        </div>
    </div>
</section>
