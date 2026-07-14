<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Analytics</flux:heading>
            <flux:text class="mt-1 text-zinc-500">First-party tracking for phone, email & form conversions.</flux:text>
        </div>
        <flux:button href="{{ route('admin.dashboard') }}" variant="ghost" icon="arrow-left">
            Back to Dashboard
        </flux:button>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <flux:select wire:model.live="dateFilter" class="w-full sm:w-44">
                <flux:select.option value="today">Today</flux:select.option>
                <flux:select.option value="week">Last 7 Days</flux:select.option>
                <flux:select.option value="month">Last 30 Days</flux:select.option>
                <flux:select.option value="all">All Time</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="typeFilter" class="w-full sm:w-52">
                <flux:select.option value="all">All Events</flux:select.option>
                <flux:select.option value="phone_click">Phone Clicks</flux:select.option>
                <flux:select.option value="email_click">Email Clicks</flux:select.option>
                <flux:select.option value="form_submit">Form Submissions</flux:select.option>
                <flux:select.option value="cta_click">CTA Clicks</flux:select.option>
            </flux:select>
        </div>
    </flux:card>

    {{-- Stats Grid --}}
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                    <flux:icon.phone class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['phone']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Phone Clicks</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400">
                    <flux:icon.envelope class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['email']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Email Clicks</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                    <flux:icon.document-text class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['form']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Form Submissions</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                    <flux:icon.cursor-arrow-rays class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['cta']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">CTA Clicks</div>
                </div>
            </div>
        </flux:card>
    </div>

    {{-- Trend + Top Pages --}}
    <div class="mb-8 grid gap-4 lg:grid-cols-3">
        <flux:card class="min-w-0 overflow-hidden lg:col-span-2">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="sm">Events — Last {{ $trendDays }} Days</flux:heading>
                <div class="flex items-center gap-1">
                    @foreach (\App\Livewire\Admin\SiteAnalytics::TREND_SPANS as $span)
                        <flux:button
                            size="xs"
                            variant="ghost"
                            wire:click="setTrendDays({{ $span }})"
                            class="{{ $trendDays === $span ? 'bg-sky-100! text-sky-800! dark:bg-sky-900/30! dark:text-sky-200!' : '' }}"
                        >
                            {{ $span }}d
                        </flux:button>
                    @endforeach
                </div>
            </div>
            <flux:chart :value="$trendChartData" wire:key="events-trend-{{ $trendDays }}" class="min-w-0">
                <flux:chart.viewport class="h-56">
                    <flux:chart.svg gutter="18 8 26 8">
                        <flux:chart.axis axis="x" field="date">
                            <flux:chart.axis.tick />
                            <flux:chart.axis.line />
                        </flux:chart.axis>

                        <flux:chart.axis axis="y">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>

                        <flux:chart.cursor />

                        <flux:chart.line field="phone" class="text-green-500" />
                        <flux:chart.line field="email" class="text-sky-500" />
                        <flux:chart.line field="form" class="text-purple-500" />
                        <flux:chart.line field="cta" class="text-amber-500" />
                        <flux:chart.line field="total" class="text-zinc-400" />
                    </flux:chart.svg>

                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="date" />
                        <flux:chart.tooltip.value field="phone" label="Phone Clicks">
                            <span class="size-2.5 rounded-full bg-green-500"></span>
                        </flux:chart.tooltip.value>
                        <flux:chart.tooltip.value field="email" label="Email Clicks">
                            <span class="size-2.5 rounded-full bg-sky-500"></span>
                        </flux:chart.tooltip.value>
                        <flux:chart.tooltip.value field="form" label="Form Submissions">
                            <span class="size-2.5 rounded-full bg-purple-500"></span>
                        </flux:chart.tooltip.value>
                        <flux:chart.tooltip.value field="cta" label="CTA Clicks">
                            <span class="size-2.5 rounded-full bg-amber-500"></span>
                        </flux:chart.tooltip.value>
                        <flux:chart.tooltip.value field="total" label="Total">
                            <span class="size-2.5 rounded-full bg-zinc-400"></span>
                        </flux:chart.tooltip.value>
                    </flux:chart.tooltip>
                </flux:chart.viewport>

                <div class="mt-2 flex flex-wrap items-center gap-2 px-1">
                    <flux:chart.legend label="Phone" class="p-1 text-xs text-zinc-500">
                        <flux:chart.legend.indicator class="bg-green-500" />
                    </flux:chart.legend>
                    <flux:chart.legend label="Email" class="p-1 text-xs text-zinc-500">
                        <flux:chart.legend.indicator class="bg-sky-500" />
                    </flux:chart.legend>
                    <flux:chart.legend label="Form" class="p-1 text-xs text-zinc-500">
                        <flux:chart.legend.indicator class="bg-purple-500" />
                    </flux:chart.legend>
                    <flux:chart.legend label="CTA" class="p-1 text-xs text-zinc-500">
                        <flux:chart.legend.indicator class="bg-amber-500" />
                    </flux:chart.legend>
                    <flux:chart.legend label="Total" class="p-1 text-xs text-zinc-500">
                        <flux:chart.legend.indicator class="bg-zinc-400" />
                    </flux:chart.legend>
                </div>
            </flux:chart>
        </flux:card>

        <flux:card>
            <flux:heading size="sm" class="mb-4">Top Pages</flux:heading>
            @if($topPages->isEmpty())
                <flux:text class="text-zinc-400">No data for this period.</flux:text>
            @else
                <div class="space-y-2">
                    @foreach($topPages as $page => $count)
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm text-zinc-700 dark:text-zinc-300" title="{{ $page }}">{{ $page }}</span>
                            <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>
    </div>

    {{-- Recent Events Table --}}
    @if($events->isEmpty())
        <flux:card>
            <div class="py-12 text-center text-zinc-500 dark:text-zinc-400">
                <flux:icon.chart-bar class="mx-auto mb-4 size-12 opacity-50" />
                <p>No tracked events for this period yet.</p>
            </div>
        </flux:card>
    @else
        <flux:card class="overflow-hidden !p-0">
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Event</flux:table.column>
                        <flux:table.column>Value</flux:table.column>
                        <flux:table.column>Page</flux:table.column>
                        <flux:table.column>Source</flux:table.column>
                        <flux:table.column>When</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($events as $event)
                            <flux:table.row>
                                <flux:table.cell>
                                    @php
                                        $badge = match($event->type) {
                                            'phone_click' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                            'email_click' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400',
                                            'form_submit' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
                                            default => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $badge }}">
                                        {{ \App\Models\TrackedEvent::typeLabel($event->type) }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $event->label ?: '—' }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $event->page_path ?: '—' }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="text-xs">
                                        @if($event->utm_source)
                                            <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400">{{ $event->utm_source }}</span>
                                        @elseif($event->referrer)
                                            <span class="max-w-[150px] truncate text-zinc-400" title="{{ $event->referrer }}">{{ parse_url($event->referrer, PHP_URL_HOST) ?: $event->referrer }}</span>
                                        @else
                                            <span class="text-zinc-400">direct</span>
                                        @endif
                                        @if($event->country)
                                            <div class="mt-1 text-zinc-400">{{ $event->country }}</div>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $event->created_at->timezone('America/Chicago')->format('M j, Y') }}
                                        <div class="text-xs">{{ $event->created_at->timezone('America/Chicago')->format('g:i A') }} CT</div>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </flux:card>

        <div class="mt-4">
            {{ $events->links() }}
        </div>
    @endif
</div>
