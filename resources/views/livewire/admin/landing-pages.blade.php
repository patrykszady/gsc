<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Landing Pages</flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                Demand-driven, proof-gated pages under /remodeling/. Generate one below, or review
                drafts the Autopilot created. Only published + proof-backed pages get indexed.
            </flux:text>
        </div>
        <flux:button href="{{ route('admin.autopilot.index') }}" variant="ghost" icon="sparkles">
            Autopilot
        </flux:button>
    </div>

    @if ($flash)
        <flux:callout variant="success" class="mb-4">{{ $flash }}</flux:callout>
    @endif
    @if ($error)
        <flux:callout variant="danger" class="mb-4">{{ $error }}</flux:callout>
    @endif

    {{-- Generate form --}}
    <flux:card class="mb-6">
        <flux:heading size="md" class="mb-3">Generate a landing page</flux:heading>
        <div class="grid gap-3 sm:grid-cols-4">
            <flux:select wire:model="genService" label="Service">
                @foreach ($services as $slug => $label)
                    <option value="{{ $slug }}">{{ $label }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="genCity" label="City" placeholder="e.g. Winnetka" />
            <flux:select wire:model="genModifier" label="Modifier (optional)">
                <option value="">— none —</option>
                <option value="luxury">Luxury</option>
                <option value="affordable">Affordable</option>
                <option value="small-space">Small-space</option>
                <option value="condo">Condo</option>
                <option value="modern">Modern</option>
            </flux:select>
            <div class="flex items-end">
                <flux:button wire:click="generate" variant="primary" icon="plus" class="w-full" wire:loading.attr="disabled">
                    Generate draft
                </flux:button>
            </div>
        </div>
        <flux:text class="mt-2 text-xs text-zinc-500">
            Content is built from real project proof + your city/pricing/FAQ data. If there's no matching project, generation is blocked (no thin pages).
        </flux:text>
    </flux:card>

    {{-- Pages table --}}
    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <th class="py-2 pr-3">Page</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2 pr-3">Indexed</th>
                        <th class="py-2 pr-3">Proof</th>
                        <th class="py-2 pr-3">Source</th>
                        <th class="py-2 pr-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pages as $page)
                        <tr class="border-b border-zinc-100 align-top dark:border-zinc-800">
                            <td class="py-3 pr-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $page->h1 }}</div>
                                <a href="/remodeling/{{ $page->slug }}{{ $page->status === 'draft' ? '?preview=1' : '' }}"
                                   target="_blank" class="text-xs text-sky-600 hover:underline">/remodeling/{{ $page->slug }}</a>
                            </td>
                            <td class="py-3 pr-3">
                                <flux:badge size="sm" :color="$page->status === 'published' ? 'emerald' : 'zinc'">{{ $page->status }}</flux:badge>
                            </td>
                            <td class="py-3 pr-3">
                                @if ($page->shouldIndex())
                                    <flux:badge size="sm" color="emerald">indexed</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">noindex</flux:badge>
                                @endif
                            </td>
                            <td class="py-3 pr-3 text-zinc-600 dark:text-zinc-400">{{ count($page->proof_project_ids ?? []) }}</td>
                            <td class="py-3 pr-3">
                                <flux:badge size="sm" :color="$page->source === 'autopilot' ? 'purple' : 'sky'">{{ $page->source }}</flux:badge>
                            </td>
                            <td class="py-3 pr-3">
                                <div class="flex justify-end gap-1">
                                    <flux:button size="xs" variant="ghost"
                                        href="/remodeling/{{ $page->slug }}{{ $page->status === 'draft' ? '?preview=1' : '' }}" target="_blank">
                                        Preview
                                    </flux:button>
                                    @if ($page->status === 'draft')
                                        <flux:button size="xs" variant="primary" wire:click="publish({{ $page->id }})">Publish</flux:button>
                                    @else
                                        <flux:button size="xs" variant="ghost" wire:click="unpublish({{ $page->id }})">Unpublish</flux:button>
                                    @endif
                                    <flux:button size="xs" variant="ghost" wire:click="delete({{ $page->id }})"
                                        wire:confirm="Delete this landing page permanently?">Delete</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500">
                                No landing pages yet. Generate one above, or let the Autopilot propose them from demand gaps.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $pages->links() }}</div>
    </flux:card>
</div>
