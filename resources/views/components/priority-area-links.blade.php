@php
    // Internal-link boost for striking-distance pages. The list is computed
    // daily by the RecommendationEngine (seo/priority-pages.json): area pages
    // ranking 8–20 with real search demand. Rendering their links from
    // high-authority pages is the automated version of "deepen internal links
    // on these first". Renders nothing when the file is absent.
    $priorityLinks = \Illuminate\Support\Facades\Cache::remember('priority_area_links', 3600, function () {
        try {
            if (! \Illuminate\Support\Facades\Storage::disk('local')->exists('seo/priority-pages.json')) {
                return [];
            }
            $decoded = json_decode((string) \Illuminate\Support\Facades\Storage::disk('local')->get('seo/priority-pages.json'), true);

            return is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        } catch (\Throwable $e) {
            return [];
        }
    });
@endphp

@if(! empty($priorityLinks))
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            In-demand service areas this month
        </h2>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach($priorityLinks as $link)
                <a href="{{ $link['path'] }}"
                   wire:navigate
                   class="rounded-full border border-zinc-200 bg-white px-3.5 py-1.5 text-sm font-medium text-zinc-700 transition hover:border-sky-300 hover:text-sky-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-sky-600 dark:hover:text-sky-400">
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>
    </section>
@endif
