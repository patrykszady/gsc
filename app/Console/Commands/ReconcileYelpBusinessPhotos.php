<?php

namespace App\Console\Commands;

use App\Models\ProjectImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile Yelp business-photos uploads against the script log.
 *
 * Background: when the Puppeteer script throws AFTER the upload commit
 * (e.g. a "detached Frame" during the post-commit gallery count), the
 * process exits with code 1 and PHP never writes `yelp_biz_uploaded_at`.
 * The image is live on Yelp but the DB thinks it failed, so the queue
 * re-dispatches it → duplicate uploads.
 *
 * This command scans storage/logs/yelp-upload.log for "[yelp] captured
 * real photo_id=..." lines, pairs them with the most recent "===== ...
 * image_id=N =====" banner, and backfills any ProjectImage row whose
 * yelp_biz_uploaded_at is NULL. Also clears the "queued" cache marker
 * so the watcher exits cleanly.
 *
 * Safe to run anytime; idempotent.
 */
class ReconcileYelpBusinessPhotos extends Command
{
    protected $signature = 'yelp:reconcile-business-photos
        {--log= : Path to yelp-upload.log (defaults to storage/logs/yelp-upload.log)}
        {--dry-run : Show what would be backfilled without writing}
        {--clear-queue-markers : Also clear yelp_biz_upload_queued:* cache entries for reconciled images}';

    protected $description = 'Backfill yelp_biz_uploaded_at for images that uploaded to Yelp but were never marked done (script crashed post-commit).';

    public function handle(): int
    {
        $logPath = (string) ($this->option('log') ?: storage_path('logs/yelp-upload.log'));

        if (! is_file($logPath)) {
            $this->error("Log file not found: {$logPath}");
            return self::FAILURE;
        }

        $this->info("Scanning {$logPath}...");

        // Walk the log linearly, tracking the most recent banner so we can
        // attribute each captured photo_id to the right image_id. We keep
        // the LAST successful photo_id per image_id (re-uploads override).
        $pairs = $this->extractPairs($logPath);

        if (empty($pairs)) {
            $this->info('No (image_id, photo_id) pairs found in log.');
            return self::SUCCESS;
        }

        $this->line(sprintf('Found %d image(s) with captured photo_id(s) in log.', count($pairs)));

        // Fetch existing rows in bulk.
        $rows = ProjectImage::query()
            ->whereIn('id', array_keys($pairs))
            ->get(['id', 'yelp_biz_photo_id', 'yelp_biz_uploaded_at'])
            ->keyBy('id');

        $toBackfill = [];
        $alreadyDone = 0;
        $missingRow = 0;

        foreach ($pairs as $imageId => $photoId) {
            $row = $rows->get($imageId);
            if (! $row) {
                $missingRow++;
                continue;
            }
            if ($row->yelp_biz_uploaded_at) {
                $alreadyDone++;
                continue;
            }
            $toBackfill[$imageId] = $photoId;
        }

        $this->line(sprintf(
            '  already marked done: %d  |  missing project_image row: %d  |  to backfill: %d',
            $alreadyDone,
            $missingRow,
            count($toBackfill),
        ));

        if (empty($toBackfill)) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('--dry-run: would backfill these rows:');
            $this->table(
                ['image_id', 'photo_id'],
                array_map(
                    fn ($id, $pid) => [$id, $pid],
                    array_keys($toBackfill),
                    array_values($toBackfill),
                ),
            );
            return self::SUCCESS;
        }

        $now = now();
        $clearMarkers = (bool) $this->option('clear-queue-markers');
        $written = 0;

        DB::transaction(function () use ($toBackfill, $now, $clearMarkers, &$written): void {
            foreach ($toBackfill as $imageId => $photoId) {
                ProjectImage::where('id', $imageId)
                    ->whereNull('yelp_biz_uploaded_at')
                    ->update([
                        'yelp_biz_photo_id' => $photoId,
                        'yelp_biz_uploaded_at' => $now,
                    ]);
                $written++;

                if ($clearMarkers) {
                    Cache::forget('yelp_biz_upload_queued:' . $imageId);
                }
            }
        });

        $this->info(sprintf('Reconciled %d image(s).', $written));
        if ($clearMarkers) {
            $this->line('Cleared yelp_biz_upload_queued:* cache markers.');
        } else {
            $this->line('Tip: re-run with --clear-queue-markers to also free the "already queued" cache locks.');
        }

        return self::SUCCESS;
    }

    /**
     * Walk the log and return an [imageId => latestCapturedPhotoId] map.
     *
     * Log format we rely on:
     *   ===== 2026-05-28T02:09:22+00:00 image_id=71 =====
     *   ...
     *   [yelp] captured real photo_id=juYV2mI6OtPOqdtSUh0q-g from ...
     *
     * @return array<int, string>
     */
    protected function extractPairs(string $logPath): array
    {
        $pairs = [];
        $currentImageId = null;

        // Stream line-by-line so this works on multi-MB logs without
        // loading the whole file into memory.
        $fh = @fopen($logPath, 'rb');
        if (! $fh) {
            return $pairs;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                if (preg_match('/^=====\s+\S+\s+image_id=(\d+)\s+=====\s*$/', $line, $m)) {
                    $currentImageId = (int) $m[1];
                    continue;
                }
                if ($currentImageId === null) {
                    continue;
                }
                if (preg_match('/\[yelp\]\s+captured real photo_id=([A-Za-z0-9_-]{8,})/', $line, $m)) {
                    // Latest capture wins (handles re-uploads cleanly).
                    $pairs[$currentImageId] = $m[1];
                }
            }
        } finally {
            fclose($fh);
        }

        return $pairs;
    }
}
