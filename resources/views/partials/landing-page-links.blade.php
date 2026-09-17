{{--
    Links to the in-depth landing pages (/remodeling/…): six pages of
    9,000 words each that nothing on the site linked to, so Google had them
    as "Discovered, currently not indexed" (2026-09-17). A trade page lists
    its own; a town page lists its town's.
    Variables: $pages (Collection of LandingPage), $heading (string).
--}}
@php $guidePages = collect($pages ?? [])->filter(fn ($p) => $p->status === \App\Models\LandingPage::STATUS_PUBLISHED); @endphp
@if ($guidePages->isNotEmpty())
    <section class="mx-auto max-w-7xl px-6 py-8 lg:px-8" aria-label="{{ $heading }}" data-landing-page-links>
        <h2 class="font-heading text-xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ $heading }}</h2>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($guidePages as $guide)
                <li>
                    <a href="{{ $guide->url() }}" wire:navigate class="group block rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-sky-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800/75">
                        <span class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">{{ $guide->city ?: 'Chicago suburbs' }}</span>
                        <span class="mt-1 block font-semibold text-zinc-900 group-hover:text-sky-700 dark:text-white">{{ $guide->h1 ?: $guide->title }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
