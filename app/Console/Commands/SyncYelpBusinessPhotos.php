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
        {--show-process : Stream Yelp uploader process output (sync mode only)}
        {--watch : After dispatching, poll the DB and show a live progress bar until all queued uploads complete or timeout elapses (queue mode only)}
        {--watch-timeout=21600 : Max seconds to watch before bailing out (default 6h)}';

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

        // Visibility: show how many we're skipping vs queueing so the
        // operator can confirm we never re-upload already-synced images
        // and that previously-failed images ARE being retried.
        if (! $this->option('force')) {
            $totalPublished = ProjectImage::query()
                ->whereHas('project', function ($q) {
                    $q->where('is_published', true);
                    if ($projectId = $this->option('project')) {
                        $q->where('id', $projectId);
                    }
                })
                ->count();
            $alreadyUploaded = ProjectImage::query()
                ->whereHas('project', function ($q) {
                    $q->where('is_published', true);
                    if ($projectId = $this->option('project')) {
                        $q->where('id', $projectId);
                    }
                })
                ->whereNotNull('yelp_biz_uploaded_at')
                ->count();
            $pendingOrFailed = max(0, $totalPublished - $alreadyUploaded);
            $this->line(sprintf(
                'Eligible: %d published image(s); %d already uploaded (skipped), %d pending or previously failed (will be processed).',
                $totalPublished,
                $alreadyUploaded,
                $pendingOrFailed,
            ));
        } else {
            $this->warn('--force enabled: ALL eligible images will be re-uploaded, including already-synced ones.');
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = 0;
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $watch = (bool) $this->option('watch');
        $showProcess = (bool) $this->option('show-process');

        $minInterval = max(0, (int) config('services.yelp.business.min_interval_seconds', 0));
        if (! $sync) {
            if ($minInterval > 0) {
                $this->line(sprintf(
                    'Throttle: 1 upload every %d seconds (~%s).',
                    $minInterval,
                    $this->humanInterval($minInterval),
                ));
            } else {
                $this->line('Mode: serial (next upload starts as soon as the previous Chromium exits).');
            }
        }

        $dispatchedIds = [];

        $query->orderBy('id')->each(function (ProjectImage $image) use (&$count, &$dispatchedIds, $force, $sync, $showProcess, $service) {
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
                $cacheKey = 'yelp_biz_upload_queued:' . $image->id;
                if (! $force && Cache::has($cacheKey)) {
                    $this->line("  - skip image #{$image->id} (already queued)");
                    return;
                }
                Cache::put($cacheKey, true, now()->addHours(12));

                UploadProjectImageToYelpBusinessPhotos::dispatch($image->id, $force)
                    ->onQueue('media-sync');
                $this->line("  - queued image #{$image->id}");
                $dispatchedIds[] = $image->id;
            }
            $count++;
        });

        $this->info("Dispatched {$count} Yelp business-photos upload job(s).");

        if (! $sync && $watch && ! empty($dispatchedIds)) {
            $this->watchProgress($dispatchedIds, $minInterval, (int) $this->option('watch-timeout'));
        }

        return self::SUCCESS;
    }

    /**
     * Poll the DB until every dispatched image is marked uploaded (or has
     * exceeded retries / been removed). Shows a live progress bar with
     * ETA based on the configured min_interval_seconds.
     *
     * @param  array<int, int>  $imageIds
     */
    protected function watchProgress(array $imageIds, int $minInterval, int $maxSeconds): void
    {
        $total = count($imageIds);
        $eta = $minInterval > 0 ? $minInterval * $total : 0;
        if ($eta > 0) {
            $this->line(sprintf(
                'Watching %d upload(s); estimated total time: ~%s',
                $total,
                $this->humanInterval($eta),
            ));
        } else {
            $this->line(sprintf('Watching %d upload(s); they will run back-to-back.', $total));
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  done=%done% pending=%pending% failed=%failed%  elapsed=%elapsed%  status=%status%');
        $bar->setMessage('0', 'done');
        $bar->setMessage((string) $total, 'pending');
        $bar->setMessage('0', 'failed');
        $bar->setMessage('starting', 'status');
        $bar->start();

        $start = time();
        $lastLogged = null;

        while (true) {
            $rows = ProjectImage::query()
                ->whereIn('id', $imageIds)
                ->get(['id', 'yelp_biz_uploaded_at']);

            $done = $rows->whereNotNull('yelp_biz_uploaded_at')->count();

            // Detect failed (no longer in queue, still not uploaded, no
            // "queued" cache marker held). These will never become done
            // without manual re-dispatch, so we must include them in the
            // exit condition or the watch loop spins forever.
            $failed = 0;
            foreach ($rows as $row) {
                if ($row->yelp_biz_uploaded_at) continue;
                if (! Cache::has('yelp_biz_upload_queued:' . $row->id)) {
                    $failed++;
                }
            }

            $pending = max(0, $total - $done - $failed);

            $bar->setMessage((string) $done, 'done');
            $bar->setMessage((string) $pending, 'pending');
            $bar->setMessage((string) $failed, 'failed');

            $lastRunAt = (int) Cache::get('yelp:browser-automation:last-run-at', 0);
            $lockHeld = Cache::has('yelp:browser-automation:lock');
            if ($lockHeld) {
                $statusMsg = 'uploading';
            } elseif ($minInterval > 0 && $lastRunAt > 0 && (time() - $lastRunAt) < $minInterval) {
                $statusMsg = sprintf('next in %ds', max(0, $minInterval - (time() - $lastRunAt)));
            } elseif ($pending > 0) {
                $statusMsg = 'starting next';
            } else {
                $statusMsg = 'idle';
            }
            $bar->setMessage($statusMsg, 'status');

            // Re-set position so the bar visibly advances.
            $bar->setProgress($done);

            // Periodic info line below the bar (every 5 min) for log capture.
            $elapsed = time() - $start;
            if ($lastLogged === null || $elapsed - $lastLogged >= 300) {
                $lastLogged = $elapsed;
            }

            if ($done + $failed >= $total) {
                $bar->setProgress($done);
                $bar->finish();
                $this->newLine(2);
                if ($failed > 0) {
                    $this->warn(sprintf(
                        '%d upload(s) completed, %d failed (see failed_jobs / laravel.log).',
                        $done,
                        $failed,
                    ));
                } else {
                    $this->info("All {$total} upload(s) completed.");
                }
                return;
            }

            if ($elapsed >= $maxSeconds) {
                $bar->finish();
                $this->newLine(2);
                $this->warn(sprintf(
                    'Watch timeout reached (%s). Remaining: %d pending, %d failed.',
                    $this->humanInterval($maxSeconds),
                    $pending,
                    $failed,
                ));
                return;
            }

            // Poll cadence: every 10s.
            sleep(10);
        }
    }

    protected function humanInterval(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return $h . 'h ' . $m . 'm';
    }
}
