{{-- Comparison cross-link.

     Shared by /about and the area about pages. Its whole job is to stop
     /compare and its 11 competitor pages being orphaned — a real link from a
     crawled, authoritative page is what lets them be discovered and cited.
     Linking from ~70 area pages as well multiplies that considerably.

     Takes no variables. --}}
        {{-- Internal link into the comparison content so it isn't orphaned:
             a real link from a crawled, authoritative page lets /compare (and its
             11 competitor pages) be discovered, gain authority, and be AI-cited. --}}
        {{-- mt-16 matches the other about partials. This block had no top margin
     at all, so it sat flush against whatever preceded it — visible as the
     values grid and this card touching. --}}
        <div class="mx-auto mt-16 max-w-7xl px-4 pb-4 sm:px-6 lg:px-8">
            <div class="group rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-800/40 sm:p-8 shadow-sm transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">How we compare to other Chicago remodelers</h2>
                <p class="mt-2 max-w-3xl text-zinc-600 dark:text-zinc-300">
                    Getting other quotes? We keep factual, side-by-side comparisons with the region's
                    larger design-build firms — service area, approach, materials and communication —
                    plus a plain-English guide to choosing a contractor, so you can decide with clear information.
                </p>
                <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2">
                    <a href="{{ route('compare.index') }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-semibold text-sky-700 hover:underline dark:text-sky-400">
                        See how GS Construction compares →
                    </a>
                    <a href="{{ url('/how-to-choose-a-remodeling-contractor') }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-semibold text-sky-700 hover:underline dark:text-sky-400">
                        How to choose a remodeling contractor →
                    </a>
                </div>
            </div>
        </div>
