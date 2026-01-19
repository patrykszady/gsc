<div>
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <flux:button href="{{ route('admin.dashboard') }}" variant="ghost" icon="arrow-left" />
            <flux:heading size="xl">{{ $testimonial?->exists ? 'Edit Review' : 'New Review' }}</flux:heading>
        </div>
    </div>

    @if(session('success'))
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            {{ session('success') }}
        </flux:callout>
    @endif

    <form wire:submit="save">
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Review Details</flux:heading>

                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Reviewer Name</flux:label>
                            <flux:input wire:model="reviewer_name" placeholder="e.g., Jane D." />
                            <flux:error name="reviewer_name" />
                        </flux:field>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Project Location</flux:label>
                                <flux:input wire:model="project_location" placeholder="e.g., Arlington Heights, IL" />
                                <flux:error name="project_location" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Project Type</flux:label>
                                <flux:input wire:model="project_type" placeholder="e.g., kitchen, bathroom" />
                                <flux:error name="project_type" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Review</flux:label>
                            <flux:textarea wire:model="review_description" rows="6" placeholder="Paste the review text..." />
                            <flux:error name="review_description" />
                        </flux:field>
                    </div>
                </flux:card>

                <flux:card>
                    <flux:heading size="lg" class="mb-4">Review Source</flux:heading>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Review Date</flux:label>
                            <flux:input wire:model="review_date" type="date" />
                            <flux:error name="review_date" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Review URL</flux:label>
                            <flux:input wire:model="review_url" placeholder="https://..." />
                            <flux:error name="review_url" />
                        </flux:field>
                    </div>

                    <flux:field class="mt-4">
                        <flux:label>Reviewer Image URL</flux:label>
                        <flux:input wire:model="review_image" placeholder="https://..." />
                        <flux:error name="review_image" />
                    </flux:field>

                    @if($review_image)
                        <div class="mt-4 flex items-center gap-4">
                            <img src="{{ $review_image }}" alt="Preview" class="size-16 rounded-full object-cover" />
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">Preview</span>
                        </div>
                    @endif
                </flux:card>
            </div>

            <div class="space-y-6">
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Publish</flux:heading>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Reviews are immediately visible on the testimonials page.
                    </p>

                    <div class="mt-6 flex gap-2">
                        <flux:button type="submit" variant="primary" class="flex-1">
                            {{ $testimonial?->exists ? 'Update' : 'Create' }} Review
                        </flux:button>
                    </div>
                </flux:card>
            </div>
        </div>
    </form>
</div>
