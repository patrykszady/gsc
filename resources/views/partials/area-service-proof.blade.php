{{--
    The town's OWN proof for THIS service, rendered above the generated copy
    (partials/area-service-content) rather than after it (2026-10-10):

      (a) this town's published projects of this trade — falling back to this
          town's other projects, and then to a neighbouring town's projects of
          this trade when this town has none at all, each card honestly
          labeled with the town the job actually happened in (x-project-card's
          own :town badge).
      (b) this town's own reviews that actually mention this trade (by
          project_type tag or by naming it — AreaSeoPolicy::DEMAND_KEYWORDS),
          falling back to this town's other reviews; cached on the model.
      (c) one whole sentence of fact the town already has — its permit rule,
          else the lead of its own copy — no new hand-written copy.
      (d) links to this town's sibling service spokes and back to its hub.

    Nothing here replaces the generated copy below it; this only adds real,
    town-specific proof ahead of it.

    Variables: $area (AreaServed), $config (the service page's config array
    — 'urlSlug', 'projectType', 'label').
--}}
@php
    $proofService = $config['urlSlug'];
    $proofType = $config['projectType'] ?? null;
    $proofLine = \App\Models\AreaServiceContent::label($proofService);

    // (a) Town's own work in this trade, then the town's other work, then —
    // only when the town has no projects of its own at all — a neighbouring
    // town's work in this trade (AreaServed::nearbyProjects() $projectType
    // param). presentFor($proofType) in x-project-card is safe either way:
    // a project that does not belong to $proofType (directly or through an
    // area) simply presents as itself, badge and all — never mislabeled.
    $proofProjects = $area->localProjects(3, $proofType);
    $proofProjectsMatchType = $proofProjects->isNotEmpty();
    $proofProjectsAreLocal = true;
    if ($proofProjects->isEmpty()) {
        $proofProjects = $area->localProjects(3);
    }
    if ($proofProjects->isEmpty()) {
        $proofProjects = $area->nearbyProjects(3, $proofType);
        $proofProjectsAreLocal = false;
    }

    $proofProjectsHeading = match (true) {
        $proofProjectsAreLocal && $proofProjectsMatchType => ucfirst($proofLine)." we've completed in {$area->city}, IL",
        $proofProjectsAreLocal => "Remodeling projects we've completed in {$area->city}, IL",
        default => ucfirst($proofLine)." we've completed near {$area->city}, IL",
    };

    // (b) This town's own reviews about this trade, else its other reviews.
    // Both come from the model's cached helpers (one query at most, 6h):
    // AreaServed::localTestimonialsAbout() / localTestimonials().
    $proofMatchedReviews = $area->localTestimonialsAbout($proofService, 3);
    $proofReviewsAreMatched = $proofMatchedReviews->isNotEmpty();
    $proofReviews = $proofReviewsAreMatched ? $proofMatchedReviews : $area->localTestimonials(3);

    // (c) One whole sentence of fact the town already has — its permit rule
    // first, else the lead of its own copy (AreaServed::serviceProofFact()).
    $proofFact = $area->serviceProofFact();
@endphp
@if($proofProjects->isNotEmpty() || $proofReviews->isNotEmpty() || $proofFact)
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" aria-label="{{ $area->city }} {{ $proofLine }} proof">

        @if($proofFact)
            <p class="rounded-lg bg-sky-50 px-6 py-4 text-sm leading-6 text-zinc-700 dark:bg-sky-900/20 dark:text-zinc-300">
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $proofFact['label'] }}</span>
                {{ $proofFact['text'] }}
            </p>
        @endif

        @if($proofProjects->isNotEmpty())
            <div class="{{ $proofFact ? 'mt-6' : '' }}">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $proofProjectsHeading }}</h2>
                <x-project-grid
                    :projects="$proofProjects"
                    :towns="! $proofProjectsAreLocal"
                    :type="$proofType"
                    class="mt-5" />
            </div>
        @endif

        @if($proofReviews->isNotEmpty())
            <div class="mt-8">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    {{ $proofReviewsAreMatched ? "What {$area->city} homeowners say about our {$proofLine}" : "What {$area->city} homeowners say" }}
                </h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    @foreach($proofReviews as $review)
                        <a href="{{ route('reviews.show', $review->slug) }}" wire:navigate
                           class="group flex flex-col rounded-2xl bg-white p-5 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                            <figure class="flex flex-1 flex-col">
                                @php $proofStars = (int) ($review->star_rating ?? 5); @endphp
                                @if($proofStars === 5)
                                    <x-five-stars size="h-6" class="self-start" />
                                @else
                                    {{-- Honesty rule on x-five-stars: it may only render a genuine
                                         5/5. Anything lower draws its own stars. --}}
                                    <div class="flex items-center gap-0.5 self-start text-amber-400" aria-label="{{ $proofStars }} out of 5 stars">
                                        @foreach(range(1, max(1, $proofStars)) as $star)
                                            <svg class="size-6 fill-current" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.28 3.95a1 1 0 0 0 .95.69h4.15c.97 0 1.37 1.24.59 1.81l-3.36 2.44a1 1 0 0 0-.36 1.12l1.28 3.95c.3.92-.75 1.69-1.54 1.12l-3.36-2.44a1 1 0 0 0-1.17 0l-3.36 2.44c-.78.57-1.84-.2-1.54-1.12l1.28-3.95a1 1 0 0 0-.36-1.12L2.08 9.38c-.78-.57-.38-1.81.6-1.81h4.14a1 1 0 0 0 .95-.69l1.28-3.95Z"/></svg>
                                        @endforeach
                                    </div>
                                @endif
                                <blockquote class="mt-3 flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                                    &ldquo;{{ \Illuminate\Support\Str::limit(trim((string) $review->review_description), 240) }}&rdquo;
                                </blockquote>
                                <figcaption class="mt-4 text-sm">
                                    <span class="font-medium text-zinc-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-400">{{ $review->display_name }}</span>
                                    <span class="text-zinc-500 dark:text-zinc-400"> — {{ $review->project_location }}</span>
                                </figcaption>
                            </figure>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- (d) Back to the hub, then the sibling service spokes. --}}
        <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <p class="text-sm">
                <a href="{{ $area->url }}" wire:navigate class="font-medium text-sky-700 hover:text-sky-800 dark:text-sky-400 dark:hover:text-sky-300">
                    &larr; {{ $area->city }} remodeling overview
                </a>
            </p>
            <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Other {{ $area->city }} services
            </h3>
            <x-service-chips :area="$area" :exclude="$proofService" class="mt-3" />
        </div>
    </section>
@endif
