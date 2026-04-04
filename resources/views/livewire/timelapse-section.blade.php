<section
    x-data="{
        frames: @js($frames ?? []),
        position: 1,
        timer: null,
        started: false,
        inView: false,
        hovering: false,
        dragging: false,
        intervalMs: 1200,
        allLoaded: false,
        loadedCount: 0,
        firstFrameLoaded: false,
        showBlur: false,
        init() {
            // Check if first frame is already cached
            if (this.frames.length) {
                const img = new Image();
                img.src = this.frames[0];
                if (img.complete && img.naturalWidth > 0) {
                    this.firstFrameLoaded = true;
                } else {
                    this.showBlur = true;
                    img.onload = () => { this.firstFrameLoaded = true; };
                }
            }
        },
        src() {
            if (!this.frames.length) return null;
            return this.frames[this.position - 1] ?? this.frames[0] ?? null;
        },
        tick() {
            if (!this.frames.length) return;
            this.position = (this.position % this.frames.length) + 1;
        },
        start() {
            if (!this.inView || this.hovering || this.dragging || this.timer || this.frames.length < 2 || !this.allLoaded) return;
            this.timer = setInterval(() => this.tick(), this.intervalMs);
        },
        stop() {
            if (!this.timer) return;
            clearInterval(this.timer);
            this.timer = null;
        },
        preloadAll() {
            if (this.allLoaded) return;
            const total = this.frames.length;
            if (total === 0) {
                this.allLoaded = true;
                return;
            }
            this.frames.forEach(src => {
                const img = new Image();
                img.onload = () => {
                    this.loadedCount++;
                    if (this.loadedCount >= total) {
                        this.allLoaded = true;
                        // Start playing once all loaded (if still in view)
                        if (this.inView && this.started) {
                            this.start();
                        }
                    }
                };
                img.onerror = () => {
                    this.loadedCount++;
                    if (this.loadedCount >= total) {
                        this.allLoaded = true;
                        if (this.inView && this.started) {
                            this.start();
                        }
                    }
                };
                img.src = src;
            });
        },
        play() {
            this.inView = true;
            this.started = true;
            this.preloadAll();
            // Only start if all images are already loaded
            if (this.allLoaded) {
                this.stop();
                this.start();
            }
        },
        pause() {
            this.inView = false;
            this.stop();
        },
        beginHover() {
            this.hovering = true;
            this.stop();
        },
        endHover() {
            this.hovering = false;
            this.start();
        },
        beginDrag() {
            this.dragging = true;
            this.stop();
        },
        endDrag() {
            this.dragging = false;
            this.start();
        },
    }"
    @pointerup.window="endDrag()"
    @pointercancel.window="endDrag()"
    x-intersect:enter.full="play()"
    x-intersect:leave.full="pause()"
    class="relative w-full overflow-hidden rounded-2xl bg-white dark:bg-slate-950"
>
    <div class="relative h-[375px] sm:h-[450px] lg:h-[525px]">
        {{-- Blur placeholder while first frame loads --}}
        <img
            x-cloak
            x-show="showBlur && !firstFrameLoaded"
            src="{{ $frames[0] ?? '' }}"
            alt=""
            aria-hidden="true"
            class="absolute inset-0 h-full w-full object-cover blur-xl scale-110 transition-opacity duration-300"
        />
        {{-- Timelapse frame --}}
        <img
            x-ref="frame"
            x-show="frames.length"
            :src="src()"
            alt="Project timelapse"
            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-150"
        />

        {{-- Subtle overlay to match hero style --}}
        <div class="absolute inset-0 bg-black/20"></div>

        {{-- Project title --}}
        @if($timelapse?->project)
        <div class="absolute top-4 left-4 z-10">
            <a href="{{ route('projects.show', $timelapse->project) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-black/50 px-3 py-1.5 text-sm font-medium text-white backdrop-blur-sm transition hover:bg-black/70">
                {{ $timelapse->project->title }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
        </div>
        @endif

        {{-- Speed control --}}
        <div class="absolute inset-x-0 bottom-6 z-10">
            <div class="mx-auto w-full max-w-md px-6">
                <div
                    class="rounded-xl p-4 text-white backdrop-blur-sm shadow-lg ring-2 ring-white/50 **:text-white **:fill-white **:stroke-white"
                    @mouseenter="beginHover()"
                    @mouseleave="endHover()"
                    @focusin="beginHover()"
                    @focusout="endHover()"
                    @pointerdown.capture="beginDrag()"
                >
                    <div class="relative">
                        <flux:slider min="1" max="{{ $frameCount }}" x-model.number="position">
                            @for ($i = 1; $i <= $frameCount; $i++)
                                <flux:slider.tick value="{{ $i }}" class="!text-white drop-shadow-sm font-medium">
                                    @if ($i === 1)
                                        Before
                                    @elseif ($i === $frameCount)
                                        After
                                    @else
                                        <span class="sr-only">Frame {{ $i }}</span>
                                    @endif
                                </flux:slider.tick>
                            @endfor
                        </flux:slider>
                        <div class="pointer-events-none absolute inset-x-0 bottom-0 flex justify-center">
                            <span class="text-sm font-medium text-white drop-shadow-sm">Construction</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
