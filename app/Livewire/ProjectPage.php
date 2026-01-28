<?php

namespace App\Livewire;

use App\Models\AreaServed;
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

    protected function getLocationArea(): ?AreaServed
    {
        if (!$this->project->location) {
            return null;
        }

        // Extract city name from location (e.g., "Arlington Heights, IL" -> "Arlington Heights")
        $city = preg_replace('/,?\s*(IL|Illinois)$/i', '', $this->project->location);
        $city = trim($city);

        return AreaServed::where('city', $city)->first();
    }

    public function render()
    {
        return view('livewire.project-page', [
            'projectTypeLabel' => $this->getProjectTypeLabel(),
            'relatedProjects' => $this->getRelatedProjects(),
            'locationArea' => $this->getLocationArea(),
        ]);
    }
}
