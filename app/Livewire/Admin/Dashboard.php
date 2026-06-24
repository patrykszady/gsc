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
    public ?int $viewingId = null;

    public function view(int $id): void
    {
        $this->viewingId = $id;
        $this->modal('lead-detail')->show();
    }

    public function convertToReal(int $id): void
    {
        $lead = ContactSubmission::find($id);
        if ($lead && $lead->isSpam()) {
            $lead->markAsReal();
            session()->flash('status', "Lead from {$lead->name} converted, sent to Hive, and similar senders will no longer be flagged.");
        }
        $this->modal('lead-detail')->close();
    }

    public function markSpam(int $id): void
    {
        $lead = ContactSubmission::find($id);
        if ($lead && ! $lead->isSpam()) {
            $lead->markAsSpam();
            session()->flash('status', "Lead from {$lead->name} marked as spam; similar senders will be blocked going forward.");
        }
        $this->modal('lead-detail')->close();
    }

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
            'viewing' => $this->viewingId ? ContactSubmission::find($this->viewingId) : null,
        ]);
    }
}
