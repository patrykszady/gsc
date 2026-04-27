@php
    use Artesaos\SEOTools\Facades\SEOMeta;
    use Artesaos\SEOTools\Facades\OpenGraph;
    use Artesaos\SEOTools\Facades\TwitterCard;

    SEOMeta::setTitle('Page Not Found');
    SEOMeta::setDescription('Sorry, we couldn\'t find that page. Browse our remodeling services, projects, and service areas in the Chicago suburbs.');
    SEOMeta::setRobots('noindex,follow');
    SEOMeta::setCanonical(url('/'));
    OpenGraph::setTitle('Page Not Found | GS Construction');
    OpenGraph::setDescription('Sorry, we couldn\'t find that page.');
    OpenGraph::setUrl(url()->current());
    TwitterCard::setTitle('Page Not Found | GS Construction');

    $popularAreas = \App\Models\AreaServed::orderBy('city')->limit(12)->get();
@endphp

<x-layouts.app>
    <div class="bg-white dark:bg-zinc-900">
        <div class="mx-auto max-w-3xl px-6 py-20 sm:py-28 lg:px-8 text-center">
            <p class="text-sm font-semibold uppercase tracking-widest text-sky-600 dark:text-sky-400">404</p>
            <h1 class="mt-3 text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                We can't find that page
            </h1>
            <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-300">
                The page you're looking for may have moved or no longer exists. Try one of these popular destinations instead:
            </p>

            <div class="mt-10 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <a href="{{ route('services.kitchen') }}" wire:navigate
                   class="rounded-xl border border-zinc-200 bg-white px-5 py-4 text-base font-semibold text-zinc-800 shadow-sm hover:bg-sky-50 hover:border-sky-300 dark:bg-zinc-800 dark:text-white dark:border-zinc-700 dark:hover:bg-zinc-700">
                    Kitchen Remodeling
                </a>
                <a href="{{ route('services.bathroom') }}" wire:navigate
                   class="rounded-xl border border-zinc-200 bg-white px-5 py-4 text-base font-semibold text-zinc-800 shadow-sm hover:bg-sky-50 hover:border-sky-300 dark:bg-zinc-800 dark:text-white dark:border-zinc-700 dark:hover:bg-zinc-700">
                    Bathroom Remodeling
                </a>
                <a href="{{ route('services.home') }}" wire:navigate
                   class="rounded-xl border border-zinc-200 bg-white px-5 py-4 text-base font-semibold text-zinc-800 shadow-sm hover:bg-sky-50 hover:border-sky-300 dark:bg-zinc-800 dark:text-white dark:border-zinc-700 dark:hover:bg-zinc-700">
                    Home Remodeling
                </a>
            </div>

            <div class="mt-10 flex flex-wrap justify-center gap-3">
                <a href="{{ route('home') }}" wire:navigate
                   class="rounded-lg bg-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
                    Go Home
                </a>
                <a href="{{ route('projects.index') }}" wire:navigate
                   class="rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-zinc-800 ring-1 ring-inset ring-zinc-300 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-white dark:ring-zinc-700 dark:hover:bg-zinc-700">
                    Browse Projects
                </a>
                <a href="{{ route('testimonials.index') }}" wire:navigate
                   class="rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-zinc-800 ring-1 ring-inset ring-zinc-300 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-white dark:ring-zinc-700 dark:hover:bg-zinc-700">
                    Read Reviews
                </a>
                <a href="{{ route('contact') }}" wire:navigate
                   class="rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-zinc-800 ring-1 ring-inset ring-zinc-300 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-white dark:ring-zinc-700 dark:hover:bg-zinc-700">
                    Contact Us
                </a>
            </div>

            @if($popularAreas->count())
                <div class="mt-14">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Service Areas</h2>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        @foreach($popularAreas as $a)
                            <a href="{{ $a->url }}" wire:navigate
                               class="inline-flex items-center rounded-full bg-zinc-100 px-3 py-1 text-sm text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                {{ $a->city }}
                            </a>
                        @endforeach
                        <a href="{{ route('areas.index') }}" wire:navigate
                           class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-sm font-semibold text-sky-800 hover:bg-sky-200 dark:bg-sky-900/40 dark:text-sky-300 dark:hover:bg-sky-900/60">
                            See all areas →
                        </a>
                    </div>
                </div>
            @endif

            <p class="mt-12 text-sm text-zinc-500 dark:text-zinc-400">
                Need help? Call <a href="tel:+12247354200" class="font-semibold text-sky-600 hover:underline dark:text-sky-400">(224) 735-4200</a>
                — we'll point you in the right direction.
            </p>
        </div>
    </div>
</x-layouts.app>
