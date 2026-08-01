{{-- City intro: project slider on the LEFT, city copy on the RIGHT.

     Shared by the area landing page and every /areas-served/{city}/services/*
     and /contact page. The service pages previously used a separate
     single-column block (partials/area-unique-content) with no slider, so the
     two looked like different sites.

     Variables
       $area        AreaServed (required)
       $heading     string — the H2. Varies per (city, service) on purpose: the
                    H2 is heavily weighted in Google's near-duplicate detection,
                    and these pages exist across ~70 towns.
       $serviceLine string — "bathroom remodels", "remodeling projects" — woven
                    through the aria-label, landmark and permit sub-headings.

     Guard the include with hasUniqueContent()/landmarks/permit_notes — with
     none of those there is nothing to say about the town. --}}
@php
    $heading ??= "Remodeling in {$area->city}, IL";
    $serviceLine ??= 'remodeling projects';

    // Project::project_type slug. Set on a service page so the slider shows
    // THAT trade's work — a bathroom page proving itself with kitchen photos
    // is worse than showing nothing.
    $projectType ??= null;
@endphp
@php
    // Prefer genuinely local project photos (projects whose location
    // resolves to this city) — a strong "real local work" signal for
    // both Google and AI Overviews. Fall back to cover photos of our
    // featured projects when this city has no matched projects yet.
    $localSliderImages = $area->localProjectImages(6, $projectType);
    $citySliderImages = $localSliderImages;

    // TOP UP to at least three, rather than all-or-nothing. Barrington has two
    // local kitchen projects, and the old logic only reached for curated covers
    // when there were ZERO — so the slider sat on two slides. Same shape of bug
    // the review quotes had.
    $minSlides = 3;
    if ($citySliderImages->count() < $minSlides) {
        $citySliderImages = $citySliderImages->concat(
            \App\Models\ProjectImage::curatedCovers($projectType, $minSlides + $citySliderImages->count())
                ->reject(fn ($img) => $citySliderImages->contains('id', $img->id))
                ->take($minSlides - $citySliderImages->count())
        )->values();
    }

    // Only claim "we completed these HERE" when every slide genuinely is local.
    $hasLocalProjects = $localSliderImages->isNotEmpty()
        && $localSliderImages->count() === $citySliderImages->count();
@endphp
<section class="overflow-hidden bg-white py-10 sm:py-14 dark:bg-zinc-900" aria-label="About {{ $area->city }} {{ $serviceLine }}">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto grid max-w-2xl grid-cols-1 gap-x-12 gap-y-8 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-start">

            {{-- LEFT: project image slider --}}
            <div class="lg:mt-2">
                @if($citySliderImages->count() > 0)
                    <div
                        x-data="{
                            current: 0,
                            total: {{ $citySliderImages->count() }},
                            timer: null,
                            prev() { this.current = (this.current - 1 + this.total) % this.total; },
                            next() { this.current = (this.current + 1) % this.total; },
                            start() { this.timer = setInterval(() => this.next(), 3000); },
                            stop()  { if (this.timer) clearInterval(this.timer); this.timer = null; },
                        }"
                        x-init="start()"
                        @mouseenter="stop()"
                        @mouseleave="start()"
                        class="relative overflow-hidden rounded-2xl shadow-lg ring-1 ring-zinc-900/10 dark:ring-white/10"
                    >
                        <div class="relative aspect-[4/3] w-full bg-zinc-100 dark:bg-zinc-800">
                            @foreach($citySliderImages as $idx => $img)
                                <div
                                    x-show="current === {{ $idx }}"
                                    x-transition:enter="transition ease-out duration-700"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition ease-in duration-700"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0"
                                    class="absolute inset-0"
                                >
                                    <x-lqip-image
                                        :image="$img"
                                        size="large"
                                        aspectRatio="4/3"
                                        rounded="2xl"
                                        :alt="($img->seo_alt_text ?? $img->alt_text) ?: 'Remodeling project near ' . $area->city . ', IL'"
                                        class="h-full w-full object-cover" />

                                    {{-- Project title, top-left over the image —
                                         same treatment as the hero slider
                                         (bg-black/50, backdrop blur, chevron).
                                         Links through to the project, so a slide
                                         is a route into the work rather than
                                         decoration. --}}
                                    @if($img->project)
                                        <div class="absolute top-4 left-4 z-10">
                                            <a href="{{ route('projects.show', $img->project) }}" wire:navigate
                                               class="inline-flex items-center gap-1.5 rounded-lg bg-black/50 px-3 py-1.5 text-sm font-medium text-white backdrop-blur-sm transition hover:bg-black/70">
                                                {{ $img->project->title }}
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        {{-- No left/right arrows: the dots below are already
                             real buttons, and a pair of floating chevrons over
                             the image duplicated that control while covering
                             the photo. Same call as the hero slider. --}}

                        {{-- Dots --}}
                        @if($citySliderImages->count() > 1)
                            <div class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 gap-2">
                                @foreach($citySliderImages as $idx => $_img)
                                    <button
                                        type="button"
                                        @click="current = {{ $idx }}"
                                        :class="current === {{ $idx }} ? 'bg-white' : 'bg-white/50 hover:bg-white/80'"
                                        class="h-2 w-2 rounded-full transition"
                                        aria-label="Show slide {{ $idx + 1 }}"></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
                @if($hasLocalProjects)
                    <p class="mt-3 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                        Recent {{ $serviceLine }} we completed in {{ $area->city }}, IL.
                    </p>
                @endif

                {{-- Landmarks sit under the SLIDER rather than in the copy
                     column: it is a list of local places, so it belongs with the
                     local imagery, and it keeps the right-hand column to prose
                     rather than prose-then-list-then-box. --}}
                @if(filled($area->landmarks))
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Neighborhoods &amp; landmarks near our {{ $area->city }} {{ $serviceLine }}
                        </h3>
                        <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">{{ $area->landmarks }}</p>
                    </div>
                @endif
            </div>

            {{-- RIGHT: city copy --}}
            <div class="lg:pl-4">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                    {{ $heading }}
                </h2>

                @if(filled($area->intro))
                    <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                        {{ $area->intro }}
                    </p>
                @endif

                @if(filled($area->local_intro))
                    <div class="mt-4 prose prose-zinc dark:prose-invert max-w-none">
                        {!! nl2br(e($area->local_intro)) !!}
                    </div>
                @endif

                @if(filled($area->permit_notes))
                    <div class="mt-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ $area->city }} permits &amp; building codes for {{ $serviceLine }}
                        </h3>
                        <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $area->permit_notes }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
