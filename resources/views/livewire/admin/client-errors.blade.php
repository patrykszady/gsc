<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">JavaScript Errors</flux:heading>
            <flux:text class="mt-1 text-zinc-500">Client-side errors captured from real visitors, grouped by signature.</flux:text>
        </div>
        <flux:button href="{{ route('admin.dashboard') }}" variant="ghost" icon="arrow-left">
            Back to Dashboard
        </flux:button>
    </div>

    {{-- Stats Grid --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                    <flux:icon.bug-ant class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['open']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Open Errors</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                    <flux:icon.arrow-path-rounded-square class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['occurrences']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Total Occurrences</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400">
                    <flux:icon.clock class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['last_24h']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Active (24h)</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                    <flux:icon.check-circle class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['resolved']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Resolved</div>
                </div>
            </div>
        </flux:card>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <flux:select wire:model.live="statusFilter" class="w-full sm:w-44">
                    <flux:select.option value="open">Open</flux:select.option>
                    <flux:select.option value="resolved">Resolved</flux:select.option>
                    <flux:select.option value="all">All</flux:select.option>
                </flux:select>
                <flux:select wire:model.live="kindFilter" class="w-full sm:w-52">
                    <flux:select.option value="all">All Types</flux:select.option>
                    <flux:select.option value="error">Errors</flux:select.option>
                    <flux:select.option value="promise">Unhandled Promises</flux:select.option>
                </flux:select>
            </div>
            @if ($stats['open'] > 0)
                <flux:button wire:click="resolveAll" wire:confirm="Mark all open errors as resolved?" variant="ghost" icon="check" size="sm">
                    Resolve All
                </flux:button>
            @endif
        </div>
    </flux:card>

    {{-- Error list --}}
    <flux:card>
        @forelse ($errors as $error)
            <div @class([
                'border-b border-zinc-100 py-4 last:border-0 dark:border-zinc-700/60',
                'opacity-60' => $error->resolved_at,
            ])>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" :color="$error->kind === 'promise' ? 'amber' : 'red'">
                                {{ $error->kind === 'promise' ? 'Promise' : 'Error' }}
                            </flux:badge>
                            <flux:badge size="sm" color="zinc">{{ $error->occurrences }}&times;</flux:badge>
                            @if ($error->resolved_at)
                                <flux:badge size="sm" color="green">Resolved</flux:badge>
                            @endif
                        </div>

                        <button type="button" wire:click="toggleExpand({{ $error->id }})" class="mt-2 block text-left">
                            <span class="font-medium text-zinc-900 break-words dark:text-white">{{ $error->message }}</span>
                        </button>

                        <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($error->source)
                                <span class="break-all">{{ $error->source }}@if ($error->line):{{ $error->line }}@endif</span>
                                <span class="mx-1">&middot;</span>
                            @endif
                            @if ($error->page_path)
                                <span class="break-all">{{ $error->page_path }}</span>
                                <span class="mx-1">&middot;</span>
                            @endif
                            <span>last seen {{ $error->last_seen_at?->diffForHumans() }}</span>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        @if ($error->resolved_at)
                            <flux:button wire:click="markUnresolved({{ $error->id }})" variant="ghost" size="sm" icon="arrow-uturn-left" tooltip="Reopen" />
                        @else
                            <flux:button wire:click="markResolved({{ $error->id }})" variant="ghost" size="sm" icon="check" tooltip="Mark resolved" />
                        @endif
                        <flux:button wire:click="delete({{ $error->id }})" wire:confirm="Delete this error record?" variant="ghost" size="sm" icon="trash" tooltip="Delete" />
                    </div>
                </div>

                @if ($expandedId === $error->id)
                    <div class="mt-3 space-y-3 rounded-lg bg-zinc-50 p-4 text-sm dark:bg-zinc-800/50">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div><span class="text-zinc-500">First seen:</span> {{ $error->first_seen_at?->format('M j, Y g:i A') }}</div>
                            <div><span class="text-zinc-500">Last seen:</span> {{ $error->last_seen_at?->format('M j, Y g:i A') }}</div>
                            @if ($error->column)
                                <div><span class="text-zinc-500">Column:</span> {{ $error->column }}</div>
                            @endif
                            @if ($error->user_agent)
                                <div class="sm:col-span-2 break-words"><span class="text-zinc-500">User agent:</span> {{ $error->user_agent }}</div>
                            @endif
                        </div>
                        @if ($error->stack)
                            <div>
                                <div class="mb-1 text-zinc-500">Stack trace</div>
                                <pre class="overflow-x-auto rounded bg-zinc-900 p-3 text-xs text-zinc-100">{{ $error->stack }}</pre>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="py-12 text-center">
                <flux:icon.check-circle class="mx-auto size-10 text-green-500" />
                <flux:text class="mt-3 text-zinc-500">No JavaScript errors recorded for this filter. 🎉</flux:text>
            </div>
        @endforelse

        @if ($errors->hasPages())
            <div class="mt-4">
                {{ $errors->links() }}
            </div>
        @endif
    </flux:card>
</div>
