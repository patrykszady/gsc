{{--
    The project's timelapses as the project page shows them: the
    "Before & After & Timelapse" eyebrow, pills to pick a timelapse when there
    is more than one, and per timelapse a card holding the view toggle
    (Slider / Accordion / Before & After) with the flush player bled to the
    card's edges. Lifted out of the project page so the blog renders the exact
    same thing.

    Props
      timelapses  Timelapses with frames (caller filters).
      project     Owning project (alt text).
--}}
@props(['timelapses', 'project'])

<div x-data="{ active: 0 }" x-cloak {{ $attributes }}>
    {{-- Labels the whole timelapse region, so it is rendered once
         here rather than per card: the cards are mutually
         exclusive (x-show on `active`), and a heading inside each
         one would repeat in the markup and move with the tabs. --}}
    <p class="mb-4 text-center text-sm font-semibold uppercase tracking-widest text-sky-600 dark:text-sky-400">
        Before &amp; After &amp; Timelapse
    </p>

    @foreach($timelapses as $tIdx => $timelapse)
        @php
            $frames = $timelapse->frames->sortBy('sort_order')->map(fn($f) => $f->url)->values()->all();
            $timelapseTitle = $timelapse->title ?: 'Project Timelapse';
            $hasBeforeAfter = count($frames) >= 2;
            $defaultView = in_array($timelapse->display_mode, ['accordion', 'slider'], true) ? $timelapse->display_mode : 'slider';
        @endphp
        <div
            x-data="{ view: '{{ $defaultView }}' }"
            x-show="active === {{ $tIdx }}"
            x-cloak
            role="tabpanel"
            class="group mb-8 overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-5 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600"
        >
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                @if($timelapses->count() > 1)
                    <div role="tablist" aria-label="Select timelapse" class="flex flex-wrap gap-2">
                        @foreach($timelapses as $tIdx2 => $t)
                            <button
                                type="button"
                                role="tab"
                                @click="active = {{ $tIdx2 }}"
                                :aria-selected="active === {{ $tIdx2 }}"
                                :class="active === {{ $tIdx2 }}
                                    ? 'bg-sky-600 text-white border-sky-600 shadow-sm'
                                    : 'bg-white text-zinc-700 border-zinc-200 hover:border-zinc-300 hover:text-zinc-900 dark:bg-zinc-900 dark:text-zinc-300 dark:border-zinc-700 dark:hover:text-white'"
                                class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                            >{{ $t->title ?: 'Timelapse '.($tIdx2 + 1) }}</button>
                        @endforeach
                    </div>
                @else
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $timelapseTitle }}</h2>
                @endif

                <div role="tablist" aria-label="Timelapse view mode" class="inline-flex self-start rounded-lg border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-800">
                    <button type="button" role="tab" @click="view = 'slider'" :aria-selected="view === 'slider'"
                        :class="view === 'slider' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition">Slider</button>
                    <button type="button" role="tab" @click="view = 'accordion'" :aria-selected="view === 'accordion'"
                        :class="view === 'accordion' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition">Accordion</button>
                    @if($hasBeforeAfter)
                        <button type="button" role="tab" @click="view = 'before-after'" :aria-selected="view === 'before-after'"
                            :class="view === 'before-after' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                            class="rounded-md px-3 py-1.5 text-sm font-medium transition">Before &amp; After</button>
                    @endif
                </div>
            </div>

            {{-- Slider Panel. Bled to the card edges: the negative
                 margins cancel the card's p-4/sm:p-5, and the card's
                 overflow-hidden clips the frame to its own radius. --}}
            <div x-show="view === 'slider'" role="tabpanel" class="-mx-4 -mb-4 sm:-mx-5 sm:-mb-5">
                <livewire:timelapse-section :timelapse-id="$timelapse->id" :heading="null" :flush="true" :key="'timelapse-'.$timelapse->id" />
            </div>

            {{-- Accordion Panel --}}
            <div x-show="view === 'accordion'" x-cloak role="tabpanel" class="-mx-4 -mb-4 sm:-mx-5 sm:-mb-5">
                <section x-data="{ active: null }" class="relative w-full overflow-hidden rounded-t-2xl bg-zinc-100 dark:bg-zinc-800">
                    <div class="relative gallery-viewport flex">
                        @foreach($frames as $fIdx => $frameUrl)
                            <div
                                class="relative h-full overflow-hidden border-r border-white/20 last:border-r-0 transition-[transform,opacity] duration-500 ease-in-out cursor-pointer will-change-transform"
                                :class="active === {{ $fIdx }} ? 'flex-[8]' : (active === null ? 'flex-1' : 'flex-[0.3]')"
                                @mouseenter="active = {{ $fIdx }}"
                                @mouseleave="active = null"
                            >
                                <img src="{{ $frameUrl }}" alt="{{ $project->title }} — frame {{ $fIdx + 1 }}"
                                    class="absolute inset-0 h-full w-full object-cover"
                                    style="object-position: {{ count($frames) > 1 ? round($fIdx / (count($frames) - 1) * 100, 2) : 50 }}% center"
                                    loading="lazy" />
                                <div class="absolute inset-0 transition-colors duration-300" :class="active === {{ $fIdx }} ? 'bg-black/10' : 'bg-black/30'"></div>
                                <div class="absolute inset-x-0 bottom-0 z-10 p-3 text-center">
                                    <span class="inline-block rounded-full bg-black/60 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm transition-opacity duration-300"
                                        :class="active !== null && active !== {{ $fIdx }} ? 'opacity-0' : 'opacity-100'">
                                        @if($fIdx === 0) Before
                                        @elseif($fIdx === count($frames) - 1) After
                                        @else {{ $fIdx + 1 }}
                                        @endif
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            {{-- Before / After Panel --}}
            @if($hasBeforeAfter)
                @php $firstFrame = $frames[0]; $lastFrame = $frames[count($frames) - 1]; @endphp
                <div x-show="view === 'before-after'" x-cloak role="tabpanel" class="-mx-4 -mb-4 sm:-mx-5 sm:-mb-5">
                    <section
                        x-data="{
                            position: 50,
                            dragging: false,
                            updatePosition(clientX) {
                                const rect = this.$refs.tlBaContainer.getBoundingClientRect();
                                const x = clientX - rect.left;
                                this.position = Math.max(0, Math.min(100, (x / rect.width) * 100));
                            },
                            onPointerDown(e) {
                                this.dragging = true;
                                this.$refs.tlBaContainer.setPointerCapture(e.pointerId);
                                this.updatePosition(e.clientX);
                                e.preventDefault();
                            },
                            onPointerMove(e) { if (!this.dragging) return; this.updatePosition(e.clientX); },
                            onPointerUp(e) {
                                if (!this.dragging) return;
                                this.dragging = false;
                                this.$refs.tlBaContainer.releasePointerCapture(e.pointerId);
                            },
                        }"
                        class="relative select-none"
                    >
                        <div
                            x-ref="tlBaContainer"
                            @pointerdown="onPointerDown($event)"
                            @pointermove="onPointerMove($event)"
                            @pointerup="onPointerUp($event)"
                            @pointercancel="onPointerUp($event)"
                            class="relative h-[375px] w-full overflow-hidden rounded-t-2xl bg-zinc-100 dark:bg-zinc-800 cursor-col-resize sm:h-[450px] lg:h-[525px]" style="touch-action: none;"
                        >
                            <img src="{{ $lastFrame }}" alt="{{ $timelapseTitle }} — After" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                            <div class="absolute inset-0 overflow-hidden" :style="'clip-path: inset(0 ' + (100 - position) + '% 0 0)'">
                                <img src="{{ $firstFrame }}" alt="{{ $timelapseTitle }} — Before" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                            </div>
                            <div class="absolute inset-y-0 z-10 flex items-center" :style="'left: ' + position + '%'">
                                <div class="relative -ml-px h-full w-0.5 bg-white shadow-md">
                                    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 flex size-10 items-center justify-center rounded-full bg-white shadow-lg ring-2 ring-white/80">
                                        <svg class="size-5 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                        <svg class="size-5 -ml-1 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                    </div>
                                </div>
                            </div>
                            <div class="pointer-events-none absolute inset-x-0 bottom-4 z-10 flex justify-between px-4">
                                <span class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm" x-show="position > 10" x-transition>Before</span>
                                <span class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm" x-show="position < 90" x-transition>After</span>
                            </div>
                        </div>
                    </section>
                </div>
            @endif
        </div>
    @endforeach
</div>
