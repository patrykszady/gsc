<div>
    <flux:heading size="xl" class="mb-6">Tags</flux:heading>

    {{-- Success Message --}}
    @if(session('success'))
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            {{ session('success') }}
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Tag Form --}}
        <div>
            <flux:card>
                <flux:heading size="lg" class="mb-4">
                    {{ $editingId ? 'Edit Tag' : 'Create Tag' }}
                </flux:heading>
                
                <form wire:submit="{{ $editingId ? 'update' : 'create' }}">
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Name</flux:label>
                            <flux:input wire:model="name" placeholder="e.g., Modern, Marble, Island" />
                            <flux:error name="name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Type</flux:label>
                            <flux:select wire:model="type">
                                @foreach($tagTypes as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="type" />
                        </flux:field>

                        <div class="flex gap-2">
                            <flux:button type="submit" variant="primary" class="flex-1">
                                {{ $editingId ? 'Update' : 'Create' }}
                            </flux:button>
                            @if($editingId)
                                <flux:button type="button" wire:click="cancelEdit" variant="ghost">
                                    Cancel
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </form>
            </flux:card>
        </div>

        {{-- Tags List --}}
        <div class="lg:col-span-2">
            <flux:card class="overflow-hidden !p-0">
                @if($tags->isEmpty())
                    <div class="py-12 text-center">
                        <flux:icon.tag class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                        <h3 class="mt-4 text-lg font-medium text-zinc-900 dark:text-white">No tags yet</h3>
                        <p class="mt-1 text-zinc-500 dark:text-zinc-400">Create your first tag to get started</p>
                    </div>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Name</flux:table.column>
                            <flux:table.column>Type</flux:table.column>
                            <flux:table.column>Images</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($tags as $tag)
                                <flux:table.row>
                                    <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                                        {{ $tag->name }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm">{{ $tagTypes[$tag->type] ?? $tag->type }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-zinc-500 dark:text-zinc-400">
                                        {{ $tag->images_count }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex justify-end gap-1">
                                            <flux:button 
                                                wire:click="edit({{ $tag->id }})" 
                                                size="sm" 
                                                variant="ghost" 
                                                icon="pencil"
                                            />
                                            <flux:button 
                                                wire:click="delete({{ $tag->id }})" 
                                                wire:confirm="Delete this tag?"
                                                size="sm" 
                                                variant="ghost" 
                                                icon="trash"
                                            />
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
        </div>
    </div>
</div>
