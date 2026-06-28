<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">GSC Errors</flux:heading>
            <flux:text class="mt-1 text-zinc-500">Google Search Console URL Inspection states with filters and CSV export.</flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('admin.seo-reports.index') }}" variant="ghost" icon="arrow-left">
                Back to SEO Reports
            </flux:button>
            <flux:button
                wire:click="refreshInBackground"
                wire:confirm="Queue a full sitemap GSC inspection in the background?"
                variant="ghost"
                icon="arrow-path"
            >
                Refresh (Background)
            </flux:button>
            <flux:button wire:click="exportCsv" variant="ghost" icon="arrow-down-tray">
                Export CSV
            </flux:button>
        </div>
    </div>

    @if ($flash)
        <flux:callout variant="success" class="mb-4">{{ $flash }}</flux:callout>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
        <flux:card>
            <div class="text-sm text-zinc-500">Tracked URLs</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['tracked']) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Problem URLs</div>
            <div class="mt-1 text-2xl font-bold text-rose-600">{{ number_format($stats['problem']) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Pass URLs</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600">{{ number_format($stats['pass']) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Latest Inspection</div>
            <div class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $stats['latest_inspected'] ?? 'Never' }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Sitemap URLs</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['sitemap_urls'] ?? 0) }}</div>
        </flux:card>
        <flux:card>
            <div class="text-sm text-zinc-500">Inspection Coverage</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($stats['inspection_coverage_pct'] ?? 0) }}%</div>
            <div class="text-xs text-zinc-500">Tracked vs sitemap</div>
        </flux:card>
    </div>

    <flux:card class="mb-6">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="md">Enhancements &amp; Shopping Signals</flux:heading>
            <flux:text class="text-xs text-zinc-500">Source: URL Inspection rich-results payload</flux:text>
        </div>

        @if(! $enhancements['available'])
            <flux:text class="text-sm text-zinc-500">Enhancement issue table is not available yet. Run migrations, then run <code>php artisan seo:gsc-inspect-bulk --limit=0 --markdown</code>.</flux:text>
        @else
            <div class="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Total enhancement issues</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ number_format($enhancements['total_issues']) }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Affected URLs</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ number_format($enhancements['affected_urls']) }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Product issues</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ number_format($enhancements['product_issues']) }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Shopping-related issues</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ number_format($enhancements['shopping_issues']) }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Latest rich-results scan</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $enhancements['latest_inspected'] ?? 'Never' }}</div>
                </div>
            </div>

            @if(!empty($enhancements['by_type']))
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($enhancements['by_type'] as $item)
                        <div class="rounded-md border border-zinc-200 p-2.5 text-sm dark:border-zinc-700">
                            <div class="truncate text-zinc-500">{{ $item['type'] }}</div>
                            <div class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ number_format($item['count']) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </flux:card>

    <flux:card class="mb-6">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="md">Last Reindex Run</flux:heading>
            @if($reindexReport['generated'])
                <flux:text class="text-xs text-zinc-500">{{ $reindexReport['generated'] }}</flux:text>
            @endif
        </div>

        @if(! $reindexReport['available'])
            <flux:text class="text-sm text-zinc-500">No reindex report yet. Run <code>php artisan seo:reindex-problem-pages --auto</code> to generate one.</flux:text>
        @else
            <div class="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Mode</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $reindexReport['mode'] ?? 'unknown' }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Detected</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ number_format((int)($reindexReport['detected'] ?? 0)) }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Submitted</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ number_format((int)($reindexReport['submitted'] ?? 0)) }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Excluded 410</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ number_format((int)($reindexReport['excluded410'] ?? 0)) }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Excluded Not in Sitemap</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ number_format((int)($reindexReport['excludedNotInSitemap'] ?? 0)) }}</div>
                </div>
            </div>

            <details class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <summary class="cursor-pointer text-sm font-medium text-zinc-700 dark:text-zinc-200">View full report</summary>
                <pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap rounded bg-zinc-50 p-3 text-xs text-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-200">{{ $reindexReport['body'] }}</pre>
            </details>
        @endif
    </flux:card>

    <flux:card class="mb-6">
        <div class="grid gap-3 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Search URL, state, verdict..." icon="magnifying-glass" />
            </div>

            <flux:select wire:model.live="scope">
                <flux:select.option value="problems">Problems Only</flux:select.option>
                <flux:select.option value="all">All URLs</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="issueFilter">
                <flux:select.option value="all">All Issues</flux:select.option>
                <flux:select.option value="blocked">Blocked</flux:select.option>
                <flux:select.option value="not_indexed">Not Indexed</flux:select.option>
                <flux:select.option value="duplicate">Duplicate/Canonical</flux:select.option>
                <flux:select.option value="soft_404">Soft 404</flux:select.option>
                <flux:select.option value="fetch_error">Fetch Error</flux:select.option>
                <flux:select.option value="other">Other</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="verdictFilter">
                <flux:select.option value="all">All Verdicts</flux:select.option>
                <flux:select.option value="PASS">PASS</flux:select.option>
                <flux:select.option value="FAIL">FAIL</flux:select.option>
                <flux:select.option value="NEUTRAL">NEUTRAL</flux:select.option>
                <flux:select.option value="unknown">UNKNOWN</flux:select.option>
            </flux:select>
        </div>
    </flux:card>

    <flux:card>
        @if($errors->isEmpty())
            <div class="py-10 text-center text-zinc-500">No rows match current filters.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="pb-2 pr-2">URL</th>
                            <th class="pb-2 pr-2">Issue</th>
                            <th class="pb-2 pr-2">Verdict</th>
                            <th class="pb-2 pr-2">Coverage State</th>
                            <th class="pb-2 pr-2">Fetch</th>
                            <th class="pb-2 pr-2">Last Crawl</th>
                            <th class="pb-2">Changed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($errors as $row)
                            @php
                                $path = parse_url((string) $row->url, PHP_URL_PATH) ?: '/';
                                $text = strtolower(trim(($row->coverage_state ?? '') . ' ' . ($row->page_fetch_state ?? '')));
                                $issue = 'Other';
                                if ($text === '' && strtoupper((string) ($row->verdict ?? '')) === 'PASS') $issue = 'Indexed';
                                elseif (str_contains($text, 'forbidden') || str_contains($text, 'robots')) $issue = 'Blocked';
                                elseif (str_contains($text, 'not indexed')) $issue = 'Not indexed';
                                elseif (str_contains($text, 'duplicate') || str_contains($text, 'canonical')) $issue = 'Duplicate/canonical';
                                elseif (str_contains($text, 'soft 404')) $issue = 'Soft 404';
                                elseif (str_contains($text, 'server') || str_contains($text, 'not found') || str_contains($text, 'redirect')) $issue = 'Fetch error';
                            @endphp
                            <tr>
                                <td class="max-w-72 py-2 pr-2 align-top">
                                    <a href="{{ $row->url }}" target="_blank" rel="noopener" class="font-medium text-sky-700 hover:underline dark:text-sky-300">
                                        <span class="line-clamp-2">{{ $path }}</span>
                                    </a>
                                    @if(($row->consecutive_failures ?? 0) > 0)
                                        <div class="mt-1 text-xs text-rose-600">Fails: {{ $row->consecutive_failures }}</div>
                                    @endif
                                </td>
                                <td class="py-2 pr-2">{{ $issue }}</td>
                                <td class="py-2 pr-2">{{ $row->verdict ?? 'UNKNOWN' }}</td>
                                <td class="max-w-72 py-2 pr-2"><span class="line-clamp-2">{{ $row->coverage_state ?: '—' }}</span></td>
                                <td class="max-w-56 py-2 pr-2"><span class="line-clamp-2">{{ $row->page_fetch_state ?: '—' }}</span></td>
                                <td class="py-2 pr-2">{{ $row->last_crawl_time?->toDateString() ?? '—' }}</td>
                                <td class="py-2">{{ ($row->last_changed_at ?? $row->inspected_at)?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($errors->hasPages())
                <div class="mt-4">
                    {{ $errors->links() }}
                </div>
            @endif
        @endif
    </flux:card>
</div>
