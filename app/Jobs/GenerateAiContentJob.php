<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\AiContentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class GenerateAiContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 120, 300, 600]; // Aggressive backoff for Gemini rate limits (1, 2, 5, 10 min)

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ProjectImage|Project $model,
        public bool $overwrite = false,
        public bool $regenerateSitemap = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AiContentService $service): void
    {
        if ($this->model instanceof ProjectImage) {
            $this->processImage($service);
        } elseif ($this->model instanceof Project) {
            $this->processProject($service);
        }
    }

    protected function processImage(AiContentService $service): void
    {
        $image = $this->model;
        $shouldRegenerateSitemap = false;

        // Skip if already has content and not overwriting
        $rawSeoAltText = $image->getRawOriginal('seo_alt_text');
        $skipContent = !$this->overwrite
            && !empty($image->alt_text)
            && !empty($image->caption)
            && !empty($rawSeoAltText);
        if ($skipContent) {
            Log::debug('GenerateAiContentJob: Skipping image content, already has alt_text, caption, and seo_alt_text', [
                'image_id' => $image->id,
            ]);
        }

        // Check if image file exists before trying to process
        $disk = config('app.images_disk', 'public');
        if (!\Illuminate\Support\Facades\Storage::disk($disk)->exists($image->path)) {
            Log::warning('GenerateAiContentJob: Image file not found, skipping', [
                'image_id' => $image->id,
                'path' => $image->path,
            ]);
            $skipContent = true;
            // Still allow slug generation below even if file is missing
        }

        if (!$skipContent) {
            $content = $service->generateImageContent($image);

            if ($content === null) {
                Log::warning('GenerateAiContentJob: Failed to generate image content', [
                    'image_id' => $image->id,
                    'error' => $service->getLastError(),
                ]);
            } else {
                $updateData = [];

                if (isset($content['alt_text']) && ($this->overwrite || empty($image->alt_text))) {
                    $updateData['alt_text'] = $content['alt_text'];
                }

                if (isset($content['seo_alt_text']) && ($this->overwrite || empty($rawSeoAltText))) {
                    $updateData['seo_alt_text'] = $content['seo_alt_text'];
                }

                if (isset($content['caption']) && ($this->overwrite || empty($image->caption))) {
                    $updateData['caption'] = $content['caption'];
                }


                if (!empty($updateData)) {
                    $image->updateQuietly($updateData);
                    $shouldRegenerateSitemap = true;
                }
            }
        }

        if (empty($image->slug)) {
            $image->slug = $image->generateSlug();
            $image->saveQuietly();
            $shouldRegenerateSitemap = true;
        }

        if ($this->regenerateSitemap && $shouldRegenerateSitemap) {
            $this->regenerateSitemap();
        }

        // Check if all project images now have AI content; if so, generate project description
        $this->maybeGenerateProjectDescription($image);
    }

    /**
     * After an image is processed, check if all project images have AI content.
     * If so, dispatch (or run inline) the project description generation.
     */
    protected function maybeGenerateProjectDescription(ProjectImage $image): void
    {
        $project = $image->project;
        if (!$project) {
            return;
        }

        // Check if all images have AI-generated content
        $totalImages = $project->images()->count();
        $completedImages = $project->images()
            ->whereNotNull('seo_alt_text')
            ->where('seo_alt_text', '!=', '')
            ->count();

        if ($completedImages < $totalImages) {
            Log::debug('GenerateAiContentJob: Waiting for remaining images', [
                'project_id' => $project->id,
                'completed' => $completedImages,
                'total' => $totalImages,
            ]);
            return;
        }

        Log::info('GenerateAiContentJob: All images processed, generating project description', [
            'project_id' => $project->id,
            'image_count' => $totalImages,
        ]);

        // Always overwrite — image AI content may have improved since last description
        static::dispatch($project, overwrite: true, regenerateSitemap: true)
            ->onQueue('ai-content')
            ->delay(now()->addSeconds(5));
    }

    protected function processProject(AiContentService $service): void
    {
        $project = $this->model;

        // Skip if already has description and not overwriting
        if (!$this->overwrite && !empty($project->description)) {
            Log::debug('GenerateAiContentJob: Skipping project, already has description', [
                'project_id' => $project->id,
            ]);
            return;
        }

        $description = $service->generateProjectDescription($project);

        if ($description === null) {
            Log::warning('GenerateAiContentJob: Failed to generate project description', [
                'project_id' => $project->id,
                'error' => $service->getLastError(),
            ]);
            return;
        }

        $project->updateQuietly(['description' => $description]);

        if ($this->regenerateSitemap) {
            $this->regenerateSitemap();
        }
    }

    protected function regenerateSitemap(): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Exception $e) {
            Log::warning('GenerateAiContentJob: Failed to regenerate sitemap', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
