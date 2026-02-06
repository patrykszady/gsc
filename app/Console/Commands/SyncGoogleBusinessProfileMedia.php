<?php

namespace App\Console\Commands;

use App\Jobs\UploadProjectImageToGooglePlaces;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;

class SyncGoogleBusinessProfileMedia extends Command
{
    protected $signature = 'google-business-profile:sync
        {--upload : Upload all un-synced images to GBP}
        {--status : Show sync status overview}
        {--list : List media currently on GBP}
        {--delete-orphans : Delete GBP media items that no longer exist locally}
        {--queue : Queue uploads instead of running inline}
        {--limit= : Limit number of images to upload}
        {--project-id= : Only sync images from a specific project}
        {--dry-run : Show what would be done without making changes}';

    protected $description = 'Sync project images with Google Business Profile media.';

    public function handle(GoogleBusinessProfileService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('Google Business Profile is not fully configured.');
            $this->line('Required .env vars: GOOGLE_BUSINESS_PROFILE_ENABLED, _CLIENT_ID, _CLIENT_SECRET, _REFRESH_TOKEN, _ACCOUNT_ID, _LOCATION_ID');
            $this->newLine();
            $this->info('Run `php artisan google-business-profile:locations` to find your account & location IDs.');

            return self::FAILURE;
        }

        if ($this->option('status')) {
            return $this->showStatus($service);
        }

        if ($this->option('list')) {
            return $this->listMedia($service);
        }

        if ($this->option('delete-orphans')) {
            return $this->deleteOrphans($service);
        }

        if ($this->option('upload')) {
            return $this->uploadPending($service);
        }

        // Default: show status
        return $this->showStatus($service);
    }

    protected function showStatus(GoogleBusinessProfileService $service): int
    {
        $stats = $service->getStats();

        $this->info('Google Business Profile Sync Status');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total images', $stats['total']],
                ['Uploaded to GBP', $stats['uploaded']],
                ['Pending upload', $stats['pending']],
            ]
        );

        if ($stats['pending'] > 0) {
            $this->newLine();
            $this->line("Run <comment>php artisan google-business-profile:sync --upload</comment> to upload pending images.");
        }

        return self::SUCCESS;
    }

    protected function listMedia(GoogleBusinessProfileService $service): int
    {
        $this->info('Fetching media from Google Business Profile...');

        $items = $service->listAllMedia();

        if (empty($items)) {
            if ($error = $service->getLastError()) {
                $this->error('Failed: ' . ($error['message'] ?? 'Unknown error'));
                if (isset($error['body'])) {
                    $this->line($error['body']);
                }

                return self::FAILURE;
            }

            $this->warn('No media items found on this GBP location.');

            return self::SUCCESS;
        }

        $rows = collect($items)->map(function ($item) {
            return [
                'name' => $item['name'] ?? '',
                'format' => $item['mediaFormat'] ?? '',
                'category' => $item['locationAssociation']['category'] ?? '',
                'description' => \Illuminate\Support\Str::limit($item['description'] ?? '', 60),
                'views' => $item['insights']['viewCount'] ?? '0',
            ];
        })->all();

        $this->table(['Name', 'Format', 'Category', 'Description', 'Views'], $rows);
        $this->info('Total: ' . count($items) . ' media items on GBP.');

        return self::SUCCESS;
    }

    protected function deleteOrphans(GoogleBusinessProfileService $service): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Checking for orphaned GBP media...');

        $gbpItems = $service->listAllMedia();
        if ($gbpItems === []) {
            $this->warn('No media on GBP or failed to fetch.');

            return self::SUCCESS;
        }

        // Get all local media names that have been uploaded
        $localMediaNames = ProjectImage::whereNotNull('google_places_media_name')
            ->pluck('google_places_media_name')
            ->all();

        $orphans = collect($gbpItems)->filter(function ($item) use ($localMediaNames) {
            return ! in_array($item['name'] ?? '', $localMediaNames);
        });

        if ($orphans->isEmpty()) {
            $this->info('No orphaned media found. Everything is in sync.');

            return self::SUCCESS;
        }

        $this->warn("Found {$orphans->count()} orphaned media items on GBP.");

        if ($dryRun) {
            foreach ($orphans as $item) {
                $this->line("  Would delete: {$item['name']} — " . \Illuminate\Support\Str::limit($item['description'] ?? '(no description)', 60));
            }

            return self::SUCCESS;
        }

        if (! $this->confirm("Delete {$orphans->count()} orphaned media items from GBP?")) {
            return self::SUCCESS;
        }

        $deleted = 0;
        $bar = $this->output->createProgressBar($orphans->count());

        foreach ($orphans as $item) {
            $name = $item['name'] ?? '';
            if ($name && $service->deleteMedia($name)) {
                $deleted++;
            }
            $bar->advance();

            // Rate-limit: 200ms between deletes
            usleep(200_000);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Deleted {$deleted} orphaned media items from GBP.");

        return self::SUCCESS;
    }

    protected function uploadPending(GoogleBusinessProfileService $service): int
    {
        $dryRun = $this->option('dry-run');
        $useQueue = $this->option('queue');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $projectId = $this->option('project-id') ? (int) $this->option('project-id') : null;

        $query = ProjectImage::query()
            ->with('project')
            ->whereNull('google_places_uploaded_at')
            ->whereHas('project', fn ($q) => $q->where('is_published', true));

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $query->orderBy('project_id')->orderBy('sort_order');

        if ($limit) {
            $query->limit($limit);
        }

        $images = $query->get();

        if ($images->isEmpty()) {
            $this->info('All published project images are already uploaded to GBP.');

            return self::SUCCESS;
        }

        $this->info("Found {$images->count()} images to upload.");

        if ($dryRun) {
            foreach ($images as $img) {
                $this->line("  Would upload: [{$img->project->title}] {$img->alt_text}");
            }

            return self::SUCCESS;
        }

        if ($useQueue) {
            foreach ($images as $img) {
                UploadProjectImageToGooglePlaces::dispatch($img->id)
                    ->onQueue('media-sync')
                    ->delay(now()->addSeconds(2 * $images->search(fn ($i) => $i->id === $img->id)));
            }

            $this->info("Queued {$images->count()} upload jobs on the 'media-sync' queue.");
            $this->line('Run `php artisan queue:work --queue=media-sync` to process.');

            return self::SUCCESS;
        }

        // Inline upload
        $bar = $this->output->createProgressBar($images->count());
        $uploaded = 0;
        $failed = 0;

        foreach ($images as $image) {
            $mediaName = $service->uploadProjectImage($image);

            if ($mediaName) {
                $image->updateQuietly([
                    'google_places_media_name' => $mediaName,
                    'google_places_uploaded_at' => now(),
                ]);
                $uploaded++;
            } else {
                $error = $service->getLastError();
                $this->newLine();
                $this->warn("  Failed: image #{$image->id} — " . ($error['message'] ?? 'Unknown error'));
                $failed++;
            }

            $bar->advance();

            // Rate-limit: 500ms between uploads to be respectful
            usleep(500_000);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Upload complete: {$uploaded} succeeded, {$failed} failed.");

        return self::SUCCESS;
    }
}
