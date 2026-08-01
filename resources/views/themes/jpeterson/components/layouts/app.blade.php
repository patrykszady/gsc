<!DOCTYPE html>
<html lang="en">
{{-- J. Peterson Design layout. Overrides components/layouts/app for this tenant
     only. Deliberately the opposite of the other two sites: light, editorial,
     generous whitespace, serif display type — an interior-design aesthetic, not
     a contractor or a software one. --}}
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <title>{{ $title ?? config('brand.display_name') . ' — Interior Design' }}</title>
    <meta name="description" content="{{ $description ?? config('geo.site_description') }}" />
    <link rel="canonical" href="{{ config('geo.site_url') }}{{ request()->getPathInfo() === '/' ? '' : request()->getPathInfo() }}" />
    <meta property="og:site_name" content="{{ config('brand.display_name') }}" />
    <meta property="og:title" content="{{ $title ?? config('brand.display_name') }}" />
    <meta property="og:description" content="{{ $description ?? config('geo.site_description') }}" />

    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%23f5f3ef'/%3E%3Ctext x='50' y='66' font-size='46' font-family='Georgia,serif' text-anchor='middle' fill='%233f3a34'%3EJP%3C/text%3E%3C/svg%3E" />

    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-wght-normal.woff2') }}" crossorigin>
    @vite(\App\Support\Theme::viteEntries(\App\Models\Site::current()))
    @fluxAppearance

    {{-- Page transition for wire:navigate. Links carry wire:navigate, so
         Livewire fetches the next page and swaps the body without a full
         reload; this fades the incoming content in so the swap does not read
         as a jump-cut.

         In <head> on purpose: Livewire dedupes head assets across navigation,
         so the listener is registered once instead of stacking up on every
         page change.

         Honours prefers-reduced-motion — vestibular triggers are a real
         accessibility concern, and a page transition is pure decoration. --}}
    <style>
        @media (prefers-reduced-motion: no-preference) {
            @keyframes jp-page-in {
                from { opacity: 0; transform: translateY(8px); }
                to   { opacity: 1; transform: none; }
            }
            [data-page-enter] { animation: jp-page-in .3s cubic-bezier(.22,.61,.36,1) both; }
            /* Dim slightly while the next page is in flight, so a slow
               response still feels like a response. */
            body.jp-leaving [data-page-enter] { opacity: .55; transition: opacity .18s ease-out; }
        }
    </style>
    <script data-navigate-once>
        document.addEventListener('livewire:navigate', () => document.body.classList.add('jp-leaving'));
        document.addEventListener('livewire:navigated', () => {
            document.body.classList.remove('jp-leaving');
            const page = document.querySelector('[data-page-enter]');
            if (! page) return;
            // Restart the animation: the element may be morphed rather than
            // replaced, in which case CSS alone would not re-run it.
            page.style.animation = 'none';
            void page.offsetWidth;
            page.style.animation = '';
        });
    </script>
</head>
<body class="min-h-screen bg-[#faf8f5] font-sans text-stone-700 antialiased">

    {{-- ---------- header ---------- --}}
    <header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-stone-200/70 bg-[#faf8f5]/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
            {{-- Wordmark in the logo teal; "DESIGN" sits muted beneath it in the
                 real mark, so the descender line here stays neutral. --}}
            <a href="/" wire:navigate class="font-heading text-lg tracking-[0.2em] text-brand-700 uppercase transition hover:text-brand-800">
                {{ config('brand.display_name') }}
            </a>

            {{-- This theme owns how the nav looks; config/sites/jpeterson/nav.php
                 owns which links exist. Keeping the link set in config is what
                 lets `php artisan sites:check` and /_sites verify that none of
                 them 404 on this tenant — a list buried in a layout cannot be
                 validated. --}}
            <nav class="hidden items-center gap-9 text-sm tracking-wide text-stone-600 md:flex">
                @foreach (config('nav.links', []) as $link)
                    @if ($link['cta'] ?? false)
                        <x-button :href="$link['href']" variant="outline" size="sm">{{ $link['label'] }}</x-button>
                    @else
                        <a href="{{ $link['href'] }}" wire:navigate class="transition hover:text-brand-700">{{ $link['label'] }}</a>
                    @endif
                @endforeach
            </nav>

            <button @click="open = !open" class="md:hidden" aria-label="Menu">
                <svg class="size-6 text-stone-800" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                </svg>
            </button>
        </div>

        <nav x-show="open" x-cloak class="border-t border-stone-200/70 px-6 py-4 md:hidden">
            @foreach (config('nav.links', []) as $link)
                <a href="{{ $link['href'] }}" wire:navigate class="block py-2.5 text-sm tracking-wide text-stone-700">{{ $link['label'] }}</a>
            @endforeach
        </nav>
    </header>

    <main data-page-enter>
        {{ $slot }}
    </main>

    {{-- ---------- footer ---------- --}}
    <footer class="mt-24 border-t-2 border-brand-500/40">
        <div class="mx-auto grid max-w-6xl gap-10 px-6 py-14 sm:grid-cols-3">
            <div>
                <p class="font-heading text-base tracking-[0.2em] text-brand-700 uppercase">{{ config('brand.display_name') }}</p>
                <p class="mt-3 text-sm leading-relaxed text-stone-500">{{ config('geo.site_description') }}</p>
            </div>
            <div>
                <p class="text-xs tracking-[0.18em] text-stone-400 uppercase">Studio</p>
                <ul class="mt-3 space-y-1.5 text-sm text-stone-600">
                    @foreach (config('markets.list', []) as $market)
                        <li><a href="/{{ $market['slug'] }}" wire:navigate class="transition hover:text-brand-700">{{ $market['label'] }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <p class="text-xs tracking-[0.18em] text-stone-400 uppercase">Contact</p>
                <ul class="mt-3 space-y-1.5 text-sm text-stone-600">
                    @if (config('brand.email'))
                        <li><a href="mailto:{{ config('brand.email') }}" class="transition hover:text-brand-700">{{ config('brand.email') }}</a></li>
                    @endif
                    @if (config('brand.phone'))
                        <li><a href="tel:{{ config('brand.phone_href') }}" class="transition hover:text-brand-700">{{ config('brand.phone') }}</a></li>
                    @endif
                </ul>
            </div>
        </div>
        <div class="border-t border-stone-200/70 py-6 text-center text-xs text-stone-400">
            &copy; {{ now()->year }} {{ config('brand.legal_name') }}
        </div>
    </footer>

    @fluxScripts
</body>
</html>
