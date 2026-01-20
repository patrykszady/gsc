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
        <flux:card class="overflow-hidden !p-0">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Contact</flux:table.column>
                    <flux:table.column>City</flux:table.column>
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
                            <flux:table.cell class="text-zinc-500 dark:text-zinc-400">
                                {{ $lead->created_at->diffForHumans() }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-2">
                                    <flux:button href="mailto:{{ $lead->email }}" size="sm" variant="ghost" icon="envelope" />
                                    <flux:button href="tel:{{ $lead->phone }}" size="sm" variant="ghost" icon="phone" />
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
</div>
