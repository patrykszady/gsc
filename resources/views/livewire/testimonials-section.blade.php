<div>
    <section
        x-data="{
            current: @entangle('current'),
            autoplay: null,
            touchStartX: 0,
            touchEndX: 0,
            getImage() {
                if (this.current?.image) return this.current.image;
                const name = this.current?.name || 'R';
                return 'https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=0ea5e9&color=fff&size=576&font-size=0.4&bold=true';
            },
            startAutoplay() {
                this.autoplay = setInterval(() => {
                    $wire.nextTestimonial();
                }, 5000);
            },
            stopAutoplay() {
                clearInterval(this.autoplay);
            },
            handleKeydown(e) {
                if (e.key === 'ArrowLeft') {
                    this.stopAutoplay();
                    $wire.prevTestimonial();
                } else if (e.key === 'ArrowRight') {
                    this.stopAutoplay();
                    $wire.nextTestimonial();
                }
            },
            handleTouchStart(e) {
                this.touchStartX = e.changedTouches[0].screenX;
            },
            handleTouchEnd(e) {
                this.touchEndX = e.changedTouches[0].screenX;
                this.handleSwipe();
            },
            handleSwipe() {
                const diff = this.touchStartX - this.touchEndX;
                if (Math.abs(diff) > 50) {
                    this.stopAutoplay();
                    if (diff > 0) {
                        $wire.nextTestimonial();
                    } else {
                        $wire.prevTestimonial();
                    }
                }
            }
        }"
        x-init="startAutoplay()"
        @mouseenter="stopAutoplay()"
        @mouseleave="startAutoplay()"
        @keydown.window="handleKeydown($event)"
        @touchstart="handleTouchStart($event)"
        @touchend="handleTouchEnd($event)"
        class="isolate overflow-hidden bg-white px-6 lg:px-8 dark:bg-slate-950"
    >
        <div class="relative mx-auto max-w-2xl py-12 sm:py-16 lg:max-w-4xl">
            <figure class="grid grid-cols-1 items-start gap-x-6 gap-y-4 lg:grid-cols-[auto_1fr] lg:gap-x-10 lg:gap-y-2">
                {{-- Desktop: Left column with image + reviewer info --}}
                <div class="relative row-span-3 hidden w-48 flex-col lg:flex xl:w-56">
                    {{-- Previous Arrow - centered on image --}}
                    <button
                        wire:click="prevTestimonial"
                        @click="stopAutoplay()"
                        class="absolute -left-14 top-[6rem] z-10 -translate-y-1/2 p-2 text-gray-400 transition hover:text-gray-600 dark:text-white/60 dark:hover:text-white"
                        aria-label="Previous testimonial"
                    >
                        <svg class="size-10" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                        </svg>
                    </button>

                    <img
                        :src="getImage()"
                        :alt="current?.name"
                        class="aspect-square w-full rounded-2xl bg-sky-50 object-cover dark:bg-sky-900/20"
                    />
                    {{-- Reviewer info under image on desktop --}}
                    <div class="mt-4">
                        <div class="font-semibold text-gray-900 dark:text-white" x-text="current?.name"></div>
                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            <template x-if="current?.area_slug">
                                <a :href="'/areas/' + current.area_slug" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400" x-text="current?.location"></a>
                            </template>
                            <template x-if="!current?.area_slug">
                                <span x-text="current?.location"></span>
                            </template>
                            <span x-show="current?.date"> · </span>
                            <span x-text="current?.date"></span>
                        </div>
                    </div>
                </div>

                {{-- Desktop: Right column --}}
                <div class="relative hidden flex-col lg:flex">
                    {{-- 5-star image --}}
                    <div>
                        <img
                            src="{{ asset('images/gs construction five starts.png') }}"
                            alt="5 Stars"
                            class="h-7 w-auto"
                        />
                    </div>

                    {{-- Quote - fixed height on desktop --}}
                    <div class="relative mt-4 h-40">
                        <svg viewBox="0 0 162 128" fill="none" aria-hidden="true" class="absolute -top-12 left-0 -z-10 h-32 stroke-gray-900/10 dark:stroke-white/20">
                            <path id="testimonial-quote-path-desktop" d="M65.5697 118.507L65.8918 118.89C68.9503 116.314 71.367 113.253 73.1386 109.71C74.9162 106.155 75.8027 102.28 75.8027 98.0919C75.8027 94.237 75.16 90.6155 73.8708 87.2314C72.5851 83.8565 70.8137 80.9533 68.553 78.5292C66.4529 76.1079 63.9476 74.2482 61.0407 72.9536C58.2795 71.4949 55.276 70.767 52.0386 70.767C48.9935 70.767 46.4686 71.1668 44.4872 71.9924L44.4799 71.9955L44.4726 71.9988C42.7101 72.7999 41.1035 73.6831 39.6544 74.6492C38.2407 75.5916 36.8279 76.455 35.4159 77.2394L35.4047 77.2457L35.3938 77.2525C34.2318 77.9787 32.6713 78.3634 30.6736 78.3634C29.0405 78.3634 27.5131 77.2868 26.1274 74.8257C24.7483 72.2185 24.0519 69.2166 24.0519 65.8071C24.0519 60.0311 25.3782 54.4081 28.0373 48.9335C30.703 43.4454 34.3114 38.345 38.8667 33.6325C43.5812 28.761 49.0045 24.5159 55.1389 20.8979C60.1667 18.0071 65.4966 15.6179 71.1291 13.7305C73.8626 12.8145 75.8027 10.2968 75.8027 7.38572C75.8027 3.6497 72.6341 0.62247 68.8814 1.1527C61.1635 2.2432 53.7398 4.41426 46.6119 7.66522C37.5369 11.6459 29.5729 17.0612 22.7236 23.9105C16.0322 30.6019 10.618 38.4859 6.47981 47.558L6.47976 47.558L6.47682 47.5647C2.4901 56.6544 0.5 66.6148 0.5 77.4391C0.5 84.2996 1.61702 90.7679 3.85425 96.8404L3.8558 96.8445C6.08991 102.749 9.12394 108.02 12.959 112.654L12.959 112.654L12.9646 112.661C16.8027 117.138 21.2829 120.739 26.4034 123.459L26.4033 123.459L26.4144 123.465C31.5505 126.033 37.0873 127.316 43.0178 127.316C47.5035 127.316 51.6783 126.595 55.5376 125.148L55.5376 125.148L55.5477 125.144C59.5516 123.542 63.0052 121.456 65.9019 118.881L65.5697 118.507Z" />
                            <use x="86" href="#testimonial-quote-path-desktop" />
                        </svg>
                        <blockquote class="text-xl/8 text-gray-900 dark:text-white">
                            <p
                                x-text="current?.description"
                                class="line-clamp-5 italic transition-opacity duration-300"
                            ></p>
                        </blockquote>
                    </div>

                    {{-- Desktop: Read More button --}}
                    <div class="mt-6">
                        <a
                            href="{{ $area ? route('area.testimonials', $area) : route('testimonials.index') }}"
                            class="inline-flex items-center rounded-md bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg transition hover:bg-sky-600"
                            @click="trackCTA('Read More Testimonials', 'testimonials_section_desktop')"
                        >
                            Read More
                        </a>
                    </div>

                    {{-- Next Arrow - positioned to the right, centered on image --}}
                    <button
                        wire:click="nextTestimonial"
                        @click="stopAutoplay()"
                        class="absolute -right-14 top-[6rem] z-10 -translate-y-1/2 p-2 text-gray-400 transition hover:text-gray-600 dark:text-white/60 dark:hover:text-white"
                        aria-label="Next testimonial"
                    >
                        <svg class="size-10" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                </div>

                {{-- Mobile: Stars + Quote --}}
                <div class="lg:hidden">
                    {{-- 5-star image --}}
                    <div>
                        <img
                            src="{{ asset('images/gs construction five starts.png') }}"
                            alt="5 Stars"
                            class="h-6 w-auto"
                        />
                    </div>

                    {{-- Quote --}}
                    <div class="relative mt-4">
                        <svg viewBox="0 0 162 128" fill="none" aria-hidden="true" class="absolute -top-12 left-0 -z-10 h-32 stroke-gray-900/10 dark:stroke-white/20">
                            <path id="testimonial-quote-path-mobile" d="M65.5697 118.507L65.8918 118.89C68.9503 116.314 71.367 113.253 73.1386 109.71C74.9162 106.155 75.8027 102.28 75.8027 98.0919C75.8027 94.237 75.16 90.6155 73.8708 87.2314C72.5851 83.8565 70.8137 80.9533 68.553 78.5292C66.4529 76.1079 63.9476 74.2482 61.0407 72.9536C58.2795 71.4949 55.276 70.767 52.0386 70.767C48.9935 70.767 46.4686 71.1668 44.4872 71.9924L44.4799 71.9955L44.4726 71.9988C42.7101 72.7999 41.1035 73.6831 39.6544 74.6492C38.2407 75.5916 36.8279 76.455 35.4159 77.2394L35.4047 77.2457L35.3938 77.2525C34.2318 77.9787 32.6713 78.3634 30.6736 78.3634C29.0405 78.3634 27.5131 77.2868 26.1274 74.8257C24.7483 72.2185 24.0519 69.2166 24.0519 65.8071C24.0519 60.0311 25.3782 54.4081 28.0373 48.9335C30.703 43.4454 34.3114 38.345 38.8667 33.6325C43.5812 28.761 49.0045 24.5159 55.1389 20.8979C60.1667 18.0071 65.4966 15.6179 71.1291 13.7305C73.8626 12.8145 75.8027 10.2968 75.8027 7.38572C75.8027 3.6497 72.6341 0.62247 68.8814 1.1527C61.1635 2.2432 53.7398 4.41426 46.6119 7.66522C37.5369 11.6459 29.5729 17.0612 22.7236 23.9105C16.0322 30.6019 10.618 38.4859 6.47981 47.558L6.47976 47.558L6.47682 47.5647C2.4901 56.6544 0.5 66.6148 0.5 77.4391C0.5 84.2996 1.61702 90.7679 3.85425 96.8404L3.8558 96.8445C6.08991 102.749 9.12394 108.02 12.959 112.654L12.959 112.654L12.9646 112.661C16.8027 117.138 21.2829 120.739 26.4034 123.459L26.4033 123.459L26.4144 123.465C31.5505 126.033 37.0873 127.316 43.0178 127.316C47.5035 127.316 51.6783 126.595 55.5376 125.148L55.5376 125.148L55.5477 125.144C59.5516 123.542 63.0052 121.456 65.9019 118.881L65.5697 118.507Z" />
                            <use x="86" href="#testimonial-quote-path-mobile" />
                        </svg>
                        <blockquote class="text-lg/7 text-gray-900 dark:text-white">
                            <p
                                x-text="current?.description"
                                class="line-clamp-5 italic transition-opacity duration-300"
                            ></p>
                        </blockquote>
                    </div>
                </div>

                {{-- Mobile: Reviewer info with image + Read More --}}
                <figcaption class="text-base lg:hidden">
                    <div class="flex items-center gap-4">
                        <img
                            :src="getImage()"
                            :alt="current?.name"
                            class="size-14 rounded-xl bg-sky-50 object-cover dark:bg-sky-900/20"
                        />
                        <div>
                            <div class="font-semibold text-gray-900 dark:text-white" x-text="current?.name"></div>
                            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                <template x-if="current?.area_slug">
                                    <a :href="'/areas/' + current.area_slug" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400" x-text="current?.location"></a>
                                </template>
                                <template x-if="!current?.area_slug">
                                    <span x-text="current?.location"></span>
                                </template>
                                <span x-show="current?.date"> · </span>
                                <span x-text="current?.date"></span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a
                            href="{{ $area ? route('area.testimonials', $area) : route('testimonials.index') }}"
                            class="inline-flex items-center rounded-md bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg transition hover:bg-sky-600"
                            @click="trackCTA('Read More Testimonials', 'testimonials_section_mobile')"
                        >
                            Read More
                        </a>
                    </div>
                </figcaption>
            </figure>

            {{-- Mobile arrows --}}
            <button
                wire:click="prevTestimonial"
                @click="stopAutoplay()"
                class="absolute left-0 top-1/3 z-10 -translate-y-1/2 p-2 text-gray-400 transition hover:text-gray-600 dark:text-white/60 dark:hover:text-white lg:hidden"
                aria-label="Previous testimonial"
            >
                <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
            </button>
            <button
                wire:click="nextTestimonial"
                @click="stopAutoplay()"
                class="absolute right-0 top-1/3 z-10 -translate-y-1/2 p-2 text-gray-400 transition hover:text-gray-600 dark:text-white/60 dark:hover:text-white lg:hidden"
                aria-label="Next testimonial"
            >
                <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </button>
        </div>
    </section>
</div>
