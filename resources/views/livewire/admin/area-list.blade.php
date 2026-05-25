<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">Service Areas</flux:heading>
            <flux:subheading>Cities/neighborhoods we serve. Each area can have custom intro content and SEO overrides.</flux:subheading>
        </div>
        <flux:button :href="route('admin.areas.create')" icon="plus" variant="primary">
            New Area
        </flux:button>
    </div>

    <flux:card>
        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        placeholder="Search by city or slug…"/>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>City</flux:table.column>
                <flux:table.column>Slug</flux:table.column>
                <flux:table.column>Coordinates</flux:table.column>
                <flux:table.column>Has intro?</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($areas as $area)
                    <flux:table.row :key="'area-'.$area->id">
                        <flux:table.cell class="font-medium">{{ $area->city }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $area->slug }}</flux:table.cell>
                        <flux:table.cell class="text-xs text-zinc-500">
                            @if($area->latitude && $area->longitude)
                                {{ number_format($area->latitude, 4) }}, {{ number_format($area->longitude, 4) }}
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($area->hasUniqueContent())
                                <flux:badge color="emerald" size="sm">Yes</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">No</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-right">
                            <flux:button size="xs" variant="ghost"
                                         :href="route('admin.areas.edit', $area)">Edit</flux:button>
                            <flux:button size="xs" variant="danger"
                                         wire:click="delete({{ $area->id }})"
                                         wire:confirm="Delete {{ $area->city }}? This cannot be undone.">
                                Delete
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                            No areas yet.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $areas->links() }}</div>
    </flux:card>
</div>
