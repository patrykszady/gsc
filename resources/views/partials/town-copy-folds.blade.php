{{--
    The accordion a town's long copy sits behind: "How {city} was built",
    "What that means for remodeling in {city}", and on the town page the
    permit notes. Same card and rules as the FAQ (<x-faq-card>, faq-section)
    so every folded block on the site looks the same. Folded text stays in
    the DOM (hidden="until-found") for Google and find-in-page.

    Variables: $folds — list<array{key: string, heading: string, body: string}>
--}}
@if(($folds ?? []) !== [])
    <div class="mt-5 overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900/70">
        @foreach($folds as $fold)
            <div x-data="{ open: false }" class="border-b border-zinc-200/80 last:border-b-0 dark:border-white/10">
                <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between gap-4 px-5 py-3 text-left sm:px-6">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $fold['heading'] }}</h3>
                    <span class="ml-6 flex h-7 items-center text-zinc-900 dark:text-white">
                        <svg class="size-6 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </span>
                </button>
                {{-- hidden="until-found" is content-visibility: hidden — the element's own
                     padding and border still take up room, so they sit on the inner div
                     and a collapsed item has no blank band under its heading. --}}
                <div x-bind:hidden="open ? false : 'until-found'" x-on:beforematch="open = true" hidden="until-found">
                    <div class="space-y-3 border-t border-zinc-200/80 px-5 py-4 text-base leading-7 text-zinc-600 sm:px-6 dark:border-white/10 dark:text-zinc-400">
                        @foreach(preg_split('/\n\s*\n/', trim($fold['body'])) as $paragraph)
                            <p>{{ trim($paragraph) }}</p>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
