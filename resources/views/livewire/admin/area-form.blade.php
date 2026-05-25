<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ $area?->exists ? 'Edit Area' : 'New Area' }}</flux:heading>
            <flux:subheading>
                {{ $area?->exists ? $area->city : 'Add a city or neighborhood we serve.' }}
            </flux:subheading>
        </div>
        <flux:button :href="route('admin.areas.index')" variant="ghost" icon="arrow-left">
            Back to Areas
        </flux:button>
    </div>

    @if($savedFlash)
        <div class="mb-4 text-sm text-emerald-600 dark:text-emerald-400">{{ $savedFlash }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <flux:card>
            <flux:heading size="lg">Basics</flux:heading>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input wire:model.live="city" label="City" required/>
                <flux:input wire:model="slug" label="Slug" required
                            description="Used in /areas-served/{slug}"/>
                <flux:input wire:model="latitude" label="Latitude" type="number" step="0.0000001"/>
                <flux:input wire:model="longitude" label="Longitude" type="number" step="0.0000001"/>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Content</flux:heading>
            <div class="mt-4 space-y-4">
                <flux:textarea wire:model="intro" label="Intro"
                               description="Short paragraph at the top of the area page." rows="4"/>
                <flux:textarea wire:model="local_intro" label="Local intro"
                               description="More detailed local context." rows="5"/>
                <flux:textarea wire:model="landmarks" label="Landmarks" rows="3"/>
                <flux:textarea wire:model="permit_notes" label="Permit notes" rows="3"/>
            </div>
        </flux:card>

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">
                {{ $area?->exists ? 'Update Area' : 'Create Area' }}
            </flux:button>
            <flux:button :href="route('admin.areas.index')" variant="ghost">Cancel</flux:button>
        </div>
    </form>

    @if($area?->exists)
        <div class="mt-6">
            <livewire:admin.seo-overrides-panel :model="$area" :key="'seo-area-'.$area->id"/>
        </div>
    @endif
</div>
