<?php

namespace App\Observers;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\SubmitUrlsToIndexNow;
use App\Jobs\UploadProjectImageToGooglePlaces;
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

        // Project description is now generated automatically after all image AI content
        // completes (triggered by GenerateAiContentJob::maybeGenerateProjectDescription).
    }

    public function updated(Project $project): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($project);

        // First time the project is marked completed → generate a review-request shortlink
        // and log it so the team can include it in follow-up messages to the homeowner.
        if ($project->wasChanged('completed_at')
            && ! is_null($project->completed_at)
            && is_null($project->getOriginal('completed_at'))) {
            try {
                $shortUrl = $project->getReviewRequestUrl();
                if ($shortUrl) {
                    Log::channel('single')->info('[ReviewRequest] Project completed; share this link with the homeowner.', [
                        'project_id'   => $project->id,
                        'project_slug' => $project->slug,
                        'short_url'    => $shortUrl,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[ReviewRequest] Failed to generate shortlink: '.$e->getMessage(), [
                    'project_id' => $project->id,
                ]);
            }
        }
        
        // Re-queue AI description if it was cleared
        if ($project->wasChanged('description') && empty($project->description) && config('services.google.gemini_api_key')) {
            GenerateAiContentJob::dispatch($project, overwrite: false, regenerateSitemap: true)
                ->onQueue('ai-content')
                ->delay(now()->addSeconds(10));
        }

        if (
            $project->wasChanged('is_published')
            && $project->is_published
            && config('services.google.business_profile.enabled')
        ) {
            $project->images()
                ->whereNull('google_places_uploaded_at')
                ->pluck('id')
                ->each(fn ($imageId) => UploadProjectImageToGooglePlaces::dispatch($imageId)
                    ->onQueue('media-sync')
                    ->delay(now()->addSeconds(10))
                );
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
            // Submit both the individual project page and the gallery index.
            // Queued so the 30s HTTP timeout never blocks the admin save request.
            $urls = [
                route('projects.show', $project),
                route('projects.index'),
            ];

            SubmitUrlsToIndexNow::dispatch($urls)->onQueue('default')->delay(now()->addSeconds(15));
        } catch (\Exception $e) {
            Log::warning('IndexNow: Failed to queue project URL submission', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
