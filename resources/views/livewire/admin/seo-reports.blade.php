<div class="min-w-0 overflow-x-hidden">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" class="mb-2">SEO Reports Dashboard</flux:heading>
            <flux:text class="text-zinc-500">Daily scheduled reports with live health scoring and freshness visualization.</flux:text>
        </div>
        <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="refreshDashboard">Refresh metrics</flux:button>
    </div>

    @if ($flash)
        <flux:callout variant="success" class="mb-4">{{ $flash }}</flux:callout>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <flux:card class="p-4">
            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">SEO Health</flux:text>
            <div class="mt-2 text-3xl font-semibold text-zinc-900 dark:text-white">{{ $this->healthSnapshot['score'] }}</div>
            <flux:text class="text-xs text-zinc-500">Out of 100</flux:text>
        </flux:card>
        <flux:card class="p-4">
            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">Reports</flux:text>
            <div class="mt-2 text-3xl font-semibold text-zinc-900 dark:text-white">{{ $this->reportStats['generated'] }}/{{ $this->reportStats['total'] }}</div>
            <flux:text class="text-xs text-zinc-500">Generated files</flux:text>
        </flux:card>
        <flux:card class="p-4">
            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">Fresh (24h)</flux:text>
            <div class="mt-2 text-3xl font-semibold text-emerald-600">{{ $this->reportStats['fresh'] }}</div>
            <flux:text class="text-xs text-zinc-500">Auto-updated today</flux:text>
        </flux:card>
        <flux:card class="p-4">
            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">Stale</flux:text>
            <div class="mt-2 text-3xl font-semibold text-amber-600">{{ $this->reportStats['stale'] }}</div>
            <flux:text class="text-xs text-zinc-500">Older than 24h</flux:text>
        </flux:card>
        <flux:card class="p-4">
            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">Last Update</flux:text>
            <div class="mt-2 text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->reportStats['last_update'] ?? 'Never' }}</div>
            <flux:text class="text-xs text-zinc-500">Most recent report write</flux:text>
        </flux:card>
    </div>

    @php
        $snapshot = $this->searchSnapshot;
        $rankTracked = max(1, $snapshot['rankings']['tracked'] ?? 1);
        $trendUnitLabel = $trendMetric === 'impressions' ? 'impressions' : 'clicks';
    @endphp

    <div class="mb-6 grid min-w-0 gap-6 xl:grid-cols-3">
        @foreach ($snapshot['channels'] as $channel)
            @php
                $delta = (float) $channel['delta_clicks'];
                $deltaClass = $delta >= 0 ? 'text-emerald-600' : 'text-rose-600';
                $deltaPrefix = $delta >= 0 ? '+' : '';
            @endphp
            <flux:card class="min-w-0 overflow-hidden p-5">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $channel['label'] }}</flux:text>
                    <span class="text-xs font-semibold {{ $deltaClass }}">{{ $deltaPrefix }}{{ number_format($delta, 1) }}%</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="min-w-0">
                        <flux:text class="text-[11px] uppercase tracking-wide text-zinc-500">Clicks (7d)</flux:text>
                        <div class="truncate text-lg font-semibold text-zinc-900 dark:text-white">{{ number_format($channel['clicks']) }}</div>
                    </div>
                    <div class="min-w-0">
                        <flux:text class="text-[11px] uppercase tracking-wide text-zinc-500">Impressions</flux:text>
                        <div class="truncate text-lg font-semibold text-zinc-900 dark:text-white">{{ number_format($channel['impressions']) }}</div>
                    </div>
                    <div class="min-w-0">
                        <flux:text class="text-[11px] uppercase tracking-wide text-zinc-500">CTR / Pos</flux:text>
                        <div class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ number_format($channel['ctr'], 2) }}%
                            @if ($channel['position'] > 0)
                                <span class="text-sm font-medium text-zinc-500"> · {{ number_format($channel['position'], 1) }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </flux:card>
        @endforeach
    </div>

    <div class="mb-6 grid min-w-0 gap-6 xl:grid-cols-3">
        <flux:card class="min-w-0 overflow-hidden p-5 xl:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="md">Search Click Trend ({{ $trendDays }} days)</flux:heading>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:text class="text-xs text-zinc-500">Google + Bing ({{ $trendUnitLabel }})</flux:text>
                    <div class="flex items-center gap-1">
                        <flux:button
                            size="xs"
                            variant="ghost"
                            wire:click="setTrendMetric('clicks')"
                            class="{{ $trendMetric === 'clicks' ? 'bg-sky-100! text-sky-800! dark:bg-sky-900/30! dark:text-sky-200!' : '' }}"
                        >
                            Clicks
                        </flux:button>
                        <flux:button
                            size="xs"
                            variant="ghost"
                            wire:click="setTrendMetric('impressions')"
                            class="{{ $trendMetric === 'impressions' ? 'bg-sky-100! text-sky-800! dark:bg-sky-900/30! dark:text-sky-200!' : '' }}"
                        >
                            Impressions
                        </flux:button>
                    </div>
                    <flux:button
                        size="xs"
                        variant="ghost"
                        wire:click="toggleTrendCombined"
                        class="{{ $trendCombined === 1 ? 'bg-emerald-100! text-emerald-800! dark:bg-emerald-900/30! dark:text-emerald-200!' : '' }}"
                    >
                        Combined {{ $trendCombined === 1 ? 'On' : 'Off' }}
                    </flux:button>
                    <div class="flex items-center gap-1">
                        @foreach ([7, 14, 30] as $days)
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="setTrendDays({{ $days }})"
                                class="{{ $trendDays === $days ? 'bg-sky-100! text-sky-800! dark:bg-sky-900/30! dark:text-sky-200!' : '' }}"
                            >
                                {{ $days }}d
                            </flux:button>
                        @endforeach
                    </div>
                </div>
            </div>
            <flux:chart :value="$this->trendChartData" wire:key="trend-chart-{{ $trendMetric }}-{{ $trendDays }}-{{ $trendCombined }}" class="min-w-0">
                <flux:chart.viewport class="h-72">
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

                        <flux:chart.line field="gsc" class="text-sky-500" />
                        <flux:chart.line field="bing" class="text-emerald-500" />
                        @if ($trendCombined === 1)
                            <flux:chart.line field="combined" class="text-violet-500" />
                        @endif
                    </flux:chart.svg>

                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="date" />
                        <flux:chart.tooltip.value field="gsc" :label="'Google ' . $trendUnitLabel">
                            <span class="size-2.5 rounded-full bg-sky-500"></span>
                        </flux:chart.tooltip.value>
                        <flux:chart.tooltip.value field="bing" :label="'Bing ' . $trendUnitLabel">
                            <span class="size-2.5 rounded-full bg-emerald-500"></span>
                        </flux:chart.tooltip.value>
                        @if ($trendCombined === 1)
                            <flux:chart.tooltip.value field="combined" :label="'Combined ' . $trendUnitLabel">
                                <span class="size-2.5 rounded-full bg-violet-500"></span>
                            </flux:chart.tooltip.value>
                        @endif
                    </flux:chart.tooltip>
                </flux:chart.viewport>

                <div class="mt-2 flex flex-wrap items-center gap-2 px-1">
                    <flux:chart.legend :label="'Google ' . $trendUnitLabel" class="p-1 text-xs text-zinc-500">
                        <flux:chart.legend.indicator class="bg-sky-500" />
                    </flux:chart.legend>
                    <flux:chart.legend :label="'Bing ' . $trendUnitLabel" class="p-1 text-xs text-zinc-500">
                        <flux:chart.legend.indicator class="bg-emerald-500" />
                    </flux:chart.legend>
                    @if ($trendCombined === 1)
                        <flux:chart.legend :label="'Combined ' . $trendUnitLabel" class="p-1 text-xs text-zinc-500">
                            <flux:chart.legend.indicator class="bg-violet-500" />
                        </flux:chart.legend>
                    @endif
                </div>
            </flux:chart>
        </flux:card>

        <flux:card class="min-w-0 overflow-hidden p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="md">Indexing Health</flux:heading>
                <flux:text class="text-xs text-zinc-500">URL Inspection states</flux:text>
            </div>
            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between"><span class="text-zinc-500">Tracked URLs</span><span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($snapshot['coverage']['total']) }}</span></div>
                <div class="flex items-center justify-between"><span class="text-zinc-500">Problem URLs</span><span class="font-semibold text-rose-600">{{ number_format($snapshot['coverage']['problem']) }}</span></div>
                <div class="flex items-center justify-between"><span class="text-zinc-500">Access forbidden</span><span class="font-semibold text-amber-600">{{ number_format($snapshot['coverage']['forbidden']) }}</span></div>
                <div class="flex items-center justify-between"><span class="text-zinc-500">Not indexed</span><span class="font-semibold text-amber-600">{{ number_format($snapshot['coverage']['not_indexed']) }}</span></div>
                <div class="flex items-center justify-between"><span class="text-zinc-500">Duplicate</span><span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($snapshot['coverage']['duplicate']) }}</span></div>
            </div>
        </flux:card>
    </div>

    <div class="mb-6 grid min-w-0 gap-6 xl:grid-cols-3">
        <flux:card class="min-w-0 overflow-hidden p-5 xl:col-span-2">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="md">Top Queries (Last {{ $topDays }} Days)</flux:heading>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:text class="text-xs text-zinc-500">From Search Console</flux:text>
                    <div class="flex items-center gap-1">
                        @foreach ([7, 28, 90] as $days)
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="setTopDays({{ $days }})"
                                class="{{ $topDays === $days ? 'bg-sky-100! text-sky-800! dark:bg-sky-900/30! dark:text-sky-200!' : '' }}"
                            >
                                {{ $days }}d
                            </flux:button>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            @php
                                $qcols = [
                                    'query' => ['label' => 'Query', 'align' => 'left'],
                                    'clicks' => ['label' => 'Clicks', 'align' => 'right'],
                                    'impressions' => ['label' => 'Impr.', 'align' => 'right'],
                                    'ctr' => ['label' => 'CTR', 'align' => 'right'],
                                    'position' => ['label' => 'Pos', 'align' => 'right'],
                                ];
                            @endphp
                            @foreach ($qcols as $col => $meta)
                                <th class="pb-2 {{ $loop->last ? '' : 'pr-2' }} {{ $meta['align'] === 'right' ? 'text-right' : '' }}">
                                    <button
                                        type="button"
                                        wire:click="sortTopQueries('{{ $col }}')"
                                        class="inline-flex items-center gap-1 uppercase tracking-wide hover:text-zinc-700 dark:hover:text-zinc-200 {{ $meta['align'] === 'right' ? 'flex-row-reverse' : '' }} {{ $topQueriesSort === $col ? 'text-zinc-700 dark:text-zinc-200' : '' }}"
                                    >
                                        <span>{{ $meta['label'] }}</span>
                                        @if ($topQueriesSort === $col)
                                            <span aria-hidden="true">{{ $topQueriesDir === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->topQueries as $q)
                            <tr>
                                <td class="max-w-65 py-2 pr-2 align-top font-medium text-zinc-800 dark:text-zinc-100">
                                    <span class="line-clamp-2">{{ $q['query'] }}</span>
                                </td>
                                <td class="py-2 pr-2 text-right">{{ number_format($q['clicks']) }}</td>
                                <td class="py-2 pr-2 text-right">{{ number_format($q['impressions']) }}</td>
                                <td class="py-2 pr-2 text-right">{{ number_format($q['ctr'], 2) }}%</td>
                                <td class="py-2 text-right">{{ number_format($q['position'], 1) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-3 text-zinc-500">No query data yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>

        <flux:card class="min-w-0 overflow-hidden p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="md">Ranking Distribution</flux:heading>
                <flux:text class="text-xs text-zinc-500">Latest tracked terms</flux:text>
            </div>
            <div class="space-y-3">
                @php
                    $segments = [
                        'Top 3' => ['count' => $snapshot['rankings']['top3'], 'class' => 'bg-emerald-500'],
                        'Top 10' => ['count' => $snapshot['rankings']['top10'], 'class' => 'bg-sky-500'],
                        'Top 20' => ['count' => $snapshot['rankings']['top20'], 'class' => 'bg-amber-500'],
                        '> 20' => ['count' => $snapshot['rankings']['below20'], 'class' => 'bg-zinc-400'],
                    ];
                @endphp
                @foreach ($segments as $label => $segment)
                    @php $pct = (int) round(($segment['count'] / $rankTracked) * 100); @endphp
                    <div class="px-1">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $label }}</span>
                            <span class="text-zinc-500">{{ $segment['count'] }} ({{ $pct }}%)</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded-full {{ $segment['class'] }}" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    </div>

    <div class="mb-6 grid min-w-0 gap-6 xl:grid-cols-3">
        <flux:card class="min-w-0 overflow-hidden p-5 xl:col-span-2">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="md">Top Pages (Last {{ $topDays }} Days)</flux:heading>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:text class="text-xs text-zinc-500">Landing pages</flux:text>
                    <div class="flex items-center gap-1">
                        @foreach ([7, 28, 90] as $days)
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="setTopDays({{ $days }})"
                                class="{{ $topDays === $days ? 'bg-sky-100! text-sky-800! dark:bg-sky-900/30! dark:text-sky-200!' : '' }}"
                            >
                                {{ $days }}d
                            </flux:button>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            @php
                                $pcols = [
                                    'page' => ['label' => 'Page', 'align' => 'left'],
                                    'clicks' => ['label' => 'Clicks', 'align' => 'right'],
                                    'impressions' => ['label' => 'Impr.', 'align' => 'right'],
                                    'ctr' => ['label' => 'CTR', 'align' => 'right'],
                                    'position' => ['label' => 'Pos', 'align' => 'right'],
                                ];
                            @endphp
                            @foreach ($pcols as $col => $meta)
                                <th class="pb-2 {{ $loop->last ? '' : 'pr-2' }} {{ $meta['align'] === 'right' ? 'text-right' : '' }}">
                                    <button
                                        type="button"
                                        wire:click="sortTopPages('{{ $col }}')"
                                        class="inline-flex items-center gap-1 uppercase tracking-wide hover:text-zinc-700 dark:hover:text-zinc-200 {{ $meta['align'] === 'right' ? 'flex-row-reverse' : '' }} {{ $topPagesSort === $col ? 'text-zinc-700 dark:text-zinc-200' : '' }}"
                                    >
                                        <span>{{ $meta['label'] }}</span>
                                        @if ($topPagesSort === $col)
                                            <span aria-hidden="true">{{ $topPagesDir === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->topPages as $p)
                            <tr>
                                <td class="max-w-70 py-2 pr-2 align-top font-medium text-zinc-800 dark:text-zinc-100">
                                    <span class="line-clamp-2">{{ $p['page'] }}</span>
                                </td>
                                <td class="py-2 pr-2 text-right">{{ number_format($p['clicks']) }}</td>
                                <td class="py-2 pr-2 text-right">{{ number_format($p['impressions']) }}</td>
                                <td class="py-2 pr-2 text-right">{{ number_format($p['ctr'], 2) }}%</td>
                                <td class="py-2 text-right">{{ number_format($p['position'], 1) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-3 text-zinc-500">No page data yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>

        <flux:card class="min-w-0 overflow-hidden p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="md">Priority Actions</flux:heading>
                <flux:text class="text-xs text-zinc-500">Automated guidance</flux:text>
            </div>
            <ul class="space-y-2 text-sm text-zinc-700 dark:text-zinc-200">
                @foreach ($snapshot['action_items'] as $item)
                    <li class="rounded-lg bg-zinc-50 p-2.5 leading-snug dark:bg-zinc-800/70">{{ $item }}</li>
                @endforeach
            </ul>
        </flux:card>
    </div>

    <div class="mb-6 grid min-w-0 gap-6 lg:grid-cols-2">
        <flux:card class="min-w-0 overflow-hidden p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="md">SEO Pillars</flux:heading>
                <flux:text class="text-xs text-zinc-500">From <code>seo:health --json</code></flux:text>
            </div>
            <div class="space-y-3">
                @forelse ($this->healthSnapshot['pillars'] as $pillar)
                    <div class="px-1">
                        <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                            <span class="min-w-0 truncate font-medium text-zinc-700 dark:text-zinc-200">{{ $pillar['name'] }}</span>
                            <span class="text-zinc-500">{{ $pillar['score'] }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div
                                @class([
                                    'h-full max-w-full rounded-full',
                                    'bg-emerald-500' => $pillar['color'] === 'emerald',
                                    'bg-amber-500' => $pillar['color'] === 'amber',
                                    'bg-rose-500' => $pillar['color'] === 'rose',
                                ])
                                style="width: {{ $pillar['score'] }}%"
                            ></div>
                        </div>
                    </div>
                @empty
                    <flux:text class="text-sm text-zinc-500">No health snapshot yet.</flux:text>
                @endforelse
            </div>
        </flux:card>

        <flux:card class="min-w-0 overflow-hidden p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="md">Report Freshness</flux:heading>
                <flux:text class="text-xs text-zinc-500">Higher is newer</flux:text>
            </div>
            <div class="space-y-3">
                @foreach ($this->files as $f)
                    <div class="px-1">
                        <div class="mb-1 flex min-w-0 items-center justify-between gap-2 text-sm">
                            <span class="truncate font-medium text-zinc-700 dark:text-zinc-200">{{ $f['label'] }}</span>
                            <span class="text-zinc-500">{{ $f['age'] ?? 'never' }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div
                                @class([
                                    'h-full max-w-full rounded-full',
                                    'bg-emerald-500' => $f['status'] === 'fresh',
                                    'bg-amber-500' => $f['status'] === 'stale',
                                    'bg-zinc-400' => $f['status'] === 'missing',
                                ])
                                style="width: {{ $f['freshness_pct'] }}%"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    </div>

    <div class="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,360px)_minmax(0,1fr)]">
        {{-- Report list --}}
        <flux:card class="min-w-0 overflow-hidden p-0">
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
                                        @if ($f['status'] === 'fresh')
                                            <span class="ml-1 inline-flex rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">fresh</span>
                                        @elseif ($f['status'] === 'stale')
                                            <span class="ml-1 inline-flex rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">stale</span>
                                        @endif
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
        <flux:card class="min-w-0 overflow-hidden">
            @php
                $activeFile = collect($this->files)->firstWhere('key', $active);
            @endphp
            @if ($active && $activeFile)
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" class="min-w-0 truncate">{{ $activeFile['label'] }}</flux:heading>
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <code class="max-w-full break-all whitespace-normal text-xs text-zinc-500">php artisan {{ $activeFile['command'] }}</code>
                        <flux:button size="xs" variant="ghost" wire:click="regenerate('{{ $active }}')" icon="arrow-path">
                            Run now
                        </flux:button>
                    </div>
                </div>

                <div class="seo-report max-w-none">
                    {!! $this->activeHtml !!}
                </div>
            @else
                <flux:text class="text-zinc-500">Pick a report from the list to view its latest contents.</flux:text>
            @endif
        </flux:card>
    </div>
</div>
