{{--
    Market page — one per studio metro (/chicago, /atlanta, /south-haven).

    Driven entirely by config/sites/jpeterson/markets.php; this view holds no
    market data of its own. The route (routes/web.php) and the tenant claim
    (config/sites.php) are built from that same file.

    Blurbs are working copy for Jenn's approval, like the rest of the theme.
--}}
@php
    $others = collect(config('markets.list', []))->where('slug', '!=', $market['slug']);

    // Minimal LocalBusiness schema: the studio, scoped to this market's city.
    // Built as an array so json_encode owns the escaping.
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'ProfessionalService',
        'name' => config('brand.display_name'),
        'description' => $market['blurb'],
        'url' => config('geo.site_url') . '/' . $market['slug'],
        'areaServed' => [
            '@type' => 'City',
            'name' => $market['city'],
            'address' => ['@type' => 'PostalAddress', 'addressRegion' => $market['state']],
        ],
        'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $market['lat'], 'longitude' => $market['lng']],
    ];
@endphp

<x-layouts.app
    :title="'Interior Design in ' . $market['city'] . ', ' . $market['state'] . ' — ' . config('brand.display_name')"
    :description="$market['blurb']">

    <section class="mx-auto max-w-6xl px-6 pt-10 pb-4">
        <p class="text-xs tracking-[0.3em] text-stone-400 uppercase">
            {{ $market['office'] ? 'Studio office' : 'Studio market' }}
        </p>
        <h1 class="mt-3 max-w-3xl font-heading text-4xl text-ink sm:text-5xl">
            Interior design in {{ $market['city'] }}
        </h1>
        <p class="mt-5 max-w-xl leading-relaxed text-stone-600">{{ $market['blurb'] }}</p>

        <div class="mt-8 flex flex-wrap gap-x-12 gap-y-4 border-t border-stone-200 pt-6">
            <div>
                <p class="text-xs tracking-[0.18em] text-stone-400 uppercase">Led by</p>
                <p class="mt-1.5 text-sm text-stone-700">{{ $market['lead'] }}</p>
            </div>
            <div>
                <p class="text-xs tracking-[0.18em] text-stone-400 uppercase">Serving</p>
                <p class="mt-1.5 text-sm text-stone-700">{{ $market['region'] }}</p>
            </div>
            <div>
                <p class="text-xs tracking-[0.18em] text-stone-400 uppercase">Since</p>
                <p class="mt-1.5 text-sm text-stone-700">2008</p>
            </div>
        </div>
    </section>

    {{-- TODO: swap for projects from this market once Jenn supplies imagery. --}}
    <section class="mx-auto max-w-6xl px-6 py-12">
        <x-hero-carousel
            :slides="\App\Support\HeroSlides::placeholders([
                $market['city'] . ' project photography',
                $market['city'] . ' interior detail',
                $market['city'] . ' finished space',
            ])"
            container-classes=""
            rounded-classes="rounded-sm border border-stone-200"
            height-classes="aspect-16/7"
        />
    </section>

    <section class="mx-auto max-w-6xl px-6 py-10">
        <p class="text-xs tracking-[0.3em] text-stone-400 uppercase">The same studio, wherever you are</p>
        <div class="mt-6 grid gap-x-14 gap-y-8 sm:grid-cols-2">
            <p class="leading-relaxed text-stone-600">
                Every market runs on the same six-stage process and the same two sets of
                eyes &mdash; {{ $market['lead'] }} leads your project here, and nothing is
                handed off between the first conversation and the final walkthrough.
            </p>
            <div class="flex flex-col items-start gap-4">
                <x-button href="/process" variant="outline" size="sm">See the process</x-button>
                @if ($others->isNotEmpty())
                    <p class="text-sm text-stone-500">
                        Also serving
                        @foreach ($others as $other)
                            <a href="/{{ $other['slug'] }}" wire:navigate class="text-brand-700 underline underline-offset-4 hover:text-brand-800">{{ $other['label'] }}</a>{{ ! $loop->last ? ' and ' : '' }}
                        @endforeach
                    </p>
                @endif
            </div>
        </div>
    </section>

    <x-cta heading="Planning a project in {{ $market['city'] }}?" label="Start the conversation">
        Tell us about the space and where it is &mdash; {{ $market['lead'] }} takes it from there.
    </x-cta>

    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</x-layouts.app>
