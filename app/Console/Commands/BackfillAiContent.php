<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiContentJob;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\AiContentService;
use Illuminate\Console\Command;

class BackfillAiContent extends Command
{
    protected $signature = 'content:backfill 
        {--images : Backfill image alt text and captions}
        {--projects : Backfill project descriptions}
        {--all : Backfill all content (images + projects)}
        {--overwrite : Overwrite existing content}
        {--project-id= : Process specific project ID only}
        {--limit= : Limit number of items to process}
        {--queue : Queue jobs instead of processing synchronously}
        {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Backfill SEO-optimized AI content for images and projects using Google Gemini';

    public function handle(AiContentService $service): int
    {
        $doImages = $this->option('all') || $this->option('images');
        $doProjects = $this->option('all') || $this->option('projects');

        if (!$doImages && !$doProjects) {
            $this->error('Please specify --images, --projects, or --all');
            return self::FAILURE;
        }

        if (!config('services.google.gemini_api_key')) {
            $this->error('Google Gemini API key not configured. Set GOOGLE_GEMINI_API_KEY in .env');
            return self::FAILURE;
        }

        $overwrite = $this->option('overwrite');
        $dryRun = $this->option('dry-run');
        $useQueue = $this->option('queue');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $projectId = $this->option('project-id') ? (int) $this->option('project-id') : null;

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        if ($useQueue) {
            $this->info('Jobs will be queued for background processing');
        }

        $results = ['images' => 0, 'projects' => 0, 'failed' => 0, 'queued' => 0];

        if ($doImages) {
            $this->info('Processing images...');
            $imageResults = $this->processImages($service, $overwrite, $dryRun, $useQueue, $limit, $projectId);
            $results = array_merge($results, $imageResults);
        }

        if ($doProjects) {
            $this->info('Processing projects...');
            $projectResults = $this->processProjects($service, $overwrite, $dryRun, $useQueue, $limit, $projectId);
            $results = array_merge($results, $projectResults);
        }

        $this->newLine();
        
        if ($useQueue) {
            $this->info("Done! Queued {$results['queued']} jobs for background processing.");
            $this->line('Run `php artisan queue:work` to process the jobs.');
        } else {
            $this->info("Done! Updated {$results['images']} images, {$results['projects']} projects. {$results['failed']} failures.");
        }

        // Regenerate sitemap once at the end (if not using queue)
        if (!$dryRun && !$useQueue && ($results['images'] > 0 || $results['projects'] > 0)) {
            $this->info('Regenerating sitemap...');
            $this->call('sitemap:generate');
        }

        return self::SUCCESS;
    }

    protected function processImages(
        AiContentService $service,
        bool $overwrite,
        bool $dryRun,
        bool $useQueue,
        ?int $limit,
        ?int $projectId
    ): array {
        $query = ProjectImage::query()
            ->with('project')
            ->whereHas('project', fn($q) => $q->where('is_published', true));

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if (!$overwrite) {
            $query->where(function ($q) {
                $q->whereNull('alt_text')
                    ->orWhere('alt_text', '')
                    ->orWhereNull('caption')
                    ->orWhere('caption', '');
            });
        }

        $query->orderBy('project_id')->orderBy('sort_order');

        if ($limit) {
            $query->limit($limit);
        }

        $images = $query->get();
        $total = $images->count();

        if ($total === 0) {
            $this->info('  No images to process.');
            return ['images' => 0, 'failed' => 0, 'queued' => 0];
        }

        $this->info("  Found {$total} images to process...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $failed = 0;
        $queued = 0;

        foreach ($images as $image) {
            $bar->advance();

            if ($dryRun) {
                $this->newLine();
                $this->line("    Would process: Image #{$image->id} ({$image->project->title})");
                $updated++;
                continue;
            }

            if ($useQueue) {
                // Stagger jobs 5 seconds apart to avoid rate limits
                GenerateAiContentJob::dispatch($image, $overwrite, false)
                    ->onQueue('ai-content')
                    ->delay(now()->addSeconds($queued * 5));
                $queued++;
                continue;
            }

            // Synchronous processing
            $content = $service->generateImageContent($image);

            if ($content === null) {
                $failed++;
                if ($this->output->isVerbose()) {
                    $this->newLine();
                    $this->warn("    Failed: Image #{$image->id} - " . ($service->getLastError() ?? 'Unknown error'));
                }
                continue;
            }

            $updateData = [];

            if (isset($content['alt_text']) && ($overwrite || empty($image->alt_text))) {
                $updateData['alt_text'] = $content['alt_text'];
            }

            if (isset($content['caption']) && ($overwrite || empty($image->caption))) {
                $updateData['caption'] = $content['caption'];
            }

            if (!empty($updateData)) {
                $image->updateQuietly($updateData);
                $updated++;

                if ($this->output->isVeryVerbose()) {
                    $this->newLine();
                    $this->line("    Image #{$image->id}: " . ($content['alt_text'] ?? 'no alt'));
                }
            }

            // Rate limiting - 2 seconds between requests (Gemini free tier: 15 RPM)
            sleep(2);
        }

        $bar->finish();
        $this->newLine();

        return ['images' => $updated, 'failed' => $failed, 'queued' => $queued];
    }

    protected function processProjects(
        AiContentService $service,
        bool $overwrite,
        bool $dryRun,
        bool $useQueue,
        ?int $limit,
        ?int $projectId
    ): array {
        $query = Project::query()->where('is_published', true);

        if ($projectId) {
            $query->where('id', $projectId);
        }

        if (!$overwrite) {
            $query->where(function ($q) {
                $q->whereNull('description')
                    ->orWhere('description', '');
            });
        }

        if ($limit) {
            $query->limit($limit);
        }

        $projects = $query->get();
        $total = $projects->count();

        if ($total === 0) {
            $this->info('  No projects to process.');
            return ['projects' => 0, 'failed' => 0, 'queued' => 0];
        }

        $this->info("  Found {$total} projects to process...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $failed = 0;
        $queued = 0;

        foreach ($projects as $project) {
            $bar->advance();

            if ($dryRun) {
                $this->newLine();
                $this->line("    Would process: Project #{$project->id} ({$project->title})");
                $updated++;
                continue;
            }

            if ($useQueue) {
                // Stagger jobs 5 seconds apart to avoid rate limits
                GenerateAiContentJob::dispatch($project, $overwrite, false)
                    ->onQueue('ai-content')
                    ->delay(now()->addSeconds($queued * 5));
                $queued++;
                continue;
            }

            // Synchronous processing
            $description = $service->generateProjectDescription($project);

            if ($description === null) {
                $failed++;
                if ($this->output->isVerbose()) {
                    $this->newLine();
                    $this->warn("    Failed: Project #{$project->id} - " . ($service->getLastError() ?? 'Unknown error'));
                }
                continue;
            }

            $project->updateQuietly(['description' => $description]);
            $updated++;

            if ($this->output->isVeryVerbose()) {
                $this->newLine();
                $this->line("    Project #{$project->id}: " . substr($description, 0, 80) . '...');
            }

            // Rate limiting - 2 seconds between requests (Gemini free tier: 15 RPM)
            sleep(2);
        }

        $bar->finish();
        $this->newLine();

        return ['projects' => $updated, 'failed' => $failed, 'queued' => $queued];
    }
}
