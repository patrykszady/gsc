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
        <div class="grid gap-6 lg:grid-cols-3">
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

                    {{-- Upload Zone --}}
                    <div 
                        x-data="{ isDragging: false }"
                        x-on:dragover.prevent="isDragging = true"
                        x-on:dragleave.prevent="isDragging = false"
                        x-on:drop.prevent="isDragging = false; $refs.fileInput.files = $event.dataTransfer.files; $refs.fileInput.dispatchEvent(new Event('change'))"
                        :class="{ 'border-sky-500 bg-sky-50 dark:bg-sky-900/20': isDragging }"
                        class="relative mb-4 rounded-lg border-2 border-dashed border-zinc-300 p-8 text-center transition-colors dark:border-zinc-600"
                    >
                        <input 
                            type="file" 
                            wire:model="uploads" 
                            x-ref="fileInput"
                            multiple 
                            accept="image/*"
                            class="absolute inset-0 size-full cursor-pointer opacity-0"
                        >
                        <flux:icon.cloud-arrow-up class="mx-auto size-12 text-zinc-400" />
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            <span class="font-semibold text-sky-500">Click to upload</span> or drag and drop
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">PNG, JPG, WebP up to 10MB each</p>
                    </div>

                    {{-- Upload Errors --}}
                    @if($errors->has('uploads') || $errors->has('uploads.*'))
                        <div class="mb-4 space-y-1">
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

                    {{-- Upload Progress --}}
                    <div wire:loading wire:target="uploads" class="mb-4">
                        <flux:callout icon="arrow-path" class="animate-pulse">
                            Uploading images...
                        </flux:callout>
                    </div>

                    {{-- New Uploads Preview --}}
                    @if(count($uploads) > 0)
                        <div class="mb-4">
                            <h4 class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">New Uploads</h4>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                {{-- Non-duplicates first --}}
                                @foreach($uploads as $index => $upload)
                                    @if(!in_array($index, $duplicateIndices))
                                        @php
                                            $previewUrl = null;
                                            $previewError = null;
                                            try {
                                                $previewUrl = $upload->temporaryUrl();
                                            } catch (\Exception $e) {
                                                $previewError = $e->getMessage();
                                            }
                                        @endphp
                                        <div class="group relative aspect-square overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800" title="{{ $previewError ?? $upload->getClientOriginalName() }}">
                                            @if($previewUrl)
                                                <img src="{{ $previewUrl }}" alt="Upload preview" class="size-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="hidden size-full flex-col items-center justify-center text-red-400">
                                                    <flux:icon.exclamation-circle class="size-8" />
                                                    <span class="mt-1 text-xs">Failed to load</span>
                                                </div>
                                            @else
                                                <div class="flex size-full flex-col items-center justify-center text-zinc-400">
                                                    <flux:icon.photo class="size-8" />
                                                    <span class="mt-1 text-xs">{{ $upload->getClientOriginalName() }}</span>
                                                </div>
                                            @endif
                                            <button 
                                                type="button"
                                                wire:click="removeUpload({{ $index }})"
                                                class="absolute right-2 top-2 rounded-full bg-red-500 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100"
                                            >
                                                <flux:icon.x-mark class="size-4" />
                                            </button>
                                        </div>
                                    @endif
                                @endforeach
                                
                                {{-- Duplicates after --}}
                                @foreach($uploads as $index => $upload)
                                    @if(in_array($index, $duplicateIndices))
                                        @php
                                            $previewUrl = null;
                                            $previewError = null;
                                            try {
                                                $previewUrl = $upload->temporaryUrl();
                                            } catch (\Exception $e) {
                                                $previewError = $e->getMessage();
                                            }
                                        @endphp
                                        <div class="group relative aspect-square overflow-hidden rounded-lg bg-zinc-100 opacity-50 dark:bg-zinc-800" title="{{ $previewError ?? $upload->getClientOriginalName() }}">
                                            @if($previewUrl)
                                                <img src="{{ $previewUrl }}" alt="Upload preview" class="size-full object-cover grayscale" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="hidden size-full flex-col items-center justify-center text-red-400">
                                                    <flux:icon.exclamation-circle class="size-8" />
                                                    <span class="mt-1 text-xs">Failed to load</span>
                                                </div>
                                            @else
                                                <div class="flex size-full flex-col items-center justify-center text-zinc-400">
                                                    <flux:icon.photo class="size-8" />
                                                    <span class="mt-1 text-xs">{{ $upload->getClientOriginalName() }}</span>
                                                </div>
                                            @endif
                                            <div class="absolute right-2 top-2">
                                                <span class="rounded-full bg-amber-500 px-2 py-0.5 text-xs font-medium text-white shadow">Duplicate</span>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Existing Images --}}
                    @if(count($existingImages) > 0)
                        <div>
                            <h4 class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Project Images</h4>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                @foreach($existingImages as $image)
                                    <div class="group relative aspect-square overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                        <img src="{{ $image['url'] }}" alt="{{ $image['alt_text'] }}" class="size-full object-cover">
                                        
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
                                                    class="rounded-full bg-white p-2 text-zinc-700 hover:bg-zinc-100"
                                                    title="Set as cover"
                                                >
                                                    <flux:icon.star class="size-4" />
                                                </button>
                                            @endif
                                            <button 
                                                type="button"
                                                wire:click="removeExistingImage({{ $image['id'] }})"
                                                wire:confirm="Delete this image?"
                                                class="rounded-full bg-red-500 p-2 text-white hover:bg-red-600"
                                                title="Delete"
                                            >
                                                <flux:icon.trash class="size-4" />
                                            </button>
                                        </div>

                                        {{-- Tag indicator --}}
                                        @if(count($image['tags']) > 0)
                                            <div class="absolute bottom-2 right-2">
                                                <flux:badge size="sm" class="!bg-black/60 !text-white">
                                                    <flux:icon.tag class="mr-1 size-3" />
                                                    {{ count($image['tags']) }}
                                                </flux:badge>
                                            </div>
                                        @endif
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
            <div class="space-y-6">
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

                {{-- Tags --}}
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Tags</flux:heading>
                    
                    <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
                        Tags can be added to individual images after saving the project.
                    </p>

                    {{-- Create new tag --}}
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <h4 class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Create New Tag</h4>
                        <div class="space-y-2">
                            <flux:input wire:model="newTagName" placeholder="Tag name" size="sm" />
                            <flux:select wire:model="newTagType" size="sm">
                                @foreach($tagTypes as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button wire:click="createTag" type="button" size="sm" variant="ghost" icon="plus" class="w-full">
                                Add Tag
                            </flux:button>
                        </div>
                    </div>

                    {{-- All tags list --}}
                    @if($allTags->isNotEmpty())
                        <div class="mt-4">
                            <h4 class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Available Tags</h4>
                            <div class="flex flex-wrap gap-1">
                                @foreach($allTags as $tag)
                                    <flux:badge size="sm" color="zinc">{{ $tag->name }}</flux:badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </flux:card>
            </div>
        </div>
    </form>
</div>
