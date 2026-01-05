<section
    x-data="{
        started: false,
        play() {
            const el = this.$refs.video;
            if (!el) return;
            this.started = true;
            const p = el.play();
            if (p && typeof p.catch === 'function') p.catch(() => {});
        },
        pause() {
            const el = this.$refs.video;
            if (!el) return;
            el.pause();
        },
    }"
    x-intersect:enter.threshold.35="play()"
    x-intersect:leave.threshold.10="pause()"
    class="relative w-full overflow-hidden bg-white dark:bg-slate-950"
>
    <div class="relative h-[375px] sm:h-[450px] lg:h-[525px]">
        <video
            x-ref="video"
            class="absolute inset-0 h-full w-full object-cover"
            muted
            playsinline
            preload="metadata"
        >
            <source src="{{ asset('videos/carriageway full timelapse.mp4') }}" type="video/mp4" />
        </video>

        {{-- Subtle overlay to match hero style --}}
        <div class="absolute inset-0 bg-black/20"></div>
    </div>
</section>
