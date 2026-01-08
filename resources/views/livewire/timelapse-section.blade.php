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
        preloaded: false,
        src() {
            if (!this.frames.length) return null;
            return this.frames[this.position - 1] ?? this.frames[0] ?? null;
        },
        tick() {
            if (!this.frames.length) return;
            this.position = (this.position % this.frames.length) + 1;
        },
        start() {
            if (!this.inView || this.hovering || this.dragging || this.timer || this.frames.length < 2) return;
            this.timer = setInterval(() => this.tick(), this.intervalMs);
        },
        stop() {
            if (!this.timer) return;
            clearInterval(this.timer);
            this.timer = null;
        },
        preloadInitial() {
            // Preload first 3 frames immediately on init
            this.frames.slice(0, 3).forEach(src => {
                const img = new Image();
                img.src = src;
            });
        },
        preloadAll() {
            if (this.preloaded) return;
            this.preloaded = true;
            // Preload remaining frames in background
            this.frames.slice(3).forEach(src => {
                const img = new Image();
                img.src = src;
            });
        },
        play() {
            this.inView = true;
            this.started = true;
            this.stop();
            this.start();
            this.preloadAll();
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
    x-init="preloadInitial()"
    @pointerup.window="endDrag()"
    @pointercancel.window="endDrag()"
    x-intersect:enter.full="play()"
    x-intersect:leave.full="pause()"
    class="relative w-full overflow-hidden bg-white dark:bg-slate-950"
>
    <div class="relative h-[375px] sm:h-[450px] lg:h-[525px]">
        <img
            x-show="frames.length"
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
