@props([
    // grid | floorplan | mesh | aurora
    'variant' => 'grid',
])

{{--
    Decorative page background. Parent must be `relative isolate overflow-x-clip`;
    every layer is aria-hidden, pointer-events-none, and sits at -z-10 so content
    and clicks are unaffected.

    `grid` (default) is the quiet option: a sharp drafting grid that fades in
    below the fold. Safe on any page — it needs no changes to the content above it.

    `floorplan` is the showpiece, available for reuse on other pages:

        <div class="relative isolate overflow-x-clip bg-sky-50/60 dark:bg-zinc-950">
            <x-page-decor variant="floorplan" />

    Two things it REQUIRES to be visible, both learned the hard way:
      1. A tinted canvas (e.g. bg-sky-50/60). White decor under white cards is
         invisible — the tint is what the drawing reads against.
      2. Translucent content surfaces (bg-white/80, bg-white/88 on big tables).
         Solid cards hide it completely.
    The plan is blurred so its linework can never fight body copy, but text
    sitting directly on the canvas still reads best on a light scrim panel.

    `floorplan` is interactive: an architect's remodel plan over a soft grid.
    Alpine writes scroll progress (0..1) to --sp; pure CSS stages everything
    from it — existing walls draw in, demo walls morph to dashed and fade, new
    construction draws, pastel room fills wash in last. The SVG artwork lives
    in partials/decor-floorplan.blade.php and relies on its group classes
    (g-shell/g-int/g-demo/g-new/g-doors/g-windows/g-fix/g-dims/g-labels/g-fills)
    plus pathLength="1" on every stroked element.
--}}

@if($variant === 'floorplan')
    <div aria-hidden="true"
         class="fp-scene pointer-events-none absolute inset-0 -z-10"
         x-data="{
            raf: null,
            update() {
                if (this.raf) return;
                this.raf = requestAnimationFrame(() => {
                    this.raf = null;
                    const r = this.$el.getBoundingClientRect();
                    const total = r.height - window.innerHeight;
                    const p = total > 0 ? Math.min(1, Math.max(0, -r.top / total)) : 1;
                    this.$el.style.setProperty('--sp', p.toFixed(4));
                });
            }
         }"
         x-init="update()"
         @scroll.window.passive="update()"
         @resize.window.passive="update()">

        {{-- Soft drafting grid + faint glows (scroll with the page) --}}
        <div class="absolute inset-0 opacity-60 dark:opacity-20"
             style="background-image:
                linear-gradient(to right, rgba(2,132,199,.10) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(2,132,199,.10) 1px, transparent 1px);
                background-size: 3.5rem 3.5rem;"></div>
        <div class="absolute inset-0 dark:opacity-50"
             style="background:
                radial-gradient(50rem 34rem at 90% 0%, rgba(14,165,233,.16), transparent 60%),
                radial-gradient(44rem 32rem at 2% 60%, rgba(56,189,248,.13), transparent 62%);"></div>

        {{-- Sticky viewport layer: the plan stays on screen while the page scrolls --}}
        <div class="sticky top-0 hidden h-screen w-full overflow-hidden md:block">
            <div class="fp-plan absolute left-1/2 top-1/2 w-5xl -translate-x-1/2 -translate-y-1/2 lg:w-6xl">
                @include('partials.decor-floorplan')
            </div>
        </div>
    </div>

    <style>
        .fp-scene { --fp-line: rgba(2, 132, 199, .8); --fp-fill-max: .55; }
        .dark .fp-scene { --fp-line: rgba(56, 189, 248, .45); --fp-fill-max: .18; }

        /* The drafting grid stays sharp; the plan itself is blurred so its
           linework can never compete with body copy sitting over it. Stroke
           alpha is raised to compensate for the contrast the blur removes. */
        .fp-scene .fp-plan svg {
            display: block;
            width: 100%;
            height: auto;
            color: var(--fp-line);
            filter: blur(3.5px);
            will-change: filter;
        }

        /* Stage timers: each maps a slice of scroll progress (--sp: 0..1) to 0..1 */
        .fp-scene .g-shell   { --t: clamp(0, calc((var(--sp, 0) - .02) / .14), 1); }
        .fp-scene .g-int     { --t: clamp(0, calc((var(--sp, 0) - .12) / .14), 1); }
        .fp-scene .g-doors,
        .fp-scene .g-windows { --t: clamp(0, calc((var(--sp, 0) - .22) / .12), 1); }
        .fp-scene .g-fix     { --t: clamp(0, calc((var(--sp, 0) - .32) / .14), 1); }
        .fp-scene .g-dims,
        .fp-scene .g-labels  { --t: clamp(0, calc((var(--sp, 0) - .44) / .10), 1); }
        .fp-scene .g-demo,
        .fp-scene .g-demo-dash { --t: clamp(0, calc((var(--sp, 0) - .12) / .14), 1); }
        .fp-scene .g-new     { --t: clamp(0, calc((var(--sp, 0) - .58) / .14), 1); }

        /* The remodel moment: demo walls morph to dashed and fade... */
        .fp-scene { --d: clamp(0, calc((var(--sp, 0) - .52) / .10), 1); }
        /* ...while the finished linework settles back as fills arrive */
        .fp-scene { --n: clamp(0, calc((var(--sp, 0) - .58) / .14), 1); }

        .fp-scene .fp-plan [pathLength] {
            stroke-dasharray: 1;
            stroke-dashoffset: calc(1 - var(--t, 1));
            transition: stroke-dashoffset .15s linear;
        }
        /* The removed wall disappears; a dashed ghost of it fades in behind. */
        .fp-scene .g-demo { opacity: calc(1 - var(--d)); transition: opacity .25s linear; }
        .fp-scene .g-demo-dash [pathLength] { stroke-dasharray: .014 .014; stroke-dashoffset: 0; }
        .fp-scene .g-demo-dash { opacity: calc(var(--d) * .55); transition: opacity .25s linear; }
        .fp-scene .g-int, .fp-scene .g-fix,
        .fp-scene .g-doors, .fp-scene .g-windows { opacity: calc(1 - var(--n) * .25); transition: opacity .2s linear; }

        /* Any text inherits --t from its enclosing group, so labels, dimension
           values and callouts appear with the linework they belong to. */
        .fp-scene .fp-plan text {
            fill-opacity: var(--t, 1);
            transition: fill-opacity .2s linear;
        }

        /* Pastel room fills wash in one room at a time */
        .fp-scene .g-fills .f-1 { --f: clamp(0, calc((var(--sp, 0) - .70) / .10), 1); }
        .fp-scene .g-fills .f-2 { --f: clamp(0, calc((var(--sp, 0) - .74) / .10), 1); }
        .fp-scene .g-fills .f-3 { --f: clamp(0, calc((var(--sp, 0) - .78) / .10), 1); }
        .fp-scene .g-fills .f-4 { --f: clamp(0, calc((var(--sp, 0) - .82) / .10), 1); }
        .fp-scene .g-fills .f-5 { --f: clamp(0, calc((var(--sp, 0) - .86) / .10), 1); }
        .fp-scene .g-fills .f-6 { --f: clamp(0, calc((var(--sp, 0) - .88) / .10), 1); }
        .fp-scene .g-fills > * {
            fill-opacity: calc(var(--f, 0) * var(--fp-fill-max));
            transition: fill-opacity .25s linear;
        }

        @media (prefers-reduced-motion: reduce) {
            .fp-scene { --sp: 1 !important; }
        }
    </style>

@elseif($variant === 'grid')
    {{-- Sharp drafting grid, masked away at the top of the page so the area
         under the nav stays pure white and the header blends in. Soft blue
         blobs sit BEHIND the grid and bloom in at staggered scroll depths, so
         the lines stay crisp on top of them and the page never feels static.
         Blobs are radial gradients (no blur filter) and animate only opacity
         and transform, so this all composites on the GPU. --}}
    <div aria-hidden="true"
         class="gd-scene pointer-events-none absolute inset-0 -z-10"
         x-data="{
            raf: null, fraf: null, reduced: false, cx: 0, cy: 0, items: [],
            init() {
                // Gate on the actual pointer that moved, not on a hover/pointer
                // media query: hybrid laptops with a mouse attached often report
                // a coarse pointer, which would wrongly disable the effect.
                this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.measure();
                this.update();
            },
            update() {
                if (this.raf) return;
                this.raf = requestAnimationFrame(() => {
                    this.raf = null;
                    const r = this.$el.getBoundingClientRect();
                    const total = r.height - window.innerHeight;
                    const p = total > 0 ? Math.min(1, Math.max(0, -r.top / total)) : 1;
                    this.$el.style.setProperty('--sp', p.toFixed(4));
                });
            },
            measure() {
                const K = [0.55, 0.4, 0.7, 0.32], E = [0.07, 0.1, 0.05, 0.12];
                this.items = Array.from(this.$el.querySelectorAll('.gd-blob')).map((el, i) => ({
                    node: el.querySelector('.gd-follow'),
                    k: K[i % 4], e: E[i % 4],
                    ax: el.offsetLeft + el.offsetWidth / 2,
                    ay: el.offsetTop + el.offsetHeight / 2,
                    x: 0, y: 0,
                }));
            },
            onMove(ev) {
                if (this.reduced) return;
                if (ev.pointerType && ev.pointerType !== 'mouse') return;
                this.cx = ev.clientX; this.cy = ev.clientY;
                this.chase();
            },
            chase() {
                if (this.fraf) return;
                this.fraf = requestAnimationFrame(() => {
                    this.fraf = null;
                    const cap = 420;
                    let moving = false;
                    for (const b of this.items) {
                        const tx = Math.max(-cap, Math.min(cap, (this.cx - b.ax) * b.k));
                        const ty = Math.max(-cap, Math.min(cap, (this.cy - b.ay) * b.k));
                        b.x += (tx - b.x) * b.e;
                        b.y += (ty - b.y) * b.e;
                        if (Math.abs(tx - b.x) > 0.5 || Math.abs(ty - b.y) > 0.5) moving = true;
                        if (b.node) b.node.style.transform =
                            'translate3d(' + b.x.toFixed(1) + 'px,' + b.y.toFixed(1) + 'px,0)';
                    }
                    if (moving) this.chase();
                });
            }
         }"
         x-init="init()"
         @scroll.window.passive="update()"
         @resize.window.passive="measure(); update()"
         @pointermove.window.passive="onMove($event)">

        {{-- Blob field is pinned to the VIEWPORT, not the page: cursor math needs
             the pointer and the blob anchors in the same (viewport) coordinate
             space. Anchored to the page instead, a blob 6000px up the document
             would be flung across the whole scroll height on the first mouse move.
             Three nested layers so nothing fights over `transform`:
               .gd-blob    scroll-driven position (CSS, off --sp)
               .gd-follow  cursor chase          (JS writes transform)
               i           ambient drift         (CSS keyframes) --}}
        <div class="gd-blobs fixed inset-0 overflow-hidden">
            <div class="gd-blob b1 absolute -left-56 top-[6%] size-184"><span class="gd-follow"><i></i></span></div>
            <div class="gd-blob b2 absolute -right-56 top-[30%] size-168"><span class="gd-follow"><i></i></span></div>
            <div class="gd-blob b3 absolute left-[8%] top-[58%] size-176"><span class="gd-follow"><i></i></span></div>
            <div class="gd-blob b4 absolute -right-40 top-[74%] size-160"><span class="gd-follow"><i></i></span></div>
        </div>

        <div class="gd-grid absolute inset-0 dark:hidden"
             style="background-image:
                linear-gradient(to right, rgba(2,132,199,.1) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(2,132,199,.1) 1px, transparent 1px);"></div>
        <div class="gd-grid absolute inset-0 hidden dark:block"
             style="background-image:
                linear-gradient(to right, rgba(125,211,252,.07) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(125,211,252,.07) 1px, transparent 1px);"></div>
    </div>

    <style>
        .gd-grid {
            background-size: 4rem 4rem;
            /* Fade in below the hero, then stay.
               These were 680px/1360px, measured from the top of the DOCUMENT —
               so how much grid you saw depended on how tall the page was. On a
               long page (a /compare/* alternative page) the content sat well
               past 1360px and read against a full drafting grid; on the short
               /compare index the cards landed inside the fade band and sat on
               near-white. Same component, two different-looking pages.
               Pulled in to clear a hero (~520px) and reach full strength by the
               first content block, so every page using the decor looks alike. */
            -webkit-mask-image: linear-gradient(to bottom, transparent 0, transparent 320px, #000 760px);
            mask-image: linear-gradient(to bottom, transparent 0, transparent 320px, #000 760px);
        }

        .dark .gd-blobs { opacity: .5; }

        /* Fade in over its own slice of scroll progress, AND travel across the
           viewport as you scroll so the arrangement keeps changing. */
        .gd-blob { opacity: 0; transition: opacity .3s linear; }
        .gd-blob.b1 {
            opacity: clamp(0, calc((var(--sp, 0) - .06) / .10), 1);
            transform: translate3d(calc(var(--sp, 0) * 15rem), calc(var(--sp, 0) * -9rem), 0);
        }
        .gd-blob.b2 {
            opacity: clamp(0, calc((var(--sp, 0) - .24) / .10), 1);
            transform: translate3d(calc(var(--sp, 0) * -17rem), calc(var(--sp, 0) * 7rem), 0);
        }
        .gd-blob.b3 {
            opacity: clamp(0, calc((var(--sp, 0) - .46) / .10), 1);
            transform: translate3d(calc(var(--sp, 0) * 13rem), calc(var(--sp, 0) * -15rem), 0);
        }
        .gd-blob.b4 {
            opacity: clamp(0, calc((var(--sp, 0) - .68) / .10), 1);
            transform: translate3d(calc(var(--sp, 0) * -11rem), calc(var(--sp, 0) * -13rem), 0);
        }

        .gd-follow { display: block; height: 100%; width: 100%; will-change: transform; }

        .gd-blob i {
            display: block;
            height: 100%;
            width: 100%;
            border-radius: 9999px;
            animation: gd-drift 22s ease-in-out infinite;
        }
        .gd-blob.b1 i { background: radial-gradient(closest-side, rgba(14,165,233,.26), transparent 72%); }
        .gd-blob.b2 i { background: radial-gradient(closest-side, rgba(34,211,238,.24), transparent 72%); animation-duration: 27s; animation-delay: -7s; }
        .gd-blob.b3 i { background: radial-gradient(closest-side, rgba(2,132,199,.2), transparent 72%);  animation-duration: 24s; animation-delay: -13s; }
        .gd-blob.b4 i { background: radial-gradient(closest-side, rgba(56,189,248,.26), transparent 72%); animation-duration: 30s; animation-delay: -4s; }

        @keyframes gd-drift {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(1.5rem, -1.25rem, 0) scale(1.07); }
        }

        @media (prefers-reduced-motion: reduce) {
            .gd-scene { --sp: 1 !important; }
            .gd-blob i { animation: none; }
        }
    </style>

@elseif($variant === 'aurora')
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 overflow-hidden">
        <div class="absolute -top-40 -left-40 h-168 w-280 rotate-18 bg-linear-to-tr from-sky-400/45 via-sky-300/35 to-transparent blur-3xl dark:from-sky-500/25 dark:via-sky-400/15"></div>
        <div class="absolute top-1/3 -right-56 h-152 w-264 -rotate-14 bg-linear-to-tl from-cyan-400/40 via-sky-400/30 to-transparent blur-3xl dark:from-cyan-500/20 dark:via-sky-500/12"></div>
        <div class="absolute -bottom-40 left-1/4 h-136 w-240 rotate-8 bg-linear-to-tr from-sky-300/40 to-transparent blur-3xl dark:from-sky-600/20"></div>
    </div>

@else
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 dark:hidden"
         style="background:
            radial-gradient(55rem 38rem at 10% -4%, rgba(14,165,233,.34), transparent 62%),
            radial-gradient(48rem 34rem at 96% 6%, rgba(56,189,248,.32), transparent 62%),
            radial-gradient(46rem 34rem at 72% 46%, rgba(2,132,199,.22), transparent 64%),
            radial-gradient(42rem 32rem at 4% 72%, rgba(125,211,252,.34), transparent 62%),
            radial-gradient(40rem 30rem at 88% 92%, rgba(14,165,233,.24), transparent 62%);"></div>
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 hidden dark:block"
         style="background:
            radial-gradient(55rem 38rem at 10% -4%, rgba(14,165,233,.20), transparent 62%),
            radial-gradient(48rem 34rem at 96% 6%, rgba(56,189,248,.16), transparent 62%),
            radial-gradient(46rem 34rem at 72% 46%, rgba(2,132,199,.16), transparent 64%),
            radial-gradient(42rem 32rem at 4% 72%, rgba(125,211,252,.14), transparent 62%);"></div>
@endif
