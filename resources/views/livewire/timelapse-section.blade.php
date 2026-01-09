<section
    x-data="{
        frames: @js($frames ?? []),
        position: 1,
        timer: null,
        started: false,
        inView: false,
        hovering: false,
        dragging: false,
        intervalMs: 650,
        allLoaded: false,
        loadedCount: 0,
        firstFrameLoaded: false,
        firstFrameWasCached: false,
        firstFrameThumbLoaded: false,
        showBlur: false,
        init() {
            // Check if first frame is already loaded (browser cached) - same as team-photo-slider
            const firstFrame = this.$refs.firstFrame;
            if (firstFrame?.complete && firstFrame?.naturalWidth > 0) {
                this.firstFrameLoaded = true;
                this.firstFrameWasCached = true;
            } else {
                this.showBlur = true;
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
    class="relative w-full overflow-hidden bg-white dark:bg-slate-950"
>
    <div class="relative h-[375px] sm:h-[450px] lg:h-[525px]">
        {{-- Blur placeholder for first frame (only shown when full image is loading) --}}
        <img
            x-cloak
            x-show="showBlur && !firstFrameLoaded"
            src="{{ $frames[0] ?? '' }}"
            alt=""
            aria-hidden="true"
            @load="firstFrameThumbLoaded = true"
            :class="firstFrameThumbLoaded ? 'opacity-100' : 'opacity-0'"
            class="absolute inset-0 h-full w-full object-cover blur-xl scale-110"
        />
        {{-- First frame with static src for cache detection --}}
        <img
            x-ref="firstFrame"
            x-show="frames.length && position === 1"
            src="{{ $frames[0] ?? '' }}"
            alt="Project timelapse"
            @load="if (!firstFrameLoaded) { firstFrameLoaded = true; }"
            :class="firstFrameWasCached ? 'opacity-100' : (firstFrameLoaded ? 'opacity-100 transition-opacity duration-300' : 'opacity-0')"
            class="absolute inset-0 h-full w-full object-cover"
        />
        {{-- Other frames with dynamic src --}}
        <img
            x-show="frames.length && position !== 1"
            :src="src()"
            alt="Project timelapse"
            class="absolute inset-0 h-full w-full object-cover"
        />

        {{-- Subtle overlay to match hero style --}}
        <div class="absolute inset-0 bg-black/20"></div>

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
                    <flux:slider min="1" max="{{ $frameCount }}" x-model.number="position">
                        @for ($i = 1; $i <= $frameCount; $i++)
                            <flux:slider.tick value="{{ $i }}" class="!text-white drop-shadow-sm font-medium">
                                @if ($i === 1)
                                    Before
                                @elseif ($i === $middleTick)
                                    Construction
                                @elseif ($i === $frameCount)
                                    After
                                @else
                                    <span class="sr-only">Frame {{ $i }}</span>
                                @endif
                            </flux:slider.tick>
                        @endfor
                    </flux:slider>
                </div>
            </div>
        </div>
    </div>
</section>
