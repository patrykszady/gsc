<?php

namespace App\Observers;

use App\Models\Project;
use App\Services\IndexNowService;
use Illuminate\Support\Facades\Log;

class ProjectObserver
{
    public function __construct(
        protected IndexNowService $indexNow
    ) {}

    public function created(Project $project): void
    {
        $this->submitToIndexNow($project);
    }

    public function updated(Project $project): void
    {
        $this->submitToIndexNow($project);
    }

    public function deleted(Project $project): void
    {
        $this->submitToIndexNow($project);
    }

    protected function submitToIndexNow(Project $project): void
    {
        if (! config('indexnow.auto_submit', true)) {
            return;
        }

        // Only submit if project is published
        if (! $project->is_published) {
            return;
        }

        try {
            // Projects page (gallery) is affected when projects change
            $urls = [
                route('projects.index'),
            ];

            $this->indexNow->submitBatch($urls);
        } catch (\Exception $e) {
            Log::warning('IndexNow: Failed to submit project URL', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
