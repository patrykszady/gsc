{{--
    The copy one service page carries in one town, written for that pair
    (AreaServiceContent). The town's own description, neighborhoods and
    landmarks stay on the town page; here only a two-sentence teaser links
    to it. Before a pair has its copy, the old shared block renders so the
    page never goes thin.
    Variables: $area (AreaServed), $config (the service page's config array).
--}}
@php
    $serviceCopy = $area->serviceContent($config['urlSlug']);
    $heading = match ($config['urlSlug']) {
        'kitchen-remodeling'  => "Kitchen remodeling in {$area->city}, IL",
        'bathroom-remodeling' => "Bathroom remodeling in {$area->city}, IL",
        'home-remodeling'     => "Whole-home remodeling in {$area->city}, IL",
        'basement-remodeling' => "Basement finishing in {$area->city}, IL",
        'home-additions'      => "Home additions in {$area->city}, IL",
        default               => "{$config['label']} in {$area->city}, IL",
    };
    $teaser = collect(preg_split('/(?<=[.!?])\s+/', trim((string) $area->local_intro)) ?: [])->take(2)->implode(' ');
@endphp

@if ($serviceCopy)
    <section class="bg-white py-12 sm:py-16 dark:bg-zinc-900" data-area-service-content>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ $heading }}</h2>
                <div class="mt-4 space-y-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
                    @foreach (preg_split('/\n{2,}/', trim($serviceCopy->intro)) ?: [] as $paragraph)
                        <p>{{ trim($paragraph) }}</p>
                    @endforeach
                </div>

                @if (filled($serviceCopy->popular_requests))
                    <h3 class="mt-8 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">What {{ $area->city }} homeowners ask for</h3>
                    <p class="mt-2 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $serviceCopy->popular_requests }}</p>
                @endif

                @if (filled($serviceCopy->permit_notes))
                    <h3 class="mt-8 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Permits for {{ \App\Models\AreaServiceContent::label($config['urlSlug']) }} in {{ $area->city }}</h3>
                    <p class="mt-2 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $serviceCopy->permit_notes }}</p>
                @endif

                @if ($teaser !== '')
                    <div class="mt-8 rounded-lg border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">About {{ $area->city }}</h3>
                        <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $teaser }}</p>
                        <a href="{{ url('/areas-served/'.$area->slug) }}" class="mt-3 inline-block text-sm font-semibold text-sky-600 hover:text-sky-700 dark:text-sky-400">
                            More about our work in {{ $area->city }} →
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </section>
@else
    @include('partials.area-unique-content', ['area' => $area, 'context' => $config['urlSlug']])
@endif
