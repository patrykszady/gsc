<?php

namespace App\Observers;

use App\Jobs\GenerateAiContentJob;
use App\Models\Project;
use App\Services\IndexNowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ProjectObserver
{
    public function __construct(
        protected IndexNowService $indexNow
    ) {}

    public function created(Project $project): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($project);
        
        // Queue AI description generation for new projects (with delay to allow images to be added first)
        if (config('services.google.gemini_api_key') && empty($project->description)) {
            GenerateAiContentJob::dispatch($project, overwrite: false, regenerateSitemap: true)
                ->onQueue('ai-content')
                ->delay(now()->addMinutes(2)); // Wait for images to be uploaded
        }
    }

    public function updated(Project $project): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($project);
        
        // Re-queue AI description if it was cleared
        if ($project->wasChanged('description') && empty($project->description) && config('services.google.gemini_api_key')) {
            GenerateAiContentJob::dispatch($project, overwrite: false, regenerateSitemap: true)
                ->onQueue('ai-content')
                ->delay(now()->addSeconds(10));
        }
    }

    public function deleted(Project $project): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($project);
    }

    protected function regenerateSitemap(): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Exception $e) {
            Log::warning('Failed to regenerate sitemap', [
                'error' => $e->getMessage(),
            ]);
        }
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
            // Submit both the individual project page and the gallery index
            $urls = [
                route('projects.show', $project),
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
