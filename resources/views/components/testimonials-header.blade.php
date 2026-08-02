@blaze(fold: true)
@props([
    'area' => null,
    'showSubtitle' => false,
    // Off by default. The graphic is a FIVE-star image, so it may only render
    // where the reviews behind it really are five stars — see the caller.
    'showStars' => false,
])

<div class="mx-auto max-w-2xl text-center">
    <p class="text-sm font-semibold uppercase tracking-widest text-sky-600 dark:text-sky-400">Testimonials</p>
    <h2 class="mt-2 font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100">
        Your Neighbours{{ $area ? ' in ' . $area->city : '' }} Love Us
    </h2>
    @if($showStars)
        {{-- Same asset and light/dark pairing as <x-city-reviews-badge>, so the
             star treatment is identical wherever it appears. --}}
        <div class="mt-4 flex justify-center" role="img"
             aria-label="{{ \App\Support\CompanyStats::reviewsTotal() }} verified five-star reviews">
            <x-five-stars size="h-6" label="" />
        </div>
    @endif

    @if($showSubtitle)
    <p class="mt-2 text-lg text-zinc-600 dark:text-zinc-300">
        Real reviews from homeowners. Ask us for live phone references.
    </p>
    @endif
</div>
