<?php

namespace App\Console\Commands;

use App\Jobs\UploadProjectImageToYelpBusinessPhotos;
use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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
                    $this->info("    uploaded image #{$image->id} (photo_id={$result['photo_id']})");
                } else {
                    $this->error("    failed image #{$image->id} (see logs for details)");
                }
            } else {
                // Skip if this image is already pending from a previous run
                // (each upload takes ~3-4 min).
                $cacheKey = 'yelp_biz_upload_queued:' . $image->id;
                if (! $force && Cache::has($cacheKey)) {
                    $this->line("  - skip image #{$image->id} (already queued)");
                    return;
                }
                // Mark as queued for up to 2 hours; the job will forget it.
                Cache::put($cacheKey, true, now()->addHours(2));

                // Dispatch directly to the media-sync queue. That supervisor
                // runs ONE worker, so jobs are naturally processed FIFO —
                // each upload starts after the previous one completes.
                // Pending jobs sit in the plain queues:media-sync LIST so
                // they can be cancelled instantly with `redis-cli DEL`.
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
