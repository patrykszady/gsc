<?php

namespace App\Console\Commands;

use App\Jobs\UploadProjectImageToYelpBusinessPhotos;
use App\Models\ProjectImage;
use App\Services\YelpBusinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SyncYelpBusinessPhotos extends Command
{
    protected $signature = 'yelp:sync-business-photos
        {--project= : Limit to a single project ID}
        {--force : Re-upload images even if already synced}
        {--limit=0 : Cap number of images dispatched (0 = unlimited)}
        {--sync : Run uploads synchronously in this process (default: queue)}
        {--show-process : Stream Yelp uploader process output (sync mode only)}
        {--watch : After dispatching, poll the DB and show a live progress bar until all queued uploads complete or timeout elapses (queue mode only)}
        {--watch-timeout=21600 : Max seconds to watch before bailing out (default 6h)}
        {--purge-delayed : Before dispatching, drop any leftover delayed/queued media-sync jobs from a previous run so the worker picks up fresh dispatches immediately}';

    protected $description = 'Dispatch upload jobs for project images to the account-wide Yelp Business Photos gallery.';

    protected function checkDelayedQueue(bool $purge): void
    {
        try {
            $prefix = (string) config('database.redis.options.prefix', '');
            $base = $prefix . 'queues:media-sync';
            $delayedKey = $base . ':delayed';
            $reservedKey = $base . ':reserved';
            $delayed = (int) Redis::zcard($delayedKey);
            $reserved = (int) Redis::zcard($reservedKey);
            $pending = (int) Redis::llen($base);

            if ($delayed === 0 && $reserved === 0 && $pending === 0) {
                return;
            }

            if ($purge) {
                Redis::del($delayedKey);
                Redis::del($reservedKey);
                Redis::del($base);
                $this->warn(sprintf(
                    'Purged stale media-sync queue: %d delayed, %d reserved, %d pending.',
                    $delayed, $reserved, $pending,
                ));
                return;
            }

            $this->warn(sprintf(
                'media-sync queue is not empty: %d delayed, %d reserved, %d pending. These will run BEFORE the new dispatches and may make the worker look stuck.',
                $delayed, $reserved, $pending,
            ));
            $this->line('  -> rerun with --purge-delayed to drop them, or `redis-cli DEL ' . $delayedKey . '` manually.');
        } catch (\Throwable $e) {
            $this->line('  (could not inspect media-sync queue: ' . $e->getMessage() . ')');
        }
    }

    public function handle(YelpBusinessService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('Yelp business uploader is not configured. Set Yelp email and password in /admin/platforms.');
            return self::FAILURE;
        }

        // Surface (and optionally purge) leftover jobs from a previous run.
        // With media-sync's maxProcesses=1 supervisor, even a handful of
        // stale Delayed jobs can starve fresh dispatches: each pops, fails
        // fast on the in-flight guard, releases for 90s+, repeat. Operator
        // sees "waiting Ns for worker" while the worker is actually busy
        // chewing through zombies.
        $this->checkDelayedQueue((bool) $this->option('purge-delayed'));

        $query = ProjectImage::query()
            ->whereHas('project', function ($q) {
                $q->where('is_published', true);
                if ($projectId = $this->option('project')) {
                    $q->where('id', $projectId);
                }
            });

        if (! $this->option('force')) {
            $query->notUploadedTo('yelp_biz');
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
                ->uploadedTo('yelp_biz')
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
        // NOTE: do NOT apply ->limit($limit) at the query level. The dispatch
        // loop may skip rows whose cache marker is still set (already queued)
        // and we want the SQL limit to count only ACTUALLY dispatched images,
        // not "considered" ones. We stop manually after $limit dispatches.

        $count = 0;
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $watch = (bool) $this->option('watch');
        $showProcess = (bool) $this->option('show-process');

        $minInterval = max(0, (int) config('services.yelp.business.min_interval_seconds', 0));
        // Empirical per-image budget: ~50s upload work + min_interval buffer.
        $perImageSeconds = 50 + $minInterval;
        if (! $sync) {
            $this->line(sprintf(
                'Throttle: %ds buffer between successful uploads. Typical upload ~50s, so plan on ~%ds per image.',
                $minInterval,
                $perImageSeconds,
            ));
        }

        $dispatchedIds = [];

        // Process newest pending first ("the next N that need to be uploaded"),
        // and stop only when we've dispatched $limit images (not merely
        // considered $limit rows). Skipped rows do not consume the budget.
        $query->orderByDesc('id')->each(function (ProjectImage $image) use (&$count, &$dispatchedIds, $force, $sync, $showProcess, $service, $limit) {
            if ($limit > 0 && $count >= $limit) {
                return false; // stop the each() iteration
            }

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
            $this->watchProgress($dispatchedIds, $perImageSeconds, $minInterval, (int) $this->option('watch-timeout'));
        }

        return self::SUCCESS;
    }

    /**
     * Poll the DB until every dispatched image is marked uploaded (or has
     * exceeded retries / been removed). Shows a live progress bar with
     * ETA based on the empirical per-image upload budget (~120s + buffer).
     *
     * @param  array<int, int>  $imageIds
     */
    protected function watchProgress(array $imageIds, int $perImageSeconds, int $minInterval, int $maxSeconds): void
    {
        $total = count($imageIds);
        $eta = $perImageSeconds * $total;
        $this->line(sprintf(
            'Watching %d upload(s); estimated total time: ~%s (assumes ~%ds per image).',
            $total,
            $this->humanInterval($eta),
            $perImageSeconds,
        ));

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
            // Force a fresh DB connection each poll so we always see the
            // latest committed rows. Without this, the long-running artisan
            // process can sit on a stale REPEATABLE READ snapshot and never
            // observe the worker's UPDATE to yelp_biz_uploaded_at, leaving
            // the bar stuck at 0/N even after the upload succeeded.
            DB::connection()->disconnect();

            $rows = ProjectImage::query()
                ->whereIn('id', $imageIds)
                ->with(['platformUploads' => fn ($q) => $q->where('platform', 'yelp_biz')])
                ->get(['id']);

            $done = $rows->filter(fn ($r) => $r->platformUploads->isNotEmpty())->count();

            // Detect failed (no longer in queue, still not uploaded, no
            // "queued" cache marker held). These will never become done
            // without manual re-dispatch, so we must include them in the
            // exit condition or the watch loop spins forever.
            $failed = 0;
            foreach ($rows as $row) {
                if ($row->platformUploads->isNotEmpty()) continue;
                if (! Cache::has('yelp_biz_upload_queued:' . $row->id)) {
                    $failed++;
                }
            }

            $pending = max(0, $total - $done - $failed);

            $bar->setMessage((string) $done, 'done');
            $bar->setMessage((string) $pending, 'pending');
            $bar->setMessage((string) $failed, 'failed');

            $lastRunAt = (int) Cache::get('yelp:browser-automation:last-run-at', 0);
            $current = Cache::get('yelp:browser-automation:current');
            $currentData = null;
            if (is_string($current)) {
                $decoded = json_decode($current, true);
                if (is_array($decoded)) {
                    $currentData = $decoded;
                }
            }
            $lockHeld = Cache::has('yelp:browser-automation:lock');
            $cooldownUntil = (int) Cache::get('yelp:browser-automation:cooldown-until', 0);

            if ($currentData && isset($currentData['image_id'], $currentData['started_at'])) {
                // Live: a worker is actively running the upload subprocess.
                $elapsedSec = max(0, time() - (int) $currentData['started_at']);
                $statusMsg = sprintf('uploading #%d (%ds)', (int) $currentData['image_id'], $elapsedSec);
            } elseif ($lockHeld) {
                // Lock exists but no current-op marker — race window between
                // lock acquisition and marker write, or stale lock.
                $statusMsg = 'uploading';
            } elseif ($cooldownUntil > time()) {
                $statusMsg = sprintf('Yelp oops cooldown: %ds', $cooldownUntil - time());
            } elseif ($minInterval > 0 && $lastRunAt > 0 && (time() - $lastRunAt) < $minInterval) {
                $statusMsg = sprintf('throttle cooldown: %ds', max(0, $minInterval - (time() - $lastRunAt)));
            } elseif ($pending > 0) {
                // No active upload, no throttle window. Next pending image
                // and how long we've been waiting on the worker to pick up.
                $nextId = null;
                foreach ($rows as $row) {
                    if ($row->platformUploads->isEmpty() && Cache::has('yelp_biz_upload_queued:' . $row->id)) {
                        $nextId = $row->id;
                        break;
                    }
                }
                $sinceLast = $lastRunAt > 0 ? (time() - $lastRunAt) : (time() - $start);
                $statusMsg = $nextId
                    ? sprintf('next #%d (waiting %ds for worker)', $nextId, $sinceLast)
                    : sprintf('waiting %ds for worker', $sinceLast);
            } else {
                $statusMsg = 'idle';
            }
            $bar->setMessage($statusMsg, 'status');

            // Re-set position AND force display() so the elapsed/status
            // segment of the bar updates in-place on every tick even when
            // $done hasn't changed (ProgressBar skips redraw when the
            // progress integer is unchanged).
            $bar->setProgress($done);
            $bar->display();

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

            // Poll every 2s. Bar redraws in-place via \r so this does not
            // spam the terminal with new lines. Each tick is one indexed
            // SELECT + ~2 Redis reads (~50ms total) — negligible load.
            sleep(2);
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
