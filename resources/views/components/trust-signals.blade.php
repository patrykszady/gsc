{{--
    The four trust tiles: Family-owned / Combined experience / Verified
    reviews / Licensed & insured.

    Was written out twice — how-to-choose.blade.php (plain tiles) and
    compare-competitor-page.blade.php (with a linked reviews tile) — with
    identical tile chrome. One component; the reviews tile always links,
    which how-to-choose simply gained.

    The review count comes from CompanyStats::reviewsTotal() here rather
    than from each caller, so the tile can never disagree with the rest of
    the site.

    Props
      eyebrow  string|null — the small brand line the compare pages print
                             above the tiles.
--}}

@props([
    'eyebrow' => null,
])

@php $trustReviewCount = \App\Support\CompanyStats::reviewsTotal(); @endphp

<div {{ $attributes->class('mx-auto max-w-5xl px-6 lg:px-8') }}>
    @if($eyebrow)
        <p class="mb-4 text-center text-sm font-bold uppercase tracking-widest text-sky-600 dark:text-sky-400">
            {{ $eyebrow }}
        </p>
    @endif

    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Family-owned</dt>
            <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">Father &amp; Son</dd>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Combined experience</dt>
            <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">40+ Years</dd>
        </div>

        {{-- Whole card links to the reviews page. The anchor lives inside the
             <dd> and stretches over the card, so the dl/dt/dd structure stays
             valid for screen readers while the full tile stays clickable. --}}
        <div class="group relative rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Verified reviews</dt>
            <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 transition group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-400">
                {{ $trustReviewCount }}+
                <a href="{{ route('reviews.index') }}" wire:navigate
                   class="absolute inset-0 rounded-xl focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-2"
                   aria-label="Read all {{ $trustReviewCount }}+ verified reviews"></a>
            </dd>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Licensed &amp; insured</dt>
            <dd class="mt-0.5 font-heading text-lg font-bold text-zinc-900 dark:text-white">Yes</dd>
        </div>
    </dl>
</div>
