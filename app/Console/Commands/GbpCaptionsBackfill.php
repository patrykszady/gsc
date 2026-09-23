<?php

namespace App\Console\Commands;

use App\Jobs\UploadProjectImageToGooglePlaces;
use App\Models\ProjectImage;
use App\Services\AiContentService;
use Illuminate\Console\Command;

/**
 * Fill ProjectImage::gbp_caption for published projects' images
 * (AiContentService::generateGbpCaption(), the shared
 * SsSystems\Platform\Media\GooglePhotoCaptionPrompt, 2026-09-22) — the same
 * generation GenerateAiContentJob now runs per-image, for images that
 * already existed before this field did (or whose caption should be
 * redone with --force). Tenant-aware the way every other command here is:
 * it queries ProjectImage under whatever site is current (Site::current()),
 * so `tenants:run images:gbp-captions` fills it for every tenant in turn.
 */
class GbpCaptionsBackfill extends Command
{
    protected $signature = 'images:gbp-captions
        {--force : Regenerate gbp_caption even where one already exists}
        {--project= : Only this project ID}';

    protected $description = "Fill published projects' images with a Google Business Profile photo caption";

    public function handle(AiContentService $service): int
    {
        if (empty(config('services.google.gemini_api_key'))) {
            $this->error('Google Gemini API key not configured (GOOGLE_GEMINI_API_KEY).');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $projectId = $this->option('project') ? (int) $this->option('project') : null;

        $query = ProjectImage::query()
            ->with('project')
            ->whereHas('project', function ($q) use ($projectId) {
                $q->where('is_published', true);
                if ($projectId) {
                    $q->where('id', $projectId);
                }
            });

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('gbp_caption')->orWhere('gbp_caption', '');
            });
        }

        $images = $query->orderBy('project_id')->orderBy('sort_order')->get();
        $total = $images->count();

        if ($total === 0) {
            $this->info('No images need a gbp_caption.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} image(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $failed = 0;

        foreach ($images as $image) {
            $bar->advance();

            $caption = $service->generateGbpCaption($image);

            if ($caption === null) {
                $failed++;
                if ($this->output->isVerbose()) {
                    $this->newLine();
                    $this->warn("    Failed: Image #{$image->id} - ".($service->getLastError() ?? 'Unknown error'));
                }

                continue;
            }

            $image->updateQuietly(['gbp_caption' => $caption]);
            $updated++;

            if (
                app(\App\Services\GoogleBusinessProfileService::class)->isConnected()
                && \App\Support\GbpPhotoOwnership::ownedHere()
                && $image->project
                && $image->project->is_published
                && $image->google_places_uploaded_at
            ) {
                UploadProjectImageToGooglePlaces::dispatch($image->id, true)
                    ->onQueue('media-sync')
                    ->delay(now()->addSeconds(10));
            }

            if ($this->output->isVeryVerbose()) {
                $this->newLine();
                $this->line("    Image #{$image->id}: {$caption}");
            }

            // Same Gemini free-tier rate limiting content:backfill uses.
            sleep(2);
        }

        $bar->finish();
        $this->newLine();

        $this->info("Done! Updated {$updated} image(s). {$failed} failure(s).");

        return self::SUCCESS;
    }
}
