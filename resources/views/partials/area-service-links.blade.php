{{--
    Reusable city-service spoke link block.
    Surfaces the 5 valid area service spoke pages
    (/areas-served/{city}/services/{slug}) on area sub-pages
    (testimonials, projects, about) so every area page has direct internal
    links into the service spokes — improves crawl reach + local relevance.

    Required:
      $area — App\Models\AreaServed
--}}
<section class="bg-zinc-50 py-12 dark:bg-zinc-800/50" aria-label="Remodeling services in {{ $area->city }}">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
            Remodeling Services in {{ $area->city }}
        </h2>
        <p class="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
            Explore our {{ $area->city }} remodeling services — each with local project
            examples, scope, and clear pricing guidance.
        </p>
        <x-service-chips :area="$area" class="mt-5" />
    </div>
</section>
