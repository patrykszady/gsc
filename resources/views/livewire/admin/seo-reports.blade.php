<div>
    <flux:heading size="xl" class="mb-2">SEO Reports</flux:heading>
    <flux:text class="mb-6 text-zinc-500">Weekly markdown reports produced by the scheduled <code>seo:*</code> commands. Click a row to view; use <strong>Run now</strong> to regenerate on demand.</flux:text>

    @if ($flash)
        <flux:callout variant="success" class="mb-4">{{ $flash }}</flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,360px)_minmax(0,1fr)]">
        {{-- Report list --}}
        <flux:card class="p-0">
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->files as $f)
                    <li
                        class="cursor-pointer px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-800
                            {{ $active === $f['key'] ? 'bg-sky-50 dark:bg-sky-900/20' : '' }}"
                        wire:click="open('{{ $f['key'] }}')"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $f['label'] }}</div>
                                <div class="mt-0.5 truncate text-xs text-zinc-500">{{ $f['description'] }}</div>
                                <div class="mt-1 text-xs text-zinc-400">
                                    @if ($f['exists'])
                                        Updated {{ $f['age'] }} &middot; {{ number_format($f['size'] / 1024, 1) }} KB
                                    @else
                                        <span class="text-amber-600">Never generated</span>
                                    @endif
                                </div>
                            </div>
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click.stop="regenerate('{{ $f['key'] }}')"
                                icon="arrow-path"
                                title="Run {{ $f['command'] }}"
                            >
                                Run
                            </flux:button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </flux:card>

        {{-- Report viewer --}}
        <flux:card>
            @php
                $activeFile = collect($this->files)->firstWhere('key', $active);
            @endphp
            @if ($active && $activeFile)
                <div class="mb-4 flex items-center justify-between">
                    <flux:heading size="lg">{{ $activeFile['label'] }}</flux:heading>
                    <div class="flex items-center gap-2">
                        <code class="text-xs text-zinc-500">php artisan {{ $activeFile['command'] }}</code>
                        <flux:button size="xs" variant="ghost" wire:click="regenerate('{{ $active }}')" icon="arrow-path">
                            Run now
                        </flux:button>
                    </div>
                </div>

                <div class="prose prose-zinc dark:prose-invert max-w-none text-sm
                            prose-table:my-3 prose-th:px-2 prose-td:px-2
                            prose-table:border prose-th:border prose-td:border
                            prose-table:border-zinc-200 dark:prose-table:border-zinc-700">
                    {!! $this->activeHtml !!}
                </div>
            @else
                <flux:text class="text-zinc-500">Pick a report from the list to view its latest contents.</flux:text>
            @endif
        </flux:card>
    </div>
</div>
