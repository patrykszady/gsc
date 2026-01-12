<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="xl">Contact Submissions</flux:heading>
        <flux:button href="{{ route('admin.dashboard') }}" variant="ghost" icon="arrow-left">
            Back to Dashboard
        </flux:button>
    </div>

    {{-- Stats Grid --}}
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400">
                    <flux:icon.envelope class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $stats['total'] }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Total Leads</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                    <flux:icon.calendar class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $stats['today'] }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Today</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                    <flux:icon.chart-bar class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $stats['week'] }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">This Week</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                    <flux:icon.calendar-days class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $stats['month'] }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">This Month</div>
                </div>
            </div>
        </flux:card>
    </div>

    {{-- Analytics Cards --}}
    @if($topCities->isNotEmpty() || $utmSources->isNotEmpty())
    <div class="mb-8 grid gap-4 sm:grid-cols-2">
        @if($topCities->isNotEmpty())
        <flux:card>
            <flux:heading size="sm" class="mb-4">Top Cities</flux:heading>
            <div class="space-y-2">
                @foreach($topCities as $city => $count)
                <div class="flex items-center justify-between">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $city }}</span>
                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $count }}</span>
                </div>
                @endforeach
            </div>
        </flux:card>
        @endif

        @if($utmSources->isNotEmpty())
        <flux:card>
            <flux:heading size="sm" class="mb-4">Traffic Sources (UTM)</flux:heading>
            <div class="space-y-2">
                @foreach($utmSources as $source => $count)
                <div class="flex items-center justify-between">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $source }}</span>
                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $count }}</span>
                </div>
                @endforeach
            </div>
        </flux:card>
        @endif
    </div>
    @endif

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div class="flex-1">
                <flux:input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by name, email, phone, city..." 
                    icon="magnifying-glass"
                />
            </div>
            <flux:select wire:model.live="dateFilter" class="w-full sm:w-40">
                <flux:select.option value="all">All Time</flux:select.option>
                <flux:select.option value="today">Today</flux:select.option>
                <flux:select.option value="week">This Week</flux:select.option>
                <flux:select.option value="month">This Month</flux:select.option>
            </flux:select>
        </div>
    </flux:card>

    {{-- Submissions Table --}}
    @if($submissions->isEmpty())
        <flux:card>
            <div class="py-12 text-center text-zinc-500 dark:text-zinc-400">
                <flux:icon.envelope class="mx-auto mb-4 size-12 opacity-50" />
                <p>No contact submissions yet.</p>
            </div>
        </flux:card>
    @else
        <flux:card class="overflow-hidden !p-0">
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Contact</flux:table.column>
                        <flux:table.column>Location</flux:table.column>
                        <flux:table.column>Message</flux:table.column>
                        <flux:table.column>Source</flux:table.column>
                        <flux:table.column>Date</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($submissions as $submission)
                            <flux:table.row>
                                <flux:table.cell>
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ $submission->name }}</div>
                                        <a href="mailto:{{ $submission->email }}" class="text-sm text-sky-600 hover:underline">{{ $submission->email }}</a>
                                        <a href="tel:{{ $submission->phone }}" class="block text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">{{ $submission->phone }}</a>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="text-sm">
                                        @if($submission->city)
                                            <span class="font-medium text-zinc-900 dark:text-white">{{ $submission->city }}</span>
                                        @endif
                                        @if($submission->address)
                                            <div class="text-zinc-500 dark:text-zinc-400">{{ Str::limit($submission->address, 30) }}</div>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="max-w-xs text-sm text-zinc-600 dark:text-zinc-300">
                                        {{ Str::limit($submission->message, 60) }}
                                    </div>
                                    @if($submission->availability)
                                        <div class="mt-1 text-xs text-zinc-500">📅 {{ is_array($submission->availability) ? implode(', ', Arr::flatten($submission->availability)) : $submission->availability }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="text-xs">
                                        @if($submission->utm_source)
                                            <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400">
                                                {{ $submission->utm_source }}
                                            </span>
                                        @endif
                                        @if($submission->utm_campaign)
                                            <div class="mt-1 text-zinc-500">{{ $submission->utm_campaign }}</div>
                                        @endif
                                        @if($submission->referrer)
                                            <div class="mt-1 max-w-[150px] truncate text-zinc-400" title="{{ $submission->referrer }}">
                                                {{ parse_url($submission->referrer, PHP_URL_HOST) ?: $submission->referrer }}
                                            </div>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $submission->created_at->format('M j, Y') }}
                                        <div class="text-xs">{{ $submission->created_at->format('g:i A') }}</div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item icon="envelope" href="mailto:{{ $submission->email }}">
                                                Email
                                            </flux:menu.item>
                                            <flux:menu.item icon="phone" href="tel:{{ $submission->phone }}">
                                                Call
                                            </flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item 
                                                icon="trash" 
                                                variant="danger"
                                                wire:click="delete({{ $submission->id }})"
                                                wire:confirm="Are you sure you want to delete this submission?"
                                            >
                                                Delete
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </flux:card>

        {{-- Pagination --}}
        <div class="mt-4">
            {{ $submissions->links() }}
        </div>
    @endif
</div>
