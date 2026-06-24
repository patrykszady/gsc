<div>
    <flux:heading size="xl" class="mb-6">Dashboard</flux:heading>

    {{-- Stats Grid --}}
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400">
                    <flux:icon.folder class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $projectCount }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Total Projects</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                    <flux:icon.check-circle class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $publishedCount }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Published</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                    <flux:icon.photo class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $imageCount }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Images</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                    <flux:icon.tag class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $tagCount }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Tags</div>
                </div>
            </div>
        </flux:card>

        <flux:card class="cursor-pointer transition hover:shadow-md" onclick="window.location='{{ route('admin.leads.index') }}'">
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                    <flux:icon.envelope class="size-6" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $leadCount }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        Leads 
                        @if($leadsToday > 0)
                            <span class="text-green-600">(+{{ $leadsToday }} today)</span>
                        @endif
                    </div>
                </div>
            </div>
        </flux:card>
    </div>

    {{-- Quick Actions --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-4">Quick Actions</flux:heading>
        <div class="flex flex-wrap gap-3">
            <flux:button href="{{ route('admin.projects.create') }}" icon="plus">
                New Project
            </flux:button>
            <flux:button href="{{ route('admin.testimonials.create') }}" icon="star">
                New Review
            </flux:button>
            <flux:button href="{{ route('admin.projects.index') }}" variant="ghost" icon="folder">
                View All Projects
            </flux:button>
            <flux:button href="{{ route('admin.testimonials.index') }}" variant="ghost" icon="star">
                View All Reviews
            </flux:button>
            <flux:button href="{{ route('admin.leads.index') }}" variant="ghost" icon="envelope">
                View Leads
                @if($leadsThisWeek > 0)
                    <flux:badge color="green" size="sm" class="ml-2">{{ $leadsThisWeek }}</flux:badge>
                @endif
            </flux:button>
        </div>
    </div>

    {{-- Recent Leads --}}
    @if($recentLeads->isNotEmpty())
    <div class="mb-8">
        <div class="mb-4 flex items-center justify-between">
            <flux:heading size="lg">Recent Leads</flux:heading>
            <flux:button href="{{ route('admin.leads.index') }}" variant="ghost" size="sm">
                View All →
            </flux:button>
        </div>
        @if(session('status'))
            <flux:callout variant="success" icon="check-circle" class="mb-4" heading="{{ session('status') }}" />
        @endif
        <flux:card class="overflow-hidden !p-0">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Contact</flux:table.column>
                    <flux:table.column>City</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>When</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($recentLeads as $lead)
                        <flux:table.row>
                            <flux:table.cell>
                                <div>
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $lead->name }}</div>
                                    <div class="text-sm text-zinc-500">{{ $lead->email }}</div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-600 dark:text-zinc-300">
                                {{ $lead->city ?? '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($lead->isSpam())
                                    <flux:badge color="red" size="sm" title="{{ $lead->spam_reason }}">Spam</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">Real</flux:badge>
                                    @if($lead->wasSentToHive())
                                        <flux:badge color="sky" size="sm" class="ml-1">Hive</flux:badge>
                                    @endif
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500 dark:text-zinc-400">
                                {{ $lead->created_at->diffForHumans() }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="eye"
                                        wire:click="view({{ $lead->id }})"
                                    >
                                        Read
                                    </flux:button>
                                    @if($lead->isSpam())
                                        <flux:button
                                            size="sm"
                                            variant="primary"
                                            icon="arrow-up-right"
                                            wire:click="convertToReal({{ $lead->id }})"
                                            wire:confirm="Convert this spam submission to a real lead and send it to the Hive dashboard?"
                                        >
                                            Convert to Real
                                        </flux:button>
                                    @else
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="shield-exclamation"
                                            wire:click="markSpam({{ $lead->id }})"
                                            wire:confirm="Mark this lead as spam?"
                                        />
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>
    @endif

    {{-- Recent Projects --}}
    <div>
        <flux:heading size="lg" class="mb-4">Recent Projects</flux:heading>
        
        @if($recentProjects->isEmpty())
            <flux:card>
                <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                    No projects yet. <a href="{{ route('admin.projects.create') }}" class="text-sky-500 hover:underline">Create your first project</a>
                </div>
            </flux:card>
        @else
            <flux:card class="overflow-hidden !p-0">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Project</flux:table.column>
                        <flux:table.column>Type</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Created</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($recentProjects as $project)
                            <flux:table.row>
                                <flux:table.cell>
                                    <div class="flex items-center gap-3">
                                        @if($project->coverImage)
                                            <img src="{{ $project->coverImage->getThumbnailUrl('thumb') }}" alt="" class="size-10 rounded-lg object-cover">
                                        @else
                                            <div class="flex size-10 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                                <flux:icon.photo class="size-5 text-zinc-400" />
                                            </div>
                                        @endif
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $project->title }}</span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm">{{ \App\Models\Project::projectTypes()[$project->project_type] ?? $project->project_type }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if($project->is_published)
                                        <flux:badge color="green" size="sm">Published</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">Draft</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-zinc-500 dark:text-zinc-400">
                                    {{ $project->created_at->diffForHumans() }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:button href="{{ route('admin.projects.edit', $project) }}" size="sm" variant="ghost" icon="pencil">
                                        Edit
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    </div>

    {{-- Lead detail modal --}}
    <flux:modal name="lead-detail" class="md:w-[32rem]">
        @if($viewing)
            <div class="space-y-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">{{ $viewing->name }}</flux:heading>
                        <flux:subheading>
                            {{ $viewing->created_at->timezone('America/Chicago')->format('M j, Y g:i A') }} CT
                        </flux:subheading>
                    </div>
                    @if($viewing->isSpam())
                        <flux:badge color="red" title="{{ $viewing->spam_reason }}">Spam</flux:badge>
                    @else
                        <flux:badge color="green">Real</flux:badge>
                    @endif
                </div>

                <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-zinc-500">Email</div>
                        <div class="text-zinc-700 dark:text-zinc-300">{{ $viewing->email ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-zinc-500">Phone</div>
                        <div class="text-zinc-700 dark:text-zinc-300">{{ $viewing->phone ?: '—' }}</div>
                    </div>
                    @if($viewing->city || $viewing->address)
                        <div class="sm:col-span-2">
                            <div class="text-xs uppercase tracking-wide text-zinc-500">Location</div>
                            <div class="text-zinc-700 dark:text-zinc-300">{{ collect([$viewing->city, $viewing->address])->filter()->implode(' — ') ?: '—' }}</div>
                        </div>
                    @endif
                    @if($viewing->spam_reason)
                        <div class="sm:col-span-2">
                            <div class="text-xs uppercase tracking-wide text-zinc-500">Flagged reason</div>
                            <div class="text-zinc-700 dark:text-zinc-300">{{ $viewing->spam_reason }}</div>
                        </div>
                    @endif
                </div>

                <div>
                    <div class="mb-1 text-xs uppercase tracking-wide text-zinc-500">Message</div>
                    <div class="max-h-64 overflow-y-auto whitespace-pre-wrap rounded-lg bg-zinc-50 p-3 text-sm text-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-200">{{ $viewing->message ?: '—' }}</div>
                </div>

                @if($viewing->availability)
                    <div>
                        <div class="mb-1 text-xs uppercase tracking-wide text-zinc-500">Availability</div>
                        <div class="text-sm text-zinc-700 dark:text-zinc-300">{{ is_array($viewing->availability) ? implode(', ', \Illuminate\Support\Arr::flatten($viewing->availability)) : $viewing->availability }}</div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Close</flux:button>
                    </flux:modal.close>
                    @if($viewing->isSpam())
                        <flux:button
                            variant="primary"
                            icon="arrow-up-right"
                            wire:click="convertToReal({{ $viewing->id }})"
                            wire:confirm="Convert this submission to a real lead, send it to Hive, and stop flagging similar senders?"
                        >
                            Convert to Real
                        </flux:button>
                    @else
                        <flux:button
                            variant="danger"
                            icon="shield-exclamation"
                            wire:click="markSpam({{ $viewing->id }})"
                            wire:confirm="Mark this lead as spam and block similar senders going forward?"
                        >
                            Mark as Spam
                        </flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>
