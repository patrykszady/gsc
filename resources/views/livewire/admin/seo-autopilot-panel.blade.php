<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">SEO Autopilot</flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                Self-improving loop: synthesizes fixes from Search Console signals, auto-applies the safe/reversible
                ones, measures whether they worked, and learns which action types move the needle on this site.
            </flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('admin.seo-reports.index') }}" variant="ghost" icon="arrow-left">
                SEO Reports
            </flux:button>
            <flux:button wire:click="synthesize" variant="ghost" icon="arrow-path" wire:loading.attr="disabled">
                Refresh ledger
            </flux:button>
            <flux:button
                wire:click="runAutopilot"
                wire:confirm="Run the autopilot now? It will auto-apply the top safe actions (title/meta, reindex, llms) — all reversible."
                variant="primary"
                icon="sparkles"
                wire:loading.attr="disabled"
            >
                Run autopilot
            </flux:button>
        </div>
    </div>

    <div wire:loading class="mb-4">
        <flux:callout variant="secondary" icon="arrow-path">Working…</flux:callout>
    </div>

    @if ($flash)
        <flux:callout variant="success" class="mb-4">{{ $flash }}</flux:callout>
    @endif

    {{-- KPI cards --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
        <flux:card>
            <div class="text-sm text-zinc-500">Open actions</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($this->stats['open']) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Est. clicks/28d on table</div>
            <div class="mt-1 text-2xl font-bold text-indigo-600">{{ number_format($this->stats['est_uplift']) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Applied (live)</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($this->stats['applied']) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Worked</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600">{{ number_format($this->stats['worked']) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Regressed (auto-reverted)</div>
            <div class="mt-1 text-2xl font-bold text-rose-600">{{ number_format($this->stats['regressed']) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">No effect</div>
            <div class="mt-1 text-2xl font-bold text-zinc-500">{{ number_format($this->stats['no_effect']) }}</div>
        </flux:card>
    </div>

    {{-- Learned weights --}}
    <flux:card class="mb-6">
        <div class="mb-3 flex items-center justify-between">
            <flux:heading size="md">Learned weights</flux:heading>
            <flux:text class="text-xs text-zinc-500">Categories that historically worked here score higher; regressions score lower (neutral until ≥3 measured).</flux:text>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($this->weights as $w)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ $w['category'] }}</span>
                        <span @class([
                            'text-lg font-bold',
                            'text-emerald-600' => $w['weight'] > 1.05,
                            'text-rose-600' => $w['weight'] < 0.95,
                            'text-zinc-500' => $w['weight'] >= 0.95 && $w['weight'] <= 1.05,
                        ])>×{{ number_format($w['weight'], 2) }}</span>
                    </div>
                    <div class="mt-1 text-xs text-zinc-500">
                        {{ $w['worked'] }} worked · {{ $w['regressed'] }} regressed · {{ $w['no_effect'] }} no-effect
                    </div>
                </div>
            @endforeach
        </div>
    </flux:card>

    {{-- Tabs --}}
    <div class="mb-4 flex gap-2">
        @foreach (['open' => 'Open ledger', 'applied' => 'Applied / measured', 'all' => 'All'] as $key => $label)
            <flux:button wire:click="$set('tab', '{{ $key }}')" size="sm"
                :variant="$tab === $key ? 'primary' : 'ghost'">
                {{ $label }}
            </flux:button>
        @endforeach
    </div>

    {{-- Ledger table --}}
    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <th class="py-2 pr-3">Priority</th>
                        <th class="py-2 pr-3">Category</th>
                        <th class="py-2 pr-3">Action &amp; hypothesis</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2 pr-3">Outcome</th>
                        <th class="py-2 pr-3 text-right">Do</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($actions as $a)
                        <tr class="border-b border-zinc-100 align-top dark:border-zinc-800">
                            <td class="py-3 pr-3 font-mono font-semibold text-indigo-600">{{ number_format($a->priority, 1) }}</td>
                            <td class="py-3 pr-3">
                                <flux:badge size="sm" :color="match($a->category) { 'title_meta' => 'indigo', 'reindex' => 'amber', 'llms_regen' => 'purple', default => 'zinc' }">
                                    {{ $a->category }}
                                </flux:badge>
                                @if ($a->risk === 'safe')
                                    <div class="mt-1 text-[10px] uppercase text-emerald-600">auto-safe</div>
                                @endif
                            </td>
                            <td class="py-3 pr-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $a->title }}</div>
                                <div class="mt-0.5 text-xs text-zinc-500">{{ $a->hypothesis }}</div>
                                @if (($a->payload['new_title'] ?? null))
                                    <div class="mt-1 text-xs">
                                        <span class="text-zinc-400">→ title:</span>
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $a->payload['new_title'] }}</span>
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 pr-3">
                                <flux:badge size="sm" :color="match($a->status) { 'applied' => 'emerald', 'proposed' => 'sky', 'reverted' => 'zinc', 'failed' => 'rose', 'skipped' => 'zinc', default => 'zinc' }">
                                    {{ $a->status }}
                                </flux:badge>
                                @if ($a->auto_applied)
                                    <div class="mt-1 text-[10px] text-zinc-400">auto</div>
                                @endif
                            </td>
                            <td class="py-3 pr-3">
                                @if ($a->outcome && $a->outcome !== 'pending')
                                    <flux:badge size="sm" :color="match($a->outcome) { 'worked' => 'emerald', 'regressed' => 'rose', 'no_effect' => 'zinc', default => 'amber' }">
                                        {{ $a->outcome }}
                                    </flux:badge>
                                    @if ($a->delta_pct !== null)
                                        <div class="mt-1 text-xs {{ $a->delta_pct >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                            {{ $a->delta_pct >= 0 ? '+' : '' }}{{ number_format($a->delta_pct, 0) }}%
                                        </div>
                                    @endif
                                @elseif ($a->status === 'applied')
                                    <span class="text-xs text-zinc-400">measuring… {{ $a->measure_after?->diffForHumans() }}</span>
                                @else
                                    <span class="text-zinc-300">—</span>
                                @endif
                            </td>
                            <td class="py-3 pr-3">
                                <div class="flex justify-end gap-1">
                                    @if ($a->status === 'proposed')
                                        <flux:button size="xs" variant="primary" wire:click="applyOne({{ $a->id }})">Apply</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="skipOne({{ $a->id }})">Skip</flux:button>
                                    @elseif ($a->status === 'applied')
                                        <flux:button size="xs" variant="ghost" wire:click="revertOne({{ $a->id }})"
                                            wire:confirm="Revert this change and restore the original?">Revert</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500">
                                No actions here yet. Click <strong>Refresh ledger</strong> to synthesize from current signals.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $actions->links() }}
        </div>
    </flux:card>
</div>
