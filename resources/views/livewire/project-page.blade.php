<div class="bg-white dark:bg-zinc-900">
    {{-- Project Schema with ImageObject data --}}
    <x-project-schema :project="$project" />

    {{-- Breadcrumb Schema --}}
    @php
        $breadcrumbItems = [
            ['name' => 'Projects', 'url' => route('projects.index')],
        ];
        if ($project->project_type) {
            $breadcrumbItems[] = [
                'name' => $projectTypeLabel,
                'url' => route('projects.index', ['type' => $project->project_type]),
            ];
        }
        $breadcrumbItems[] = ['name' => $project->title];
    @endphp
    <x-breadcrumb-schema :items="$breadcrumbItems" />

    {{-- Visual Breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="{{ route('home') }}" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="{{ route('projects.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Projects</a>
                </li>
                @if($project->project_type)
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="{{ route('projects.index', ['type' => $project->project_type]) }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">{{ $projectTypeLabel }}</a>
                </li>
                @endif
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $project->title }}</span>
                </li>
            </ol>
        </nav>
    </div>

    {{-- Main Content --}}
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
        {{-- Project Header --}}
        <div class="mb-8">
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <span class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-sm font-medium text-sky-800 dark:bg-sky-900/30 dark:text-sky-300">
                    {{ $projectTypeLabel }}
                </span>
                @if($project->location)
                    <span class="inline-flex items-center text-sm text-gray-500 dark:text-gray-400">
                        <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        {{ $project->location }}
                    </span>
                @endif
                @if($project->completed_at)
                    <span class="inline-flex items-center text-sm text-gray-500 dark:text-gray-400">
                        <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        {{ $project->completed_at->format('F Y') }}
                    </span>
                @endif
            </div>
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                {{ $project->title }}
            </h1>
            @if($project->description)
                <p class="mt-4 text-lg text-gray-600 dark:text-gray-400">
                    {{ $project->description }}
                </p>
            @endif
        </div>

        {{-- Image Gallery --}}
        @if($project->images->isNotEmpty())
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($project->images as $image)
                    <div class="group relative aspect-[4/3] overflow-hidden rounded-xl bg-gray-100 dark:bg-zinc-800">
                        <x-lqip-image 
                            :image="$image"
                            size="large"
                            aspectRatio="4/3"
                            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                        />
                        @if($image->caption)
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent p-4 opacity-0 transition-opacity group-hover:opacity-100">
                                <p class="text-sm text-white">{{ $image->caption }}</p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Related Projects --}}
    @if($relatedProjects->isNotEmpty())
        <div class="border-t border-gray-200 dark:border-zinc-700">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white mb-8">
                    More {{ $projectTypeLabel }} Projects
                </h2>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($relatedProjects as $related)
                        <a href="{{ route('projects.show', $related) }}" wire:navigate class="group block">
                            <div class="relative aspect-[4/3] overflow-hidden rounded-xl bg-gray-100 dark:bg-zinc-800">
                                @if($related->images->first())
                                    <x-lqip-image 
                                        :image="$related->images->first()"
                                        size="medium"
                                        aspectRatio="4/3"
                                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                    />
                                @endif
                            </div>
                            <h3 class="mt-3 text-lg font-semibold text-gray-900 group-hover:text-sky-600 dark:text-white dark:group-hover:text-sky-400">
                                {{ $related->title }}
                            </h3>
                            @if($related->location)
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $related->location }}</p>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- CTA Section --}}
    <x-cta-section 
        variant="blue"
        heading="Ready to Start Your Project?"
        description="Get a free consultation and quote for your remodeling project. We're ready to bring your vision to life."
        primaryCtaText="Get a Free Quote"
        primaryCtaUrl="/contact"
        secondaryCtaText="View All Projects"
        secondaryCtaUrl="/projects"
    />
</div>
