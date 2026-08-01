{{-- Per-town review quotes: real reviews from this town when we have them,
     otherwise reviews from the nearest served towns — always labeled with the
     reviewer's actual town, never passed off as local. Computed up front and
     rendered flat. Expects $area (AreaServed). --}}
@php
    $wanted = 3;

    $localQuotes = $area->localTestimonials($wanted);

    // Shared with <x-city-reviews-badge>, so the badge's count can never
    // disagree with the number of cards rendered below it.
    $townQuotes = $area->testimonialsWithNeighbours($wanted);

    // "in this town" only when every quote genuinely is. A mixed set says
    // "near" — each card still carries the reviewer's real town underneath.
    $quotesAreLocal = $localQuotes->isNotEmpty() && $localQuotes->count() === $townQuotes->count();

    $quotesHeading = $quotesAreLocal
        ? "What {$area->city} homeowners say"
        : "What homeowners near {$area->city} say";
@endphp
@if($townQuotes->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8" aria-label="Homeowner reviews near {{ $area->city }}">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $quotesHeading }}</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-3">
            @foreach($townQuotes as $quote)
                {{-- Same hover as every other card on the site: shadow-md lifting to
                     shadow-xl. This one had the base shadow and ring but no
                     transition or hover state at all. --}}
                {{-- The whole card is the link. <a> wrapping <figure> is valid
                     flow content, and it means the click target is the card
                     rather than a small "read more" — the hover lift already
                     implies the card is interactive. --}}
                <a href="{{ route('reviews.show', $quote->slug) }}" wire:navigate
                   class="group flex flex-col rounded-2xl bg-white p-5 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <figure class="flex flex-1 flex-col">
                    @php $stars = (int) ($quote->star_rating ?? 5); @endphp
                    @if($stars === 5)
                        {{-- Brand 5-star mark, matching the testimonials carousel. --}}
                        <img src="{{ asset('images/5-stars.svg') }}" alt="Rated 5 out of 5" class="h-6 w-auto self-start dark:hidden" />
                        <img src="{{ asset('images/5-stars-dark.svg') }}" alt="Rated 5 out of 5" class="hidden h-6 w-auto self-start dark:block" />
                    @else
                        {{-- The asset is a fixed FIVE-star graphic, so anything
                             lower must still draw individually — using it for a
                             4-star review would overstate what the customer
                             actually gave. Currently unreachable (both sub-5
                             reviews are hidden), kept so it stays correct if one
                             is ever unhidden. --}}
                        <div class="flex items-center gap-0.5 self-start text-amber-400" aria-label="{{ $stars }} out of 5 stars">
                            @foreach(range(1, max(1, $stars)) as $star)
                                <svg class="size-6 fill-current" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.28 3.95a1 1 0 0 0 .95.69h4.15c.97 0 1.37 1.24.59 1.81l-3.36 2.44a1 1 0 0 0-.36 1.12l1.28 3.95c.3.92-.75 1.69-1.54 1.12l-3.36-2.44a1 1 0 0 0-1.17 0l-3.36 2.44c-.78.57-1.84-.2-1.54-1.12l1.28-3.95a1 1 0 0 0-.36-1.12L2.08 9.38c-.78-.57-.38-1.81.6-1.81h4.14a1 1 0 0 0 .95-.69l1.28-3.95Z"/></svg>
                            @endforeach
                        </div>
                    @endif
                    <blockquote class="mt-3 flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                        &ldquo;{{ \Illuminate\Support\Str::limit(trim((string) $quote->review_description), 240) }}&rdquo;
                    </blockquote>
                    <figcaption class="mt-4 text-sm">
                        <span class="font-medium text-zinc-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-400">{{ $quote->display_name }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400"> — {{ $quote->project_location }}</span>
                    </figcaption>
                    </figure>
                </a>
            @endforeach
        </div>
        <p class="mt-4 text-sm">
            <a href="{{ route('areas.page', [$area, 'testimonials']) }}" wire:navigate class="font-medium text-sky-700 hover:text-sky-800 dark:text-sky-400 dark:hover:text-sky-300">
                Read more homeowner reviews near {{ $area->city }} &rarr;
            </a>
        </p>
    </section>
@endif
