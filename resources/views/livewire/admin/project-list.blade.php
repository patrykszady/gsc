<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl">Projects</flux:heading>
        <flux:button href="{{ route('admin.projects.create') }}" icon="plus">
            New Project
        </flux:button>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search projects..." 
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-48">
                <flux:select wire:model.live="type">
                    <flux:select.option value="">All Types</flux:select.option>
                    @foreach($projectTypes as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="w-40">
                <flux:select wire:model.live="status">
                    <flux:select.option value="">All Status</flux:select.option>
                    <flux:select.option value="published">Published</flux:select.option>
                    <flux:select.option value="draft">Draft</flux:select.option>
                    <flux:select.option value="featured">Featured</flux:select.option>
                </flux:select>
            </div>
        </div>
    </flux:card>

    {{-- Success Message --}}
    @if(session('success'))
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            {{ session('success') }}
        </flux:callout>
    @endif

    {{-- Projects Grid --}}
    @if($projects->isEmpty())
        <flux:card>
            <div class="py-12 text-center">
                <flux:icon.folder class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <h3 class="mt-4 text-lg font-medium text-zinc-900 dark:text-white">No projects found</h3>
                <p class="mt-1 text-zinc-500 dark:text-zinc-400">
                    @if($search || $type || $status)
                        Try adjusting your filters
                    @else
                        Get started by creating a new project
                    @endif
                </p>
                @if(!$search && !$type && !$status)
                    <div class="mt-6">
                        <flux:button href="{{ route('admin.projects.create') }}" icon="plus">
                            New Project
                        </flux:button>
                    </div>
                @endif
            </div>
        </flux:card>
    @else
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($projects as $project)
                <flux:card class="group overflow-hidden !p-0">
                    {{-- Image --}}
                    <div class="relative aspect-[4/3] bg-zinc-100 dark:bg-zinc-800">
                        @if($project->coverImage)
                            <img 
                                src="{{ $project->coverImage->getThumbnailUrl('medium') }}" 
                                alt="{{ $project->title }}"
                                class="size-full object-cover"
                            >
                        @else
                            <div class="flex size-full items-center justify-center">
                                <flux:icon.photo class="size-12 text-zinc-300 dark:text-zinc-600" />
                            </div>
                        @endif

                        {{-- Overlay actions --}}
                        <div class="absolute inset-0 flex items-center justify-center gap-2 bg-black/50 opacity-0 transition-opacity group-hover:opacity-100">
                            <flux:button href="{{ route('admin.projects.edit', $project) }}" size="sm" icon="pencil">
                                Edit
                            </flux:button>
                        </div>

                        {{-- Badges --}}
                        <div class="absolute left-3 top-3 flex flex-wrap gap-1">
                            @if($project->is_featured)
                                <flux:badge color="amber" size="sm">Featured</flux:badge>
                            @endif
                            @if(!$project->is_published)
                                <flux:badge color="zinc" size="sm">Draft</flux:badge>
                            @endif
                        </div>

                        {{-- Image count --}}
                        <div class="absolute bottom-3 right-3">
                            <flux:badge size="sm" class="!bg-black/60 !text-white">
                                <flux:icon.photo class="mr-1 size-3" />
                                {{ $project->images_count }}
                            </flux:badge>
                        </div>
                    </div>

                    {{-- Content --}}
                    <div class="p-4">
                        <div class="mb-2 flex items-start justify-between gap-2">
                            <h3 class="font-medium text-zinc-900 dark:text-white">{{ $project->title }}</h3>
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil" href="{{ route('admin.projects.edit', $project) }}">
                                        Edit
                                    </flux:menu.item>
                                    <flux:menu.item 
                                        icon="{{ $project->is_published ? 'eye-slash' : 'eye' }}" 
                                        wire:click="togglePublished({{ $project->id }})"
                                    >
                                        {{ $project->is_published ? 'Unpublish' : 'Publish' }}
                                    </flux:menu.item>
                                    <flux:menu.item 
                                        icon="{{ $project->is_featured ? 'star' : 'star' }}" 
                                        wire:click="toggleFeatured({{ $project->id }})"
                                    >
                                        {{ $project->is_featured ? 'Unfeature' : 'Feature' }}
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item 
                                        icon="trash" 
                                        variant="danger"
                                        wire:click="delete({{ $project->id }})"
                                        wire:confirm="Are you sure you want to delete this project? This will also delete all images."
                                    >
                                        Delete
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                        <flux:badge size="sm">{{ $projectTypes[$project->project_type] ?? $project->project_type }}</flux:badge>
                        @if($project->location)
                            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $project->location }}</p>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-6">
            {{ $projects->links() }}
        </div>
    @endif
</div>
