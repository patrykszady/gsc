<div class="bg-white dark:bg-zinc-900">
    {{-- Breadcrumb --}}
    <div class="mx-auto max-w-3xl px-6 pt-8 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol role="list" class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="{{ route('home') }}" wire:navigate class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="{{ route('testimonials.index') }}" wire:navigate class="ml-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Testimonials</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $testimonial->reviewer_name }}</span>
                </li>
            </ol>
        </nav>
    </div>

    {{-- Main Content --}}
    <div class="mx-auto max-w-3xl px-6 py-12 lg:px-8 lg:py-16">
        {{-- Project Thumbnail --}}
        @if($thumbnailUrl)
            <div class="mb-8 overflow-hidden rounded-2xl">
                <img 
                    src="{{ $thumbnailUrl }}" 
                    alt="{{ ucfirst($testimonial->project_type ?? 'Project') }} remodeling in {{ $testimonial->project_location }}" 
                    class="aspect-[16/9] w-full object-cover"
                />
            </div>
        @endif

        <article class="relative">
            {{-- Quote decoration --}}
            <svg viewBox="0 0 162 128" fill="none" aria-hidden="true" class="absolute -top-4 -left-8 -z-10 h-24 stroke-gray-200 dark:stroke-gray-700">
                <path id="testimonial-quote-path" d="M65.5697 118.507L65.8918 118.89C68.9503 116.314 71.367 113.253 73.1386 109.71C74.9162 106.155 75.8027 102.28 75.8027 98.0919C75.8027 94.237 75.16 90.6155 73.8708 87.2314C72.5851 83.8565 70.8137 80.9533 68.553 78.5292C66.4529 76.1079 63.9476 74.2482 61.0407 72.9536C58.2795 71.4949 55.276 70.767 52.0386 70.767C48.9935 70.767 46.4686 71.1668 44.4872 71.9924L44.4799 71.9955L44.4726 71.9988C42.7101 72.7999 41.1035 73.6831 39.6544 74.6492C38.2407 75.5916 36.8279 76.455 35.4159 77.2394L35.4047 77.2457L35.3938 77.2525C34.2318 77.9787 32.6713 78.3634 30.6736 78.3634C29.0405 78.3634 27.5131 77.2868 26.1274 74.8257C24.7483 72.2185 24.0519 69.2166 24.0519 65.8071C24.0519 60.0311 25.3782 54.4081 28.0373 48.9335C30.703 43.4454 34.3114 38.345 38.8667 33.6325C43.5812 28.761 49.0045 24.5159 55.1389 20.8979C60.1667 18.0071 65.4966 15.6179 71.1291 13.7305C73.8626 12.8145 75.8027 10.2968 75.8027 7.38572C75.8027 3.6497 72.6341 0.62247 68.8814 1.1527C61.1635 2.2432 53.7398 4.41426 46.6119 7.66522C37.5369 11.6459 29.5729 17.0612 22.7236 23.9105C16.0322 30.6019 10.618 38.4859 6.47981 47.558L6.47976 47.558L6.47682 47.5647C2.4901 56.6544 0.5 66.6148 0.5 77.4391C0.5 84.2996 1.61702 90.7679 3.85425 96.8404L3.8558 96.8445C6.08991 102.749 9.12394 108.02 12.959 112.654L12.959 112.654L12.9646 112.661C16.8027 117.138 21.2829 120.739 26.4034 123.459L26.4033 123.459L26.4144 123.465C31.5505 126.033 37.0873 127.316 43.0178 127.316C47.5035 127.316 51.6783 126.595 55.5376 125.148L55.5376 125.148L55.5477 125.144C59.5516 123.542 63.0052 121.456 65.9019 118.881L65.5697 118.507Z" />
                <use x="86" href="#testimonial-quote-path" />
            </svg>

            {{-- 5 stars --}}
            <div class="mb-6">
                <img src="{{ asset('images/gs construction five starts.png') }}" alt="5 Stars" class="h-8 w-auto" />
            </div>

            {{-- Review text --}}
            <blockquote class="text-xl leading-8 text-gray-900 sm:text-2xl sm:leading-9 dark:text-white">
                <p>"{{ $testimonial->review_description }}"</p>
            </blockquote>

            {{-- Reviewer info --}}
            <figcaption class="mt-10 flex items-center gap-x-6 border-t border-gray-200 pt-10 dark:border-gray-700">
                <img 
                    src="{{ $imageUrl }}" 
                    alt="{{ $testimonial->reviewer_name }}" 
                    class="size-16 rounded-full bg-gray-50 object-cover dark:bg-gray-700" 
                />
                <div>
                    <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ $testimonial->reviewer_name }}</div>
                    <div class="mt-1 text-base text-gray-600 dark:text-gray-400">
                        @if($areaSlug)
                            <a href="/areas/{{ $areaSlug }}" wire:navigate class="hover:text-sky-600 hover:underline dark:hover:text-sky-400">{{ $testimonial->project_location }}</a>
                        @else
                            {{ $testimonial->project_location }}
                        @endif
                        @if($testimonial->review_date)
                            <span class="mx-2">·</span>
                            <span>{{ $testimonial->review_date->format('F Y') }}</span>
                        @endif
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        @if($testimonial->project_type)
                            <span class="inline-flex items-center rounded-full bg-sky-50 px-3 py-1 text-sm font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20 dark:bg-sky-900/30 dark:text-sky-300 dark:ring-sky-500/30">
                                {{ ucfirst($testimonial->project_type) }}
                            </span>
                        @endif
                        @if($testimonial->review_url)
                            <a 
                                href="{{ $testimonial->review_url }}" 
                                target="_blank" 
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 text-sm font-medium text-sky-600 hover:text-sky-500 dark:text-sky-400 dark:hover:text-sky-300"
                            >
                                Original Review
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                </svg>
                            </a>
                        @endif
                    </div>
                </div>
            </figcaption>

        </article>

        {{-- Back link --}}
        <div class="relative z-10 mt-12 border-t border-gray-200 pt-8 dark:border-gray-700">
            <a 
                href="/testimonials"
                class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Back to all testimonials
            </a>
        </div>
    </div>

    {{-- More Testimonials Section --}}
    <livewire:testimonials-section :show-header="false" />
</div>
