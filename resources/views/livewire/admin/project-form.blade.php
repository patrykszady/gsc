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

                    {{-- New Uploads Preview --}}
                    @if(count($uploads) > 0)
                        <div class="mt-4">
                            <h4 class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">New Uploads</h4>
                            <div class="flex flex-col gap-2">
                                {{-- Non-duplicates first --}}
                                @foreach($uploads as $index => $upload)
                                    @if(!in_array($index, $duplicateIndices))
                                        <flux:file-item 
                                            :heading="$upload->getClientOriginalName()"
                                            :size="$upload->getSize()"
                                        >
                                            <x-slot name="actions">
                                                <flux:file-item.remove 
                                                    wire:click="removeUpload({{ $index }})" 
                                                    aria-label="Remove file: {{ $upload->getClientOriginalName() }}" 
                                                />
                                            </x-slot>
                                        </flux:file-item>
                                    @endif
                                @endforeach
                                
                                {{-- Duplicates after --}}
                                @foreach($uploads as $index => $upload)
                                    @if(in_array($index, $duplicateIndices))
                                        <flux:file-item 
                                            :heading="$upload->getClientOriginalName()"
                                            :size="$upload->getSize()"
                                            invalid
                                        >
                                            <x-slot name="actions">
                                                <flux:badge color="amber" size="sm">Duplicate</flux:badge>
                                            </x-slot>
                                        </flux:file-item>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Existing Images --}}
                    @if(count($existingImages) > 0)
                        <div class="mt-4">
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
