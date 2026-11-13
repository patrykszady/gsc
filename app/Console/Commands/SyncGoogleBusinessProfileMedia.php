<?php

namespace App\Console\Commands;

use App\Jobs\UploadProjectImageToGooglePlaces;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use App\Support\GbpPhotoOwnership;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use SsSystems\Platform\Google\BusinessProfile\Failure;

/**
 * Exit codes (2026-11-12): the scheduler logs "failed" for anything but 0,
 * and this command used to answer 1 every night in production for a reason
 * that was not a failure at all — the central admin uploads this site's
 * photos, so the scheduled `--upload --queue` had nothing to send. The rule
 * now, for every form:
 *
 * - 0 when the run did what there was to do, including nothing (photos owned
 *   by the central admin; everything already uploaded), and when some photos
 *   met a passing Google failure but others went up — those stay pending and
 *   the next run takes them;
 * - 1 when the run achieved nothing because Google kept failing, when Google
 *   refused something a retry will not fix (the grant, the listing, a photo
 *   it will never take), or when a list it had to read came back cut short.
 *
 * Either way the LAST line printed is the outcome, starting "Failed:" or
 * "Skipped:" when it is one, so the tail of the schedule's output file says
 * why. A passing failure (5xx, rate limiting) is asked again with a wait
 * first — GoogleBusinessProfileService::withTransientRetry().
 */
class SyncGoogleBusinessProfileMedia extends Command
{
    /**
     * Google's refusals that are about the grant, the sign-in client or the
     * listing itself: the next photo would be refused the same way, so the
     * run stops instead of asking once per photo.
     *
     * @var list<string>
     */
    private const REFUSALS_THAT_STOP_THE_RUN = [
        Failure::NO_GRANT,
        Failure::CLIENT_MISSING,
        Failure::INVALID_GRANT,
        Failure::CLIENT_CHANGED,
        Failure::CLIENT_REJECTED,
        Failure::SCOPE_MISSING,
        Failure::API_DISABLED,
        Failure::NO_API_ACCESS,
        Failure::PERMISSION_DENIED,
        Failure::NOT_FOUND,
    ];

    protected $signature = 'google-business-profile:sync
        {--upload : Upload all un-synced images to GBP}
        {--force : Re-upload all published images, even already synced ones}
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
            $this->line('Connect Google Business Profile and choose a listing on the admin Platforms screen.');
            $this->line('`php artisan google-business-profile:locations` lists the account and location ids the sign-in can manage.');
            $this->error('Failed: Google Business Profile is not fully configured (no Google sign-in stored, or no listing chosen) — nothing was sent.');

            return self::FAILURE;
        }

        if ($this->option('status')) {
            return $this->showStatus($service);
        }

        if (! GbpPhotoOwnership::ownedHere()) {
            return $this->leaveItToTheCentralAdmin();
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

    /**
     * The central admin uploads this site's photos. Asked only to upload
     * (the scheduled form), that leaves nothing to do here — a skip, exit 0,
     * which is what stops the nightly "Scheduled GBP sync failed". Asked to
     * read or clear Google's media, or for nothing in particular, it stays
     * the refusal it has been since 2026-09-22.
     */
    protected function leaveItToTheCentralAdmin(): int
    {
        $this->line('Use the Platforms screen on ss.systems, or `gbp:reupload-photos --site=gsc` there.');

        if ($this->option('upload') && ! $this->option('list') && ! $this->option('delete-orphans')) {
            $this->warn('Skipped: this site\'s Google photos are uploaded by the central admin (GBP_PHOTOS_OWNED_BY=ss-systems), so there was nothing for this site to send.');

            return self::SUCCESS;
        }

        $this->error('Failed: this site\'s Google photos are uploaded by the central admin (GBP_PHOTOS_OWNED_BY=ss-systems). Nothing was sent.');

        return self::FAILURE;
    }

    /**
     * One failure as a person would want it in a log line: what was being
     * done, Google's HTTP status when there was one, and the kit's sentence
     * for the reason.
     *
     * @param  array<string, mixed>|null  $error  GoogleBusinessProfileService::getLastError()
     */
    protected function describeFailure(?array $error): string
    {
        $what = (string) ($error['message'] ?? 'Unknown error');
        $status = (int) ($error['status'] ?? 0);
        $why = (string) ($error['description'] ?? '');

        return $what.($status > 0 ? " (HTTP {$status})" : '').($why !== '' ? ' — '.$why : '');
    }

    /**
     * Stop with the reason when Google's media list came back cut short:
     * part of a list must never be shown, or acted on, as all of it.
     */
    protected function failOnACutShortList(GoogleBusinessProfileService $service, string $consequence): ?int
    {
        $error = $service->getLastError();

        if ($error === null) {
            return null;
        }

        if (isset($error['body'])) {
            $this->line((string) $error['body']);
        }

        $this->error('Failed: Google did not give the whole media list ('.$this->describeFailure($error).'), so '.$consequence.'.');

        return self::FAILURE;
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

        if (($failure = $this->failOnACutShortList($service, 'nothing is listed')) !== null) {
            return $failure;
        }

        if (empty($items)) {
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

        if (($failure = $this->failOnACutShortList($service, 'nothing was checked or deleted')) !== null) {
            return $failure;
        }

        if ($gbpItems === []) {
            $this->warn('No media on GBP.');

            return self::SUCCESS;
        }

        // Get all local media names that have been uploaded
        $localMediaNames = \App\Models\ImagePlatformUpload::query()
            ->where('platform', \App\Models\ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)
            ->pluck('remote_id')
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
        $temporary = 0;
        $refusal = null;
        $bar = $this->output->createProgressBar($orphans->count());

        foreach ($orphans as $item) {
            $name = (string) ($item['name'] ?? '');

            if ($name === '') {
                $bar->advance();

                continue;
            }

            if ($service->withTransientRetry(fn (): bool => $service->deleteMedia($name))) {
                $deleted++;
            } elseif ($service->lastErrorIsTransient()) {
                $temporary++;
            } else {
                $refusal = "{$name}: ".$this->describeFailure($service->getLastError());

                break;
            }

            $bar->advance();

            // Rate-limit: 200ms between deletes
            Sleep::usleep(200_000);
        }

        $bar->finish();
        $this->newLine(2);

        $summary = "deleted {$deleted} of {$orphans->count()} orphaned media items";

        if ($refusal !== null) {
            $this->error("Failed: {$summary}, then stopped — Google refused {$refusal}");

            return self::FAILURE;
        }

        if ($deleted === 0 && $temporary > 0) {
            $this->error("Failed: nothing was deleted — {$temporary} of {$orphans->count()} met a temporary Google error ({$this->describeFailure($service->getLastError())}) and are still there.");

            return self::FAILURE;
        }

        $this->info(ucfirst($summary).' from GBP.'.($temporary > 0 ? " {$temporary} met a temporary Google error and are still there." : ''));

        return self::SUCCESS;
    }

    protected function uploadPending(GoogleBusinessProfileService $service): int
    {
        $dryRun = $this->option('dry-run');
        $useQueue = $this->option('queue');
        $force = (bool) $this->option('force');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $projectId = $this->option('project-id') ? (int) $this->option('project-id') : null;

        $query = ProjectImage::query()
            ->with('project')
            ->whereHas('project', fn ($q) => $q->where('is_published', true));

        if (! $force) {
            $query->notUploadedTo('google_places');
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $query->orderBy('project_id')->orderBy('sort_order');

        if ($limit) {
            $query->limit($limit);
        }

        $images = $query->get();

        if ($images->isEmpty()) {
            if ($force) {
                $this->info('No published project images found to force re-upload.');
            } else {
                $this->info('All published project images are already uploaded to GBP.');
            }

            return self::SUCCESS;
        }

        $this->info("Found {$images->count()} images to upload.");

        if ($dryRun) {
            foreach ($images as $img) {
                $mode = $force ? 'Would re-upload' : 'Would upload';
                $this->line("  {$mode}: [{$img->project->title}] {$img->alt_text}");
            }

            return self::SUCCESS;
        }

        if ($useQueue) {
            foreach ($images as $img) {
                UploadProjectImageToGooglePlaces::dispatch($img->id, $force)
                    ->onQueue('media-sync')
                    ->delay(now()->addSeconds(2 * $images->search(fn ($i) => $i->id === $img->id)));
            }

            $kind = $force ? 'refresh' : 'upload';
            $this->line('Run `php artisan queue:work --queue=media-sync` to process.');
            $this->info("Queued {$images->count()} {$kind} jobs on the 'media-sync' queue.");

            return self::SUCCESS;
        }

        // Inline upload
        $bar = $this->output->createProgressBar($images->count());
        $uploaded = 0;
        $temporary = 0;
        $lastTemporary = '';
        $refused = 0;
        $firstRefusal = '';
        $stoppedBy = null;

        foreach ($images as $image) {
            $previousMediaName = $image->google_places_media_name;
            if ($force && $previousMediaName) {
                $service->withTransientRetry(fn (): bool => $service->deleteMedia($previousMediaName));
            }

            $mediaName = $service->withTransientRetry(fn (): ?array => $service->uploadProjectImage($image), idempotent: false);

            if ($mediaName) {
                \App\Models\ImagePlatformUpload::record($image->id, \App\Models\ImagePlatformUpload::PLATFORM_GOOGLE_PLACES, [
                    'remote_id' => $mediaName['name'],
                    'remote_url' => $mediaName['url'],
                ]);
                $uploaded++;
            } else {
                $error = $service->getLastError();
                $why = $this->describeFailure($error);
                $this->newLine();
                $this->warn("  Failed: image #{$image->id} — {$why}");

                if ($service->lastErrorIsTransient()) {
                    $temporary++;
                    $lastTemporary = $why;
                } elseif (in_array($error['reason'] ?? null, self::REFUSALS_THAT_STOP_THE_RUN, true)) {
                    $stoppedBy = $why;

                    break;
                } else {
                    $refused++;
                    $firstRefusal = $firstRefusal ?: "image #{$image->id}: {$why}";
                }
            }

            $bar->advance();

            // Rate-limit: 500ms between uploads to be respectful
            Sleep::usleep(500_000);
        }

        $bar->finish();
        $this->newLine(2);

        if ($stoppedBy !== null) {
            $this->error("Failed: stopped after {$uploaded} of {$images->count()} — {$stoppedBy}");

            return self::FAILURE;
        }

        $failed = $temporary + $refused;
        $pending = $temporary > 0 ? " {$temporary} met a temporary Google error and stay pending for the next run." : '';

        if ($refused > 0) {
            $this->error("Failed: {$uploaded} succeeded, {$failed} failed. Google refused {$refused} for a reason a retry will not fix (first, {$firstRefusal}).{$pending}");

            return self::FAILURE;
        }

        if ($uploaded === 0 && $temporary > 0) {
            $this->error("Failed: nothing was uploaded — {$temporary} of {$images->count()} met a temporary Google error ({$lastTemporary}) and stay pending for the next run.");

            return self::FAILURE;
        }

        $this->info("Upload complete: {$uploaded} succeeded, {$failed} failed.{$pending}");

        return self::SUCCESS;
    }
}
