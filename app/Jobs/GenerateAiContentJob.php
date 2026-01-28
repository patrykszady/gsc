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
    public array $backoff = [30, 60, 120, 300]; // Exponential backoff for rate limits

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

        // Skip if already has content and not overwriting
        if (!$this->overwrite && !empty($image->alt_text) && !empty($image->caption)) {
            Log::debug('GenerateAiContentJob: Skipping image, already has content', [
                'image_id' => $image->id,
            ]);
            return;
        }

        $content = $service->generateImageContent($image);

        if ($content === null) {
            Log::warning('GenerateAiContentJob: Failed to generate image content', [
                'image_id' => $image->id,
                'error' => $service->getLastError(),
            ]);
            return;
        }

        $updateData = [];

        if (isset($content['alt_text']) && ($this->overwrite || empty($image->alt_text))) {
            $updateData['alt_text'] = $content['alt_text'];
        }

        if (isset($content['caption']) && ($this->overwrite || empty($image->caption))) {
            $updateData['caption'] = $content['caption'];
        }

        if (!empty($updateData)) {
            $image->updateQuietly($updateData);

            Log::info('GenerateAiContentJob: Updated image content', [
                'image_id' => $image->id,
                'fields' => array_keys($updateData),
            ]);

            if ($this->regenerateSitemap) {
                $this->regenerateSitemap();
            }
        }
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

        Log::info('GenerateAiContentJob: Updated project description', [
            'project_id' => $project->id,
        ]);

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
