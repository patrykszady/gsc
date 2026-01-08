@props([
    'area' => null,
    'showSubtitle' => false,
])

<div class="mx-auto max-w-2xl text-center">
    <p class="text-sm font-semibold uppercase tracking-widest text-sky-600 dark:text-sky-400">Testimonials</p>
    <h2 class="mt-2 font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100">
        Your Neighbours{{ $area ? ' in ' . $area->city : '' }} Love Us
    </h2>
    @if($showSubtitle)
    <p class="mt-2 text-lg text-zinc-600 dark:text-zinc-300">
        Real reviews from homeowners. Ask us for live phone references.
    </p>
    @endif
</div>
