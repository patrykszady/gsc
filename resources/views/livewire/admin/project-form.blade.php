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

                    {{-- Upload Zone with batched uploads (2 at a time), always accepts drops --}}
                    <div
                        x-data="{
                            queue: [],
                            uploading: [],
                            batchSize: 2,
                            isUploading: false,
                            dragOver: false,

                            addFiles(fileList) {
                                const files = Array.from(fileList);
                                for (const file of files) {
                                    if (!file.type.startsWith('image/')) continue;
                                    const isDup = [...this.queue, ...this.uploading]
                                        .some(p => p.name === file.name && p.size === file.size);
                                    if (isDup) continue;
                                    this.queue.push({
                                        file,
                                        name: file.name,
                                        size: file.size,
                                        url: URL.createObjectURL(file),
                                        progress: 0,
                                        status: 'queued',
                                    });
                                }
                                this.processQueue();
                            },

                            processQueue() {
                                if (this.isUploading || this.queue.length === 0) return;

                                this.isUploading = true;
                                const batch = this.queue.splice(0, this.batchSize);
                                batch.forEach(item => { item.status = 'uploading'; });
                                this.uploading.push(...batch);

                                const files = batch.map(item => item.file);

                                $wire.$uploadMultiple('uploads', files,
                                    () => {
                                        batch.forEach(item => {
                                            item.status = 'done';
                                            item.progress = 100;
                                            if (item.url) URL.revokeObjectURL(item.url);
                                        });
                                        this.uploading = this.uploading.filter(i => i.status !== 'done');
                                        this.isUploading = false;
                                        queueMicrotask(() => this.processQueue());
                                    },
                                    () => {
                                        batch.forEach(item => { item.status = 'error'; });
                                        this.uploading = this.uploading.filter(i => i.status !== 'error');
                                        this.isUploading = false;
                                        queueMicrotask(() => this.processQueue());
                                    },
                                    (event) => {
                                        const pct = event.detail?.progress ?? 0;
                                        batch.forEach(item => { item.progress = pct; });
                                    }
                                );
                            },

                            get pendingPreviews() {
                                return [...this.uploading, ...this.queue];
                            },
                        }"
                    >
                        {{-- Dropzone area (always interactive) --}}
                        <div
                            x-on:click="$refs.fileInput.click()"
                            x-on:drop.prevent="dragOver = false; addFiles($event.dataTransfer.files)"
                            x-on:dragover.prevent="dragOver = true"
                            x-on:dragleave.prevent="dragOver = false"
                            class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 text-center transition-colors"
                            :class="dragOver ? 'border-blue-400 bg-blue-50 dark:bg-blue-950/20' : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-600 dark:hover:border-zinc-500'"
                        >
                            <input
                                type="file"
                                x-ref="fileInput"
                                multiple
                                accept="image/*"
                                class="hidden"
                                x-on:change="addFiles($event.target.files); $event.target.value = ''"
                            />
                            <flux:icon.cloud-arrow-up class="size-10 text-zinc-400" />
                            <p class="mt-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Drop files here or click to browse</p>
                            <p class="mt-1 text-xs text-zinc-500">PNG, JPG, WebP up to 50MB each</p>
                        </div>

                        {{-- Pending uploads preview (uploading + queued) --}}
                        <template x-if="pendingPreviews.length > 0">
                            <div class="mt-4">
                                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                    <template x-for="(preview, idx) in pendingPreviews" :key="preview.name + '-' + preview.size">
                                        <div class="group relative aspect-square overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                            <img :src="preview.url" :alt="preview.name" class="size-full object-cover" :class="preview.status === 'uploading' ? 'opacity-60' : (preview.status === 'queued' ? 'opacity-40' : '')">
                                            {{-- Per-image progress overlay (uploading) --}}
                                            <div x-show="preview.status === 'uploading'" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 via-black/40 to-transparent px-3 pb-3 pt-8">
                                                <div class="mb-1.5 flex items-center justify-between">
                                                    <p class="truncate text-xs font-medium text-white" x-text="preview.name"></p>
                                                    <span class="ml-2 shrink-0 text-xs tabular-nums text-white/80" x-text="Math.round(preview.progress) + '%'"></span>
                                                </div>
                                                <div class="h-1 overflow-hidden rounded-full bg-white/20">
                                                    <div class="h-full rounded-full bg-white transition-all duration-300 ease-out" :style="'width: ' + preview.progress + '%'"></div>
                                                </div>
                                            </div>
                                            {{-- Queued overlay --}}
                                            <div x-show="preview.status === 'queued'" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent p-2">
                                                <p class="truncate text-xs text-white/70" x-text="preview.name"></p>
                                            </div>
                                            {{-- Error overlay --}}
                                            <div x-show="preview.status === 'error'" class="absolute inset-0 flex items-center justify-center bg-red-900/50">
                                                <p class="text-xs font-medium text-white">Upload failed</p>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

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

                    {{-- Server-side uploads (accumulated across all drops) --}}
                    @if(count($allUploads) > 0)
                        <div class="mt-4">
                            <h4 class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">New Uploads</h4>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                @foreach($allUploads as $index => $upload)
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
                                            wire:click="removeQueuedUpload({{ $index }})"
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

                {{-- Timelapses --}}
                <flux:card>
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading size="lg">Timelapses</flux:heading>
                        <flux:button type="button" size="sm" variant="primary" icon="plus" wire:click="addTimelapse">
                            Add Timelapse
                        </flux:button>
                    </div>

                    @if(count($timelapses) === 0)
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">No timelapses yet. Add one to create a before/during/after slider on the project page.</p>
                    @endif

                    <div class="space-y-6">
                        @foreach($timelapses as $tlIndex => $tl)
                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4" wire:key="timelapse-{{ $tlIndex }}">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <flux:input
                                        wire:model="timelapses.{{ $tlIndex }}.title"
                                        placeholder="Timelapse title (optional)"
                                        size="sm"
                                        class="flex-1"
                                    />
                                    <flux:select wire:model="timelapses.{{ $tlIndex }}.display_mode" size="sm" class="w-40">
                                        <option value="slider">Slider</option>
                                        <option value="accordion">Accordion</option>
                                    </flux:select>
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="danger"
                                        icon="trash"
                                        wire:click="removeTimelapse({{ $tlIndex }})"
                                        wire:confirm="Delete this timelapse and all its frames?"
                                    />
                                </div>

                                {{-- Upload Zone --}}
                                <div
                                    x-data="{
                                        queue: [],
                                        uploading: [],
                                        batchSize: 2,
                                        isUploading: false,
                                        dragOver: false,
                                        tlIndex: {{ $tlIndex }},

                                        addFiles(fileList) {
                                            const files = Array.from(fileList).sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));
                                            for (const file of files) {
                                                if (!file.type.startsWith('image/')) continue;
                                                const isDup = [...this.queue, ...this.uploading]
                                                    .some(p => p.name === file.name && p.size === file.size);
                                                if (isDup) continue;
                                                this.queue.push({
                                                    file,
                                                    name: file.name,
                                                    size: file.size,
                                                    url: URL.createObjectURL(file),
                                                    progress: 0,
                                                    status: 'queued',
                                                });
                                            }
                                            this.processQueue();
                                        },

                                        processQueue() {
                                            if (this.isUploading || this.queue.length === 0) return;

                                            this.isUploading = true;
                                            const batch = this.queue.splice(0, this.batchSize);
                                            batch.forEach(item => { item.status = 'uploading'; });
                                            this.uploading.push(...batch);

                                            const files = batch.map(item => item.file);

                                            $wire.call('setActiveTimelapseIndex', this.tlIndex).then(() => {
                                                $wire.$uploadMultiple('timelapseUploads', files,
                                                    () => {
                                                        batch.forEach(item => {
                                                            item.status = 'done';
                                                            item.progress = 100;
                                                            if (item.url) URL.revokeObjectURL(item.url);
                                                        });
                                                        this.uploading = this.uploading.filter(i => i.status !== 'done');
                                                        this.isUploading = false;
                                                        queueMicrotask(() => this.processQueue());
                                                    },
                                                    () => {
                                                        batch.forEach(item => { item.status = 'error'; });
                                                        this.uploading = this.uploading.filter(i => i.status !== 'error');
                                                        this.isUploading = false;
                                                        queueMicrotask(() => this.processQueue());
                                                    },
                                                    (event) => {
                                                        const pct = event.detail?.progress ?? 0;
                                                        batch.forEach(item => { item.progress = pct; });
                                                    }
                                                );
                                            });
                                        },

                                        get pendingPreviews() {
                                            return [...this.uploading, ...this.queue];
                                        },
                                    }"
                                >
                                    <div
                                        x-on:click="$refs.timelapseInput{{ $tlIndex }}.click()"
                                        x-on:drop.prevent="dragOver = false; addFiles($event.dataTransfer.files)"
                                        x-on:dragover.prevent="dragOver = true"
                                        x-on:dragleave.prevent="dragOver = false"
                                        class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-4 text-center transition-colors"
                                        :class="dragOver ? 'border-blue-400 bg-blue-50 dark:bg-blue-950/20' : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-600 dark:hover:border-zinc-500'"
                                    >
                                        <input
                                            type="file"
                                            x-ref="timelapseInput{{ $tlIndex }}"
                                            multiple
                                            accept="image/*"
                                            class="hidden"
                                            x-on:change="addFiles($event.target.files); $event.target.value = ''"
                                        />
                                        <flux:icon.film class="size-6 text-zinc-400" />
                                        <p class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-300">Drop frames or click to browse</p>
                                        <p class="mt-0.5 text-xs text-zinc-500">Files sorted by name automatically</p>
                                    </div>

                                    {{-- Pending uploads --}}
                                    <template x-if="pendingPreviews.length > 0">
                                        <div class="mt-3">
                                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                                <template x-for="(preview, idx) in pendingPreviews" :key="preview.name + '-' + preview.size">
                                                    <div class="group relative aspect-square overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                                                        <img :src="preview.url" :alt="preview.name" class="size-full object-cover" :class="preview.status === 'uploading' ? 'opacity-60' : (preview.status === 'queued' ? 'opacity-40' : '')">
                                                        <div x-show="preview.status === 'uploading'" class="absolute inset-x-0 bottom-0 bg-black/60 p-0.5">
                                                            <div class="h-0.5 overflow-hidden rounded-full bg-white/20">
                                                                <div class="h-full rounded-full bg-white transition-all" :style="'width: ' + preview.progress + '%'"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                {{-- Pick from Gallery --}}
                                @if(count($existingImages) > 0)
                                    <div x-data="{ showGalleryPicker: false }" class="mt-3">
                                        <flux:button type="button" size="sm" variant="ghost" icon="photo" x-on:click="showGalleryPicker = !showGalleryPicker">
                                            Pick from Gallery
                                            @if(count($tl['selectedGalleryImageIds'] ?? []) > 0)
                                                <flux:badge size="sm" color="sky" class="ml-1">{{ count($tl['selectedGalleryImageIds']) }}</flux:badge>
                                            @endif
                                        </flux:button>
                                        <div x-show="showGalleryPicker" x-collapse x-cloak class="mt-2 grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                            @foreach($existingImages as $image)
                                                <div
                                                    class="group relative aspect-square cursor-pointer overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800 {{ in_array($image['id'], $tl['selectedGalleryImageIds'] ?? []) ? 'ring-2 ring-sky-500' : '' }}"
                                                    wire:click="toggleTimelapseGalleryImage({{ $tlIndex }}, {{ $image['id'] }})"
                                                >
                                                    <img src="{{ $image['url'] }}" alt="" class="size-full object-cover">
                                                    <div class="absolute left-2 top-2 z-10">
                                                        <span class="inline-flex items-center justify-center rounded-full bg-black/60 p-1">
                                                            <input type="checkbox" class="size-4 accent-sky-500 pointer-events-none" {{ in_array($image['id'], $tl['selectedGalleryImageIds'] ?? []) ? 'checked' : '' }}>
                                                        </span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- New uploads (server-side) --}}
                                @if(count($tl['allUploads'] ?? []) > 0)
                                    <div class="mt-3">
                                        <h4 class="mb-1 text-xs font-medium text-zinc-500">New Frames</h4>
                                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                            @foreach($tl['allUploads'] as $uIdx => $upload)
                                                <div class="group relative aspect-square overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800" title="{{ $upload->getClientOriginalName() }}">
                                                    <img src="{{ $upload->temporaryUrl() }}" alt="{{ $upload->getClientOriginalName() }}" class="size-full object-cover">
                                                    <button
                                                        type="button"
                                                        wire:click="removeQueuedTimelapseUpload({{ $tlIndex }}, {{ $uIdx }})"
                                                        class="absolute right-0.5 top-0.5 rounded-full bg-red-500 p-0.5 text-white opacity-0 transition-opacity group-hover:opacity-100"
                                                    >
                                                        <flux:icon.x-mark class="size-3" />
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Existing frames --}}
                                @if(count($tl['existingFrames'] ?? []) > 0)
                                    <div class="mt-3"
                                        x-data="{
                                            dragging: null,
                                            dragOverIdx: null,
                                            reorder(fromIdx, toIdx) {
                                                const frames = @js(array_column($tl['existingFrames'], 'id'));
                                                const [moved] = frames.splice(fromIdx, 1);
                                                frames.splice(toIdx, 0, moved);
                                                $wire.reorderTimelapseFrames({{ $tlIndex }}, frames);
                                            }
                                        }"
                                    >
                                        <h4 class="mb-1 text-xs font-medium text-zinc-500">
                                            Frames ({{ count($tl['existingFrames']) }})
                                        </h4>
                                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                            @foreach($tl['existingFrames'] as $fIdx => $frame)
                                                <div
                                                    class="group relative aspect-square overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800 cursor-grab"
                                                    draggable="true"
                                                    x-on:dragstart="dragging = {{ $fIdx }}"
                                                    x-on:dragend="dragging = null; dragOverIdx = null"
                                                    x-on:dragover.prevent="dragOverIdx = {{ $fIdx }}"
                                                    x-on:drop.prevent="if (dragging !== null && dragging !== {{ $fIdx }}) reorder(dragging, {{ $fIdx }}); dragging = null; dragOverIdx = null"
                                                    :class="dragOverIdx === {{ $fIdx }} ? 'ring-2 ring-sky-400' : ''"
                                                >
                                                    <button
                                                        type="button"
                                                        class="block size-full cursor-zoom-in"
                                                        x-on:click="$dispatch('open-frame-editor', { tlIndex: {{ $tlIndex }}, frameId: {{ $frame['id'] }}, url: @js($frame['url']) })"
                                                        title="Click to edit (blur personal info)"
                                                    >
                                                        <img src="{{ $frame['url'] }}" alt="Frame {{ $fIdx + 1 }}" class="size-full object-cover pointer-events-none">
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="removeTimelapseFrame({{ $tlIndex }}, {{ $frame['id'] }})"
                                                        wire:confirm="Delete this frame?"
                                                        class="absolute right-0.5 top-0.5 rounded-full bg-red-500 p-0.5 text-white opacity-0 transition-opacity group-hover:opacity-100"
                                                    >
                                                        <flux:icon.x-mark class="size-3" />
                                                    </button>
                                                    <div class="absolute left-0.5 top-0.5">
                                                        <span class="inline-flex size-4 items-center justify-center rounded-full bg-black/60 text-[9px] font-medium text-white">{{ $fIdx + 1 }}</span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @error('timelapseUploads.*')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </flux:card>

                {{-- Before / After Comparisons --}}
                <flux:card>
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading size="lg">Before &amp; After</flux:heading>
                        <flux:button type="button" size="sm" variant="primary" icon="plus" wire:click="addBeforeAfter">
                            Add Comparison
                        </flux:button>
                    </div>

                    @if(count($beforeAfters) === 0)
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">No before/after comparisons yet. Add one to show a draggable image comparison slider on the project page.</p>
                    @endif

                    <div class="space-y-6">
                        @foreach($beforeAfters as $baIndex => $ba)
                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4" wire:key="ba-{{ $baIndex }}">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <flux:input
                                        wire:model="beforeAfters.{{ $baIndex }}.title"
                                        placeholder="Comparison title (optional)"
                                        size="sm"
                                        class="flex-1"
                                    />
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="danger"
                                        icon="trash"
                                        wire:click="removeBeforeAfter({{ $baIndex }})"
                                        wire:confirm="Delete this before/after comparison?"
                                    />
                                </div>

                                @php
                                    $galleryImageMap = collect($existingImages)->keyBy('id');
                                @endphp

                                {{-- Before / After previews + uploads --}}
                                <div class="grid grid-cols-2 gap-4">
                                    {{-- Before Image --}}
                                    <div>
                                        <h4 class="mb-1 text-xs font-medium text-zinc-500">Before</h4>
                                        @if(isset($baBeforeUploads[$baIndex]) && $baBeforeUploads[$baIndex])
                                            <div class="group relative aspect-video overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                                <img src="{{ $baBeforeUploads[$baIndex]->temporaryUrl() }}" alt="Before (new)" class="size-full object-cover">
                                                <span class="absolute left-1 top-1 rounded bg-amber-500 px-1.5 py-0.5 text-[10px] font-medium text-white">New</span>
                                            </div>
                                        @elseif($ba['beforeGalleryImageId'] && isset($galleryImageMap[$ba['beforeGalleryImageId']]))
                                            <div class="group relative aspect-video overflow-hidden rounded-lg bg-zinc-100 ring-2 ring-sky-500 dark:bg-zinc-800">
                                                <img src="{{ $galleryImageMap[$ba['beforeGalleryImageId']]['url'] }}" alt="Before (from gallery)" class="size-full object-cover">
                                                <span class="absolute left-1 top-1 rounded bg-sky-500 px-1.5 py-0.5 text-[10px] font-medium text-white">Gallery</span>
                                                <button
                                                    type="button"
                                                    wire:click="setBeforeAfterGalleryImage({{ $baIndex }}, 'before', {{ $ba['beforeGalleryImageId'] }})"
                                                    class="absolute right-1 top-1 rounded-full bg-red-500 p-0.5 text-white opacity-0 transition-opacity group-hover:opacity-100"
                                                >
                                                    <flux:icon.x-mark class="size-3" />
                                                </button>
                                            </div>
                                        @elseif($ba['beforeUrl'])
                                            <div class="group relative aspect-video overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                                <img src="{{ $ba['beforeUrl'] }}" alt="Before" class="size-full object-cover">
                                            </div>
                                        @else
                                            <div class="flex aspect-video items-center justify-center rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-600">
                                                <span class="text-xs text-zinc-400">No image</span>
                                            </div>
                                        @endif
                                        <label class="mt-2 block">
                                            <flux:button type="button" size="sm" variant="ghost" icon="arrow-up-tray" class="w-full" x-on:click="$el.closest('label').querySelector('input').click()">
                                                Upload
                                            </flux:button>
                                            <input type="file" accept="image/*" class="hidden" wire:model="baBeforeUploads.{{ $baIndex }}" />
                                        </label>
                                    </div>

                                    {{-- After Image --}}
                                    <div>
                                        <h4 class="mb-1 text-xs font-medium text-zinc-500">After</h4>
                                        @if(isset($baAfterUploads[$baIndex]) && $baAfterUploads[$baIndex])
                                            <div class="group relative aspect-video overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                                <img src="{{ $baAfterUploads[$baIndex]->temporaryUrl() }}" alt="After (new)" class="size-full object-cover">
                                                <span class="absolute left-1 top-1 rounded bg-amber-500 px-1.5 py-0.5 text-[10px] font-medium text-white">New</span>
                                            </div>
                                        @elseif($ba['afterGalleryImageId'] && isset($galleryImageMap[$ba['afterGalleryImageId']]))
                                            <div class="group relative aspect-video overflow-hidden rounded-lg bg-zinc-100 ring-2 ring-sky-500 dark:bg-zinc-800">
                                                <img src="{{ $galleryImageMap[$ba['afterGalleryImageId']]['url'] }}" alt="After (from gallery)" class="size-full object-cover">
                                                <span class="absolute left-1 top-1 rounded bg-sky-500 px-1.5 py-0.5 text-[10px] font-medium text-white">Gallery</span>
                                                <button
                                                    type="button"
                                                    wire:click="setBeforeAfterGalleryImage({{ $baIndex }}, 'after', {{ $ba['afterGalleryImageId'] }})"
                                                    class="absolute right-1 top-1 rounded-full bg-red-500 p-0.5 text-white opacity-0 transition-opacity group-hover:opacity-100"
                                                >
                                                    <flux:icon.x-mark class="size-3" />
                                                </button>
                                            </div>
                                        @elseif($ba['afterUrl'])
                                            <div class="group relative aspect-video overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                                <img src="{{ $ba['afterUrl'] }}" alt="After" class="size-full object-cover">
                                            </div>
                                        @else
                                            <div class="flex aspect-video items-center justify-center rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-600">
                                                <span class="text-xs text-zinc-400">No image</span>
                                            </div>
                                        @endif
                                        <label class="mt-2 block">
                                            <flux:button type="button" size="sm" variant="ghost" icon="arrow-up-tray" class="w-full" x-on:click="$el.closest('label').querySelector('input').click()">
                                                Upload
                                            </flux:button>
                                            <input type="file" accept="image/*" class="hidden" wire:model="baAfterUploads.{{ $baIndex }}" />
                                        </label>
                                    </div>
                                </div>

                                {{-- Pick from Gallery (full-width) --}}
                                @if(count($existingImages) > 0)
                                    <div x-data="{ showGalleryPicker: false, slot: 'before' }" class="mt-3">
                                        <div class="flex items-center gap-2">
                                            <flux:button type="button" size="sm" variant="ghost" icon="photo" x-on:click="showGalleryPicker = !showGalleryPicker">
                                                Pick from Gallery
                                            </flux:button>
                                            <template x-if="showGalleryPicker">
                                                <div class="flex items-center gap-1 rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-700">
                                                    <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium transition" :class="slot === 'before' ? 'bg-zinc-800 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'" x-on:click="slot = 'before'">Before</button>
                                                    <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium transition" :class="slot === 'after' ? 'bg-zinc-800 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'" x-on:click="slot = 'after'">After</button>
                                                </div>
                                            </template>
                                        </div>
                                        <div x-show="showGalleryPicker" x-collapse x-cloak class="mt-2 grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                                            @foreach($existingImages as $image)
                                                @php
                                                    $isSelectedBefore = ($ba['beforeGalleryImageId'] ?? null) === $image['id'];
                                                    $isSelectedAfter = ($ba['afterGalleryImageId'] ?? null) === $image['id'];
                                                @endphp
                                                <div
                                                    class="group relative aspect-square cursor-pointer overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800 {{ $isSelectedBefore || $isSelectedAfter ? 'ring-2 ring-sky-500' : '' }}"
                                                    x-on:click="$wire.setBeforeAfterGalleryImage({{ $baIndex }}, slot, {{ $image['id'] }})"
                                                >
                                                    <img src="{{ $image['url'] }}" alt="" class="size-full object-cover">
                                                    <div class="absolute left-2 top-2 z-10">
                                                        <span class="inline-flex items-center justify-center rounded-full bg-black/60 p-1">
                                                            <input type="checkbox" class="size-4 accent-sky-500 pointer-events-none" {{ $isSelectedBefore || $isSelectedAfter ? 'checked' : '' }}>
                                                        </span>
                                                    </div>
                                                    @if($isSelectedBefore)
                                                        <div class="absolute bottom-1.5 right-1.5"><flux:badge size="sm" color="sky">Before</flux:badge></div>
                                                    @elseif($isSelectedAfter)
                                                        <div class="absolute bottom-1.5 right-1.5"><flux:badge size="sm" color="sky">After</flux:badge></div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
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
