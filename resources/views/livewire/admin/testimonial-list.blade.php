<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">Reviews</flux:heading>
        <flux:button href="{{ route('admin.testimonials.create') }}" icon="plus">
            New Review
        </flux:button>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search reviews..." 
                    icon="magnifying-glass"
                />
            </div>
            @if($projectTypes->isNotEmpty())
            <div class="w-48">
                <flux:select wire:model.live="type">
                    <flux:select.option value="">All Types</flux:select.option>
                    @foreach($projectTypes as $projectType)
                        <flux:select.option value="{{ $projectType }}">{{ $projectType }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            @endif
        </div>
    </flux:card>

    {{-- Success Message --}}
    @if(session('success'))
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            {{ session('success') }}
        </flux:callout>
    @endif

    {{-- Reviews List --}}
    @if($testimonials->isEmpty())
        <flux:card>
            <div class="py-12 text-center">
                <flux:icon.star class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <h3 class="mt-4 text-lg font-medium text-zinc-900 dark:text-white">No reviews found</h3>
                <p class="mt-1 text-zinc-500 dark:text-zinc-400">
                    @if($search || $type)
                        Try adjusting your filters
                    @else
                        Get started by adding a new review
                    @endif
                </p>
                @if(!$search && !$type)
                    <div class="mt-6">
                        <flux:button href="{{ route('admin.testimonials.create') }}" icon="plus">
                            New Review
                        </flux:button>
                    </div>
                @endif
            </div>
        </flux:card>
    @else
        <div class="space-y-4">
            @foreach($testimonials as $testimonial)
                <flux:card class="group">
                    <div class="flex items-start gap-4">
                        {{-- Avatar/Image --}}
                        <div class="flex-shrink-0">
                            @if($testimonial->review_image)
                                <img 
                                    src="{{ $testimonial->review_image }}" 
                                    alt="{{ $testimonial->reviewer_name }}"
                                    class="size-12 rounded-full object-cover"
                                >
                            @else
                                <div class="flex size-12 items-center justify-center rounded-full bg-sky-100 dark:bg-sky-900">
                                    <span class="text-lg font-semibold text-sky-600 dark:text-sky-400">
                                        {{ strtoupper(substr($testimonial->reviewer_name, 0, 1)) }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        {{-- Content --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="font-medium text-zinc-900 dark:text-white">
                                        {{ $testimonial->reviewer_name }}
                                    </h3>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                                        @if($testimonial->project_location)
                                            <span>{{ $testimonial->project_location }}</span>
                                        @endif
                                        @if($testimonial->project_location && $testimonial->project_type)
                                            <span>•</span>
                                        @endif
                                        @if($testimonial->project_type)
                                            <flux:badge size="sm">{{ $testimonial->project_type }}</flux:badge>
                                        @endif
                                        @if($testimonial->review_date)
                                            <span>•</span>
                                            <span>{{ $testimonial->review_date->format('M j, Y') }}</span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Actions --}}
                                <div class="flex items-center gap-2">
                                    @if($testimonial->review_url)
                                        <flux:button 
                                            variant="ghost" 
                                            size="sm" 
                                            icon="arrow-top-right-on-square"
                                            href="{{ $testimonial->review_url }}"
                                            target="_blank"
                                            title="View original review"
                                        />
                                    @endif
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" href="{{ route('admin.testimonials.edit', $testimonial) }}">
                                                Edit
                                            </flux:menu.item>
                                            <flux:menu.item icon="eye" href="{{ route('testimonials.show', $testimonial) }}" target="_blank">
                                                View on site
                                            </flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item 
                                                icon="trash" 
                                                variant="danger"
                                                wire:click="delete({{ $testimonial->id }})"
                                                wire:confirm="Are you sure you want to delete this review?"
                                            >
                                                Delete
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>

                            {{-- Review text --}}
                            <p class="mt-3 text-sm text-zinc-600 line-clamp-3 dark:text-zinc-300">
                                {{ $testimonial->review_description }}
                            </p>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-6">
            {{ $testimonials->links() }}
        </div>
    @endif
</div>
