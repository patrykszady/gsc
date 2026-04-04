<div>
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <flux:button href="{{ route('admin.testimonials.index') }}" variant="ghost" icon="arrow-left" />
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
                            <flux:label>Full Name</flux:label>
                            <flux:input wire:model.live.debounce.500ms="reviewer_name" placeholder="e.g., Jane Doe" />
                            <flux:description>Public pages will show "{{ $this->getDisplayPreview() }}" only.</flux:description>
                            <flux:error name="reviewer_name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Linked Projects</flux:label>
                            <flux:select wire:model.live="project_ids" variant="listbox" size="lg" multiple placeholder="Select projects...">
                                @foreach($projects as $project)
                                    <flux:select.option value="{{ $project->id }}">
                                        <div class="flex items-center gap-3">
                                            @if($project->coverImage)
                                                <img src="{{ $project->coverImage->getAnyUrl('small') ?? $project->coverImage->getAnyUrl('medium') ?? $project->coverImage->url }}" alt="{{ $project->title }}" class="size-12 rounded object-cover">
                                            @else
                                                <div class="flex size-12 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-800">
                                                    <flux:icon.photo class="size-6 text-zinc-400" />
                                                </div>
                                            @endif
                                            <span>{{ $project->title }}</span>
                                        </div>
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>Link this review to one or more project galleries.</flux:description>
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
                    </div>

                    <div class="mt-4 space-y-3">
                        <flux:label>Review URLs</flux:label>
                        @foreach($review_urls as $i => $entry)
                            <div class="flex items-start gap-2">
                                <div class="w-48">
                                    <flux:select wire:model="review_urls.{{ $i }}.platform" variant="listbox">
                                        <flux:select.option value="">Platform</flux:select.option>
                                        <flux:select.option value="google">
                                            <div class="flex items-center gap-2">
                                                <img src="{{ asset('images/socials/google.svg') }}" alt="" class="size-4"> Google
                                            </div>
                                        </flux:select.option>
                                        <flux:select.option value="angi">
                                            <div class="flex items-center gap-2">
                                                <img src="{{ asset('images/socials/angi.svg') }}" alt="" class="size-4"> Angi
                                            </div>
                                        </flux:select.option>
                                        <flux:select.option value="yelp">
                                            <div class="flex items-center gap-2">
                                                <img src="{{ asset('images/socials/yelp.svg') }}" alt="" class="size-4"> Yelp
                                            </div>
                                        </flux:select.option>
                                        <flux:select.option value="houzz">
                                            <div class="flex items-center gap-2">
                                                <img src="{{ asset('images/socials/houzz.svg') }}" alt="" class="size-4"> Houzz
                                            </div>
                                        </flux:select.option>
                                        <flux:select.option value="facebook">
                                            <div class="flex items-center gap-2">
                                                <img src="{{ asset('images/socials/facebook.svg') }}" alt="" class="size-4"> Facebook
                                            </div>
                                        </flux:select.option>
                                        <flux:select.option value="other">
                                            <div class="flex items-center gap-2">
                                                <flux:icon.link class="size-4" /> Other
                                            </div>
                                        </flux:select.option>
                                    </flux:select>
                                </div>
                                <div class="flex-1">
                                    <flux:input wire:model.blur="review_urls.{{ $i }}.url" placeholder="https://..." />
                                </div>
                                @if(count($review_urls) > 1)
                                    <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeUrl({{ $i }})" />
                                @endif
                            </div>
                        @endforeach
                        <flux:button variant="ghost" size="sm" icon="plus" wire:click="addUrl">Add URL</flux:button>
                    </div>


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
