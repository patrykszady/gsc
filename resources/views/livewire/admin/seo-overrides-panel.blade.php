<div>
    <flux:card>
        <flux:heading size="lg">SEO Overrides</flux:heading>
        <flux:subheading>
            Optional. Any field you fill below overrides the auto-generated SEO for this record.
            Leave blank to use the dynamic defaults.
        </flux:subheading>

        @if($savedFlash)
            <div class="mt-3 text-sm text-emerald-600 dark:text-emerald-400">{{ $savedFlash }}</div>
        @endif

        <div class="mt-4 space-y-4">
            <flux:input wire:model="title" label="Title" placeholder="Defaults to dynamic title"/>
            <flux:textarea wire:model="description" label="Meta description" rows="3"
                           placeholder="Defaults to dynamic description (~155 chars)"/>
            <flux:input wire:model="image" label="OG/Twitter image URL"
                        placeholder="https://… (defaults to cover image)"/>
            <flux:input wire:model="canonical_url" label="Canonical URL"
                        placeholder="Leave blank to use current URL"/>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input wire:model="author" label="Author" placeholder="e.g. Greg & Patryk"/>
                <flux:input wire:model="robots" label="Robots"
                            placeholder="e.g. noindex,follow"/>
            </div>
        </div>

        <div class="mt-5 flex items-center gap-2">
            <flux:button variant="primary" wire:click="save">Save overrides</flux:button>
            <flux:button variant="ghost" wire:click="clearAll"
                         wire:confirm="Clear all SEO overrides for this record?">
                Clear all
            </flux:button>
        </div>
    </flux:card>
</div>
