<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\SeoService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ProjectPage extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        // Only show published projects
        if (!$project->is_published) {
            abort(404);
        }

        $this->project = $project->load('images');

        SeoService::project($project);
    }

    protected function getProjectTypeLabel(): string
    {
        $types = Project::projectTypes();
        return $types[$this->project->project_type] ?? ucfirst(str_replace('-', ' ', $this->project->project_type));
    }

    protected function getRelatedProjects()
    {
        return Project::query()
            ->published()
            ->where('id', '!=', $this->project->id)
            ->where('project_type', $this->project->project_type)
            ->with('images')
            ->take(3)
            ->get();
    }

    public function render()
    {
        return view('livewire.project-page', [
            'projectTypeLabel' => $this->getProjectTypeLabel(),
            'relatedProjects' => $this->getRelatedProjects(),
        ]);
    }
}
