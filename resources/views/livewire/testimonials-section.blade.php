@php
    $hasMultiple = count($history ?? []) > 1;
@endphp
<div wire:key="testimonials-section-{{ $projectType ?? 'all' }}"
     x-data="{
        touchStartX: 0,
        touchEndX: 0,
        autoplay: null,
        isHovered: false,
        isVisible: true,
        isTabVisible: true,
        startAutoplay() {
            if (!this.isVisible || !this.isTabVisible || this.isHovered) return;
            this.stopAutoplay();
            this.autoplay = setInterval(() => $wire.nextTestimonial(), 4000);
        },
        scheduleAutoplay() {
            const run = () => this.startAutoplay();
            if ('requestIdleCallback' in window) {
                requestIdleCallback(run, { timeout: 1200 });
            } else {
                setTimeout(run, 200);
            }
        },
        stopAutoplay() {
            if (this.autoplay) {
                clearInterval(this.autoplay);
                this.autoplay = null;
            }
        },
        handleVisibility(isVisible) {
            this.isVisible = isVisible;
            if (isVisible && this.isTabVisible && !this.isHovered) {
                this.scheduleAutoplay();
            } else {
                this.stopAutoplay();
            }
        },
        handleTabVisibility() {
            this.isTabVisible = !document.hidden;
            if (this.isTabVisible && this.isVisible && !this.isHovered) {
                this.scheduleAutoplay();
            } else {
                this.stopAutoplay();
            }
        },
        pauseAutoplay() {
            this.isHovered = true;
            this.stopAutoplay();
        },
        resumeAutoplay() {
            this.isHovered = false;
            this.scheduleAutoplay();
        },
        handleTouchStart(e) {
            this.touchStartX = e.changedTouches[0].screenX;
        },
        handleTouchEnd(e) {
            this.touchEndX = e.changedTouches[0].screenX;
            const diff = this.touchStartX - this.touchEndX;
            if (Math.abs(diff) > 50) {
                if (diff > 0) {
                    $wire.nextTestimonial();
                } else {
                    $wire.prevTestimonial();
                }
            }
        }
     }"
    x-on:visibilitychange.document="handleTabVisibility()"
      x-init="
          @if($hasMultiple) scheduleAutoplay(); @endif
     "
     x-intersect:enter.threshold.40="handleVisibility(true)"
     x-intersect:leave.threshold.40="handleVisibility(false)"
     @keydown.left.window="$wire.prevTestimonial()"
     @keydown.right.window="$wire.nextTestimonial()"
>
    @php
        $imageUrl = $current['image'] ?? asset('images/greg-patryk-thumb.jpg');
        $hasMultiple = count($history ?? []) > 1;
    @endphp

    <section class="{{ $sectionClasses }}">

        <div class="mx-auto {{ $maxWidthClass }} {{ $paddingClass }}">
            {{-- Section Header --}}
            @if($showHeader)
            <div class="mb-10">
                <x-testimonials-header :area="$area" :show-subtitle="true" />
            </div>
            @endif

            {{-- Review card.

                 FIXED HEIGHT at every breakpoint. The carousel swaps quotes of
                 wildly different lengths (200–1400 chars), and a card that
                 resizes makes the whole page jump on every rotation. The height
                 is the container; the quote clamps to fit it.

                 One responsive layout — this was two near-identical copies
                 (lg: and lg:hidden), ~150 lines of duplicated markup. --}}
            <div
                @touchstart="handleTouchStart($event)"
                @touchend.passive="handleTouchEnd($event)"
                class="relative flex h-[27rem] w-full flex-col touch-pan-y overflow-hidden rounded-2xl bg-white p-4 shadow-lg ring-1 ring-gray-900/5 sm:h-[16.5rem] sm:p-5 lg:h-[17.5rem] lg:p-6 dark:bg-zinc-800/75 dark:shadow-none dark:ring-white/10"
            >
                {{-- min-h-0 lets the quote clamp instead of forcing the flex
                     parent taller than its fixed height. --}}
                <figure class="flex min-h-0 flex-1 flex-col gap-4 sm:flex-row sm:gap-5">
                    <div
                        x-data="{
                            imgLoaded: window.imageCache?.has('{{ $imageUrl }}') || false,
                            init() {
                                this.$nextTick(() => {
                                    const img = this.$refs.reviewImg;
                                    if (img?.complete && img?.naturalWidth > 0) this.imgLoaded = true;
                                });
                            }
                        }"
                        {{-- size-38 (9.5rem/152px) is the measured width of the
                             "Read full review" button in the footer below, which
                             is pinned to sm:w-38 so the two edges line up. Change
                             one, change the other. --}}
                        class="group/thumb relative h-36 w-full shrink-0 overflow-hidden rounded-xl bg-zinc-100 sm:size-38 dark:bg-zinc-700/40"
                    >
                        {{-- Same hover zoom as every other image on the site
                             (project cards, about gallery): scale-105 on the
                             image inside an overflow-hidden wrapper.

                             Named group/thumb rather than a bare group: the
                             card and its ancestors already use unnamed groups,
                             and an unnamed one here would also fire whenever
                             those were hovered.

                             Plain `transition`, like the project cards use, not
                             transition-opacity: in Tailwind v4 scale-105 sets
                             the `scale` property rather than `transform`, so a
                             narrower transition list would leave the zoom
                             snapping instantly while the fade animated. --}}
                        <img
                            x-ref="reviewImg"
                            src="{{ $imageUrl }}"
                            alt=""
                            loading="lazy"
                            class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover/thumb:scale-105"
                            :class="imgLoaded ? 'opacity-100' : 'opacity-0'"
                            @load="imgLoaded = true; window.imageCache?.set('{{ $imageUrl }}', '{{ $imageUrl }}')"
                        />
                    </div>

                    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                        {{-- self-start: this column is a flex COLUMN, where
                             align-items defaults to stretch, so a w-auto image
                             does not sit hard left the way it would in normal
                             flow — it drifted off the text's left edge. --}}
                        <x-five-stars size="h-9" class="self-start" />

                        <blockquote
                            class="mt-3 min-h-0 flex-1"
                            @mouseenter="pauseAutoplay()"
                            @mouseleave="resumeAutoplay()"
                            @touchstart.passive="pauseAutoplay()"
                            @touchend.passive="resumeAutoplay()"
                        >
                            <p class="line-clamp-3 text-base/7 text-gray-700 sm:line-clamp-4 dark:text-gray-200">
                                {{ $current['description'] ?? '' }}
                            </p>
                        </blockquote>

                        <figcaption class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $current['name'] ?? '' }}</span>

                            @if(!empty($current['location']))
                                <span aria-hidden="true" class="text-zinc-300 dark:text-zinc-600">&middot;</span>
                                @if(!empty($current['area_slug']))
                                    <a href="{{ route('areas.show', $current['area_slug']) }}" class="text-gray-500 hover:text-sky-600 hover:underline dark:text-gray-400 dark:hover:text-sky-400">{{ $current['location'] }}</a>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">{{ $current['location'] }}</span>
                                @endif
                            @endif

                            @if(!empty($current['date']))
                                <span aria-hidden="true" class="text-zinc-300 dark:text-zinc-600">&middot;</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $current['date'] }}</span>
                            @endif

                            @if(!empty($current['project_type']))
                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20">
                                    {{ \Illuminate\Support\Str::title(str_replace('-', ' ', $current['project_type'])) }}
                                </span>
                            @endif
                        </figcaption>
                    </div>
                </figure>

                {{-- Footer row: both CTAs left, arrows right. mt-auto pins it to
                     the bottom of the fixed-height card whatever the quote does. --}}
                <div class="mt-auto flex flex-wrap items-center gap-3 border-t border-zinc-100 pt-3 dark:border-white/5">
                    @if(!empty($current['slug']))
                        {{-- w-38 matches the thumbnail above it (see figure). The
                             label's natural width is 152px, so this pins rather
                             than stretches. --}}
                        <x-buttons.cta href="{{ route('reviews.show', $current['slug']) }}" size="sm" class="sm:w-38">
                            Read full review
                        </x-buttons.cta>
                    @endif

                    <x-buttons.cta href="{{ route('reviews.index') }}" variant="outline" size="sm">
                        All {{ $totalCount ?? '' }} reviews
                    </x-buttons.cta>

                    @if($hasMultiple)
                        <div class="ml-auto flex items-center gap-2">
                            <button
                                wire:click="prevTestimonial"
                                class="cursor-pointer rounded-full border border-zinc-200 p-2 text-gray-500 transition hover:bg-zinc-50 hover:text-gray-900 dark:border-white/10 dark:text-white/60 dark:hover:bg-white/5 dark:hover:text-white"
                                aria-label="Previous review"
                            >
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                                </svg>
                            </button>
                            <button
                                wire:click="nextTestimonial"
                                class="cursor-pointer rounded-full border border-zinc-200 p-2 text-gray-500 transition hover:bg-zinc-50 hover:text-gray-900 dark:border-white/10 dark:text-white/60 dark:hover:bg-white/5 dark:hover:text-white"
                                aria-label="Next review"
                            >
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>
