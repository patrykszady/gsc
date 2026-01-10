<?php

namespace App\Livewire\Admin;

use App\Models\ContactSubmission;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Tag;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.admin.dashboard', [
            'projectCount' => Project::count(),
            'publishedCount' => Project::published()->count(),
            'imageCount' => ProjectImage::count(),
            'tagCount' => Tag::count(),
            'leadCount' => ContactSubmission::count(),
            'leadsToday' => ContactSubmission::whereDate('created_at', today())->count(),
            'leadsThisWeek' => ContactSubmission::where('created_at', '>=', now()->subWeek())->count(),
            'recentProjects' => Project::with('coverImage')
                ->latest()
                ->take(5)
                ->get(),
            'recentLeads' => ContactSubmission::latest()->take(5)->get(),
        ]);
    }
}
