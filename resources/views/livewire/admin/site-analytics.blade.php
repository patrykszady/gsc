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
        <flux:card class="lg:col-span-2">
            <flux:heading size="sm" class="mb-4">Events — Last 14 Days</flux:heading>
            <div class="flex h-40 items-end gap-1.5">
                @foreach($days as $day)
                    <div class="flex flex-1 flex-col items-center gap-1">
                        <div class="flex w-full flex-1 items-end">
                            <div
                                class="w-full rounded-t bg-sky-500/80 transition-all hover:bg-sky-500 dark:bg-sky-400/70"
                                style="height: {{ $day['count'] > 0 ? max(4, round($day['count'] / $trendMax * 100)) : 0 }}%"
                                title="{{ $day['label'] }}: {{ $day['count'] }} events"
                            ></div>
                        </div>
                        <span class="text-[10px] text-zinc-400">{{ $day['count'] }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex justify-between text-[10px] text-zinc-400">
                <span>{{ $days->first()['label'] }}</span>
                <span>{{ $days->last()['label'] }}</span>
            </div>
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
                                        {{ $event->created_at->format('M j, Y') }}
                                        <div class="text-xs">{{ $event->created_at->format('g:i A') }}</div>
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
