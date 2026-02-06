<div>
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <flux:button href="{{ route('admin.projects.index') }}" variant="ghost" icon="arrow-left" />
            <flux:heading size="xl">{{ $project?->exists ? 'Edit Project' : 'New Project' }}</flux:heading>
        </div>
    </div>

    {{-- Success Message --}}
    @if(session('success'))
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            {{ session('success') }}
        </flux:callout>
    @endif

    <form wire:submit="save">
        <div class="grid gap-6 lg:grid-cols-3 lg:items-start">
            {{-- Main Content --}}
            <div class="space-y-6 lg:col-span-2">
                {{-- Basic Info --}}
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Project Details</flux:heading>
                    
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Title</flux:label>
                            <flux:input wire:model="title" placeholder="e.g., Modern Kitchen Remodel" />
                            <flux:error name="title" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Description</flux:label>
                            <flux:textarea wire:model="description" rows="4" placeholder="Describe the project..." />
                            <flux:error name="description" />
                        </flux:field>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Project Type</flux:label>
                                <flux:select wire:model="project_type">
                                    @foreach($projectTypes as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="project_type" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Location</flux:label>
                                <flux:input wire:model="location" placeholder="e.g., Arlington Heights, IL" />
                                <flux:error name="location" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Completion Date</flux:label>
                            <flux:input wire:model="completed_month" type="month" />
                            <flux:error name="completed_month" />
                        </flux:field>
                    </div>
                </flux:card>

                {{-- Image Upload --}}
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Images</flux:heading>

                    {{-- Upload Zone with Progress --}}
                    <flux:file-upload wire:model="uploads" multiple :disabled="$errors->has('uploads')">
                        <flux:file-upload.dropzone 
                            heading="Drop files here or click to browse" 
                            text="PNG, JPG, WebP up to 10MB each"
                            with-progress
                        />
                    </flux:file-upload>

                    {{-- Upload Errors --}}
                    @if($errors->has('uploads') || $errors->has('uploads.*'))
                        <div class="mt-4 space-y-1">
                            @foreach($errors->get('uploads') as $message)
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @endforeach
                            @foreach($errors->get('uploads.*') as $messages)
                                @foreach($messages as $message)
                                    <p class="text-sm text-red-600">{{ $message }}</p>
                                @endforeach
                            @endforeach
                        </div>
                    @endif

                    {{-- Uploaded Files List --}}
                    @if(count($uploads) > 0)
                        <div class="mt-4">
                            <h4 class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">New Uploads</h4>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                @foreach($uploads as $index => $upload)
                                    <div class="group relative aspect-square overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800 {{ in_array($index, $duplicateIndices) ? 'ring-2 ring-amber-500' : '' }}" title="{{ $upload->getClientOriginalName() }}">
                                        <img 
                                            src="{{ $upload->temporaryUrl() }}" 
                                            alt="{{ $upload->getClientOriginalName() }}" 
                                            class="size-full object-cover {{ in_array($index, $duplicateIndices) ? 'opacity-50 grayscale' : '' }}"
                                        >
                                        
                                        {{-- Duplicate badge --}}
                                        @if(in_array($index, $duplicateIndices))
                                            <div class="absolute left-2 top-2">
                                                <flux:badge color="amber" size="sm">Duplicate</flux:badge>
                                            </div>
                                        @endif

                                        {{-- Remove button --}}
                                        <button 
                                            type="button"
                                            wire:click="$removeUpload('uploads', '{{ $upload->getFilename() }}')"
                                            class="absolute right-2 top-2 rounded-full bg-red-500 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100"
                                        >
                                            <flux:icon.x-mark class="size-4" />
                                        </button>
                                        
                                        {{-- Filename overlay --}}
                                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent p-2">
                                            <p class="truncate text-xs text-white">{{ $upload->getClientOriginalName() }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Existing Images --}}
                    @if(count($existingImages) > 0)
                        <div class="mt-4">
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Project Images</h4>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if(count($selectedImageIds) > 0)
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ count($selectedImageIds) }} selected
                                        </span>
                                    @endif
                                    <flux:button type="button" size="sm" variant="ghost" wire:click="selectAllImages">
                                        Select all
                                    </flux:button>
                                    <flux:button type="button" size="sm" variant="ghost" wire:click="clearSelectedImages">
                                        Clear
                                    </flux:button>
                                    <flux:button type="button" size="sm" variant="primary" wire:click="assignTagToSelected">
                                        Apply
                                    </flux:button>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                @foreach($existingImages as $image)
                                    <div
                                        class="group relative aspect-square cursor-pointer overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800 {{ in_array($image['id'], $selectedImageIds) ? 'ring-2 ring-sky-500' : '' }}"
                                        wire:click="toggleImageSelection({{ $image['id'] }})"
                                        role="button"
                                    >
                                        <img src="{{ $image['url'] }}" alt="{{ $image['alt_text'] }}" class="size-full object-cover">

                                        <div class="absolute left-2 top-2 z-10">
                                            <label class="inline-flex items-center justify-center rounded-full bg-black/60 p-1" wire:click.stop>
                                                <input type="checkbox" class="size-4 accent-sky-500" wire:model="selectedImageIds" value="{{ $image['id'] }}" wire:click.stop>
                                            </label>
                                        </div>
                                        
                                        {{-- Cover badge --}}
                                        @if($image['is_cover'])
                                            <div class="absolute left-2 top-2">
                                                <flux:badge color="sky" size="sm">Cover</flux:badge>
                                            </div>
                                        @endif

                                        {{-- Actions overlay --}}
                                        <div class="absolute inset-0 flex items-center justify-center gap-1 bg-black/50 opacity-0 transition-opacity group-hover:opacity-100">
                                            @if(!$image['is_cover'])
                                                <button 
                                                    type="button"
                                                    wire:click="setCoverImage({{ $image['id'] }})"
                                                    wire:click.stop
                                                    class="rounded-full bg-white p-2 text-zinc-700 hover:bg-zinc-100"
                                                    title="Set as cover"
                                                >
                                                    <flux:icon.star class="size-4" />
                                                </button>
                                            @endif
                                            <button 
                                                type="button"
                                                wire:click="removeExistingImage({{ $image['id'] }})"
                                                wire:click.stop
                                                wire:confirm="Delete this image?"
                                                class="rounded-full bg-red-500 p-2 text-white hover:bg-red-600"
                                                title="Delete"
                                            >
                                                <flux:icon.trash class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @error('uploads.*')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </flux:card>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6 lg:sticky lg:top-6 lg:self-start lg:h-fit">
                {{-- Publish Settings --}}
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Publish</flux:heading>
                    
                    <div class="space-y-4">
                        <flux:switch wire:model="is_published" label="Published" description="Make this project visible on the website" />
                        <flux:switch wire:model="is_featured" label="Featured" description="Show in featured projects section" />
                    </div>

                    <div class="mt-6 flex gap-2">
                        <flux:button type="submit" variant="primary" class="flex-1">
                            {{ $project?->exists ? 'Update' : 'Create' }} Project
                        </flux:button>
                    </div>
                </flux:card>

                @if(count($existingImages) > 0)
                    {{-- Assign Tag --}}
                    <flux:card>
                        <flux:heading size="lg" class="mb-4">Assign Tag</flux:heading>
                        <div class="space-y-2">
                            <flux:select wire:model="bulkTagId" variant="combobox" size="sm">
                                <x-slot name="input">
                                    <flux:select.input wire:model.live="tagSearch" placeholder="Select or create tag..." />
                                </x-slot>
                                @foreach($allTags as $tag)
                                    <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
                                @endforeach
                                <flux:select.option.create wire:click="createTagFromSearch" min-length="2">
                                    Create "<span wire:text="tagSearch"></span>"
                                </flux:select.option.create>
                            </flux:select>
                            <div class="flex flex-wrap gap-1">
                                @foreach($allTags as $tag)
                                    <flux:badge
                                        size="sm"
                                        color="{{ $bulkTagId === $tag->id ? 'sky' : 'zinc' }}"
                                        class="cursor-pointer"
                                        wire:click="selectBulkTag({{ $tag->id }})"
                                    >
                                        {{ $tag->name }}
                                    </flux:badge>
                                @endforeach
                            </div>
                        </div>
                    </flux:card>

                    {{-- Image Tags --}}
                    <flux:card>
                        <flux:heading size="lg" class="mb-4">Image Tags</flux:heading>
                        @php
                            $selectedImages = collect($existingImages)
                                ->filter(fn($img) => in_array($img['id'], $selectedImageIds));
                        @endphp
                        @if($selectedImages->isEmpty())
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Select images to view tags.
                            </p>
                        @else
                            <div class="space-y-3">
                                @foreach($selectedImages as $image)
                                    <div class="flex items-start gap-2">
                                        <img src="{{ $image['url'] }}" alt="" class="size-10 rounded object-cover">
                                        <div class="flex flex-wrap gap-1">
                                            @forelse($image['tags'] as $tag)
                                                <flux:badge size="sm" color="zinc">{{ $tag['name'] }}</flux:badge>
                                            @empty
                                                <span class="text-xs text-zinc-400">No tags</span>
                                            @endforelse
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </flux:card>
                @endif

            </div>
        </div>
    </form>
</div>
