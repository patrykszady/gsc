<?php

namespace App\Console\Commands;

use App\Jobs\UploadProjectImageToYelpBusinessPhotos;
use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Console\Command;

class SyncYelpBusinessPhotos extends Command
{
    protected $signature = 'yelp:sync-business-photos
        {--project= : Limit to a single project ID}
        {--force : Re-upload images even if already synced}
        {--limit=0 : Cap number of images dispatched (0 = unlimited)}
        {--sync : Run uploads synchronously in this process (default: queue)}
        {--show-process : Stream Yelp uploader process output (sync mode only)}';

    protected $description = 'Dispatch upload jobs for project images to the account-wide Yelp Business Photos gallery.';

    public function handle(YelpBusinessService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('Yelp business uploader is not configured. Set Yelp email and password in /admin/platforms.');
            return self::FAILURE;
        }

        $query = ProjectImage::query()
            ->whereHas('project', function ($q) {
                $q->where('is_published', true);
                if ($projectId = $this->option('project')) {
                    $q->where('id', $projectId);
                }
            });

        if (! $this->option('force')) {
            $query->whereNull('yelp_biz_uploaded_at');
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = 0;
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');

        $showProcess = (bool) $this->option('show-process');

        $query->orderBy('id')->each(function (ProjectImage $image) use (&$count, $force, $sync, $showProcess, $service) {
            if ($sync) {
                $this->line("  - processing image #{$image->id}");

                $onProgress = null;
                if ($showProcess) {
                    $onProgress = function (string $type, string $line): void {
                        $prefix = $type === 'err' ? '    [yelp] ' : '    [yelp:out] ';
                        $this->line($prefix . $line);
                    };
                }

                $result = $service->uploadProjectImageToBusinessPhotos($image, $onProgress);
                if ($result) {
                    $image->update([
                        'yelp_biz_photo_id' => $result['photo_id'],
                        'yelp_biz_uploaded_at' => now(),
                        'yelp_biz_photos_url' => $result['photos_url'] ?? $image->yelp_biz_photos_url,
                    ]);
                    $this->info("    uploaded image #{$image->id} (photo_id={$result['photo_id']})");
                } else {
                    $this->error("    failed image #{$image->id} (see logs for details)");
                }
            } else {
                UploadProjectImageToYelpBusinessPhotos::dispatch($image->id, $force)
                    ->onQueue('media-sync');
                $this->line("  - queued image #{$image->id}");
            }
            $count++;
        });

        $this->info("Dispatched {$count} Yelp business-photos upload job(s).");
        return self::SUCCESS;
    }
}
