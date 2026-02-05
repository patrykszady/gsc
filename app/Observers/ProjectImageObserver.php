<?php

namespace App\Observers;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\UploadProjectImageToGooglePlaces;
use App\Models\ProjectImage;
use App\Services\IndexNowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ProjectImageObserver
{
    public function __construct(
        protected IndexNowService $indexNow
    ) {}

    /**
     * Handle the ProjectImage "created" event.
     * When a new image is uploaded, queue AI content generation.
     */
    public function created(ProjectImage $image): void
    {
        // Fill basic alt_text/caption immediately (for instant SEO)
        $this->fillBasicContent($image);

        // Regenerate sitemap and notify IndexNow
        $this->regenerateSitemap();
        $this->submitToIndexNow($image);

        // Queue AI-powered content generation for richer SEO
        if (config('services.google.gemini_api_key')) {
            GenerateAiContentJob::dispatch($image, overwrite: true, regenerateSitemap: true)
                ->onQueue('ai-content')
                ->delay(now()->addSeconds(5)); // Small delay to ensure image is fully saved
        }

        // Upload new images to Google Business Profile if configured
        if (
            config('services.google.business_profile.enabled')
            && $image->project
            && $image->project->is_published
        ) {
            UploadProjectImageToGooglePlaces::dispatch($image->id)
                ->onQueue('media-sync')
                ->delay(now()->addSeconds(10));
        }
    }

    /**
     * Handle the ProjectImage "updated" event.
     * Re-queue AI content if alt_text/caption were cleared.
     */
    public function updated(ProjectImage $image): void
    {
        // Regenerate sitemap and notify IndexNow on any update
        $this->regenerateSitemap();
        $this->submitToIndexNow($image);

        // Only re-generate if content was explicitly cleared
        $wasCleared = ($image->wasChanged('alt_text') && empty($image->alt_text))
            || ($image->wasChanged('caption') && empty($image->caption));

        if ($wasCleared && config('services.google.gemini_api_key')) {
            // Fill basic content first
            $this->fillBasicContent($image);

            // Then queue AI enhancement
            GenerateAiContentJob::dispatch($image, overwrite: false, regenerateSitemap: true)
                ->onQueue('ai-content')
                ->delay(now()->addSeconds(5));
        }
    }

    /**
     * Handle the ProjectImage "deleted" event.
     * Regenerate sitemap when images are removed.
     */
    public function deleted(ProjectImage $image): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($image);
    }

    /**
     * Fill basic alt_text and caption synchronously.
     * This provides immediate SEO value before AI processing completes.
     */
    protected function fillBasicContent(ProjectImage $image): void
    {
        $project = $image->project;

        if (!$project) {
            return;
        }

        $updateData = [];

        if (empty($image->alt_text)) {
            $updateData['alt_text'] = $this->generateBasicAltText($project, $image);
        }

        if (empty($image->caption)) {
            $updateData['caption'] = $this->generateBasicCaption($project, $image);
        }

        if (!empty($updateData)) {
            $image->updateQuietly($updateData);
        }
    }

    protected function generateBasicAltText($project, $image): string
    {
        $projectType = match($project->project_type) {
            'kitchen' => 'kitchen remodel',
            'bathroom' => 'bathroom remodel',
            'basement' => 'basement remodel',
            'home-remodel' => 'home remodel',
            'mudroom' => 'mudroom remodel',
            default => 'remodeling project',
        };

        $location = $project->location ? " in {$project->location}" : '';
        $position = $image->sort_order ?? 1;

        if ($image->is_cover) {
            return "{$project->title} - {$projectType}{$location} by GS Construction";
        }

        return "{$project->title} - {$projectType} photo {$position}{$location}";
    }

    protected function generateBasicCaption($project, $image): string
    {
        $projectType = match($project->project_type) {
            'kitchen' => 'kitchen remodel',
            'bathroom' => 'bathroom remodel',
            'basement' => 'basement remodel',
            'home-remodel' => 'home remodel',
            'mudroom' => 'mudroom remodel',
            default => 'remodeling project',
        };

        $location = $project->location ? " in {$project->location}" : '';
        $position = $image->sort_order ?? 1;
        $total = $project->images()->count() ?: 1;

        if ($image->is_cover) {
            return "Featured photo from our {$projectType}{$location}. Professional craftsmanship by GS Construction.";
        }

        return "Photo {$position} of {$total} from our {$projectType}{$location}.";
    }

    protected function regenerateSitemap(): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Exception $e) {
            Log::warning('ProjectImageObserver: Failed to regenerate sitemap', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function submitToIndexNow(ProjectImage $image): void
    {
        if (! config('indexnow.auto_submit', true)) {
            return;
        }

        $project = $image->project;
        if (! $project || ! $project->is_published) {
            return;
        }

        try {
            // Submit the project page URL (images are part of the project page)
            $urls = [
                route('projects.show', $project),
                route('projects.index'),
            ];

            $this->indexNow->submitBatch($urls);
        } catch (\Exception $e) {
            Log::warning('IndexNow: Failed to submit project image URL', [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
