<?php

namespace App\Livewire\Admin;

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
            'recentProjects' => Project::with('coverImage')
                ->latest()
                ->take(5)
                ->get(),
        ]);
    }
}
