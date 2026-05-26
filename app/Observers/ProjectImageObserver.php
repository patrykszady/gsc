<?php

namespace App\Observers;

use App\Jobs\DeleteGooglePlacesMedia;
use App\Jobs\GenerateAiContentJob;
use App\Jobs\UploadProjectImageToGooglePlaces;
use App\Jobs\UploadProjectImageToYelp;
use App\Jobs\UploadProjectImageToYelpBusinessPhotos;
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

        // Upload new images to Yelp Portfolio Project if configured.
        if (
            app(\App\Services\YelpBusinessService::class)->isConfigured()
            && $image->project
            && $image->project->is_published
            && ! empty($image->project->yelp_portfolio_url)
        ) {
            // No delay — the media-sync supervisor runs one worker so jobs
            // naturally FIFO. Pending uploads stay in the queue LIST where
            // they can be wiped with a single redis DEL if needed.
            UploadProjectImageToYelp::dispatch($image->id)
                ->onQueue('media-sync');
        }

        // Upload new images to the account-wide Yelp Business Photos gallery
        // if Yelp is configured (no per-project portfolio URL required).
        // No artificial delay — the WithoutOverlapping('yelp-portfolio-upload')
        // lock on the job already serializes execution, so multiple images
        // uploaded in the same admin session queue up FIFO and run one-at-a-time.
        if (
            app(\App\Services\YelpBusinessService::class)->isConfigured()
            && $image->project
            && $image->project->is_published
        ) {
            UploadProjectImageToYelpBusinessPhotos::dispatch($image->id)
                ->onQueue('media-sync');
        }
    }

    /**
     * Handle the ProjectImage "updated" event.
     * Re-queue AI content if alt_text/caption were cleared.
     */
    public function updated(ProjectImage $image): void
    {
        // Skip sitemap regen + IndexNow when the only changed columns are
        // internal sync-tracking metadata (GBP/Yelp upload IDs + timestamps).
        // Those updates don't change anything user-visible, so pinging
        // IndexNow on every Yelp/GBP photo sync is pure noise.
        $syncOnlyFields = [
            'google_places_media_name',
            'google_places_uploaded_at',
            'yelp_photo_id',
            'yelp_uploaded_at',
            'yelp_biz_photo_id',
            'yelp_biz_uploaded_at',
            'yelp_biz_photos_url',
            'updated_at',
        ];
        $changed = array_keys($image->getChanges());
        $isSyncOnlyUpdate = ! empty($changed) && empty(array_diff($changed, $syncOnlyFields));

        if (! $isSyncOnlyUpdate) {
            // Regenerate sitemap and notify IndexNow on user-visible updates
            $this->regenerateSitemap();
            $this->submitToIndexNow($image);
        }

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

        // If image AI content changed, regenerate project description to incorporate new details
        if ($image->wasChanged(['seo_alt_text', 'caption']) && config('services.google.gemini_api_key')) {
            $this->queueProjectDescriptionRegeneration($image);
        }

        // Keep GBP media description in sync when image text metadata changes.
        if (
            config('services.google.business_profile.enabled')
            && $image->project
            && $image->project->is_published
            && $image->wasChanged(['caption', 'seo_alt_text', 'alt_text'])
        ) {
            UploadProjectImageToGooglePlaces::dispatch($image->id, forceRefresh: true)
                ->onQueue('media-sync')
                ->delay(now()->addSeconds(10));
        }

        // Yelp Portfolio: only upload once per image. Re-upload not supported via
        // this scripted flow (would create duplicate photos on Yelp). Manual delete
        // + force re-sync via `php artisan yelp:sync-portfolio-media --force` if needed.
    }

    /**
     * Handle the ProjectImage "deleted" event.
     * Regenerate sitemap and project description when images are removed.
     * Delete from Google Business Profile if it was uploaded.
     */
    public function deleted(ProjectImage $image): void
    {
        $this->regenerateSitemap();
        $this->submitToIndexNow($image);

        // Regenerate project description since image set changed
        if (config('services.google.gemini_api_key')) {
            $this->queueProjectDescriptionRegeneration($image);
        }

        // Delete from Google Business Profile if it was uploaded there
        if ($image->google_places_media_name && config('services.google.business_profile.enabled')) {
            DeleteGooglePlacesMedia::dispatch($image->google_places_media_name)
                ->onQueue('media-sync')
                ->delay(now()->addSeconds(5));
        }
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

    /**
     * Queue project description regeneration after image changes.
     * Waits briefly to allow any batch of image updates to settle.
     */
    protected function queueProjectDescriptionRegeneration(ProjectImage $image): void
    {
        $project = $image->project;
        if (!$project) {
            return;
        }

        GenerateAiContentJob::dispatch($project, overwrite: true, regenerateSitemap: true)
            ->onQueue('ai-content')
            ->delay(now()->addSeconds(30)); // 30s delay to let image batch finish
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

            \App\Jobs\SubmitUrlsToIndexNow::dispatch($urls)->onQueue('default')->delay(now()->addSeconds(15));
        } catch (\Exception $e) {
            Log::warning('IndexNow: Failed to submit project image URL', [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
