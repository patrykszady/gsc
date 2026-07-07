<?php

namespace App\Console\Commands;

use App\Models\ImageSocialPost;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Remove a burst of duplicate Google Business Profile "update" posts (local
 * posts) from BOTH Google and the local database, keeping N newest, with a JSON
 * audit trail so the affected images can be re-posted later.
 *
 * Context: when the social-media queue backlog drained at once, a single run
 * created dozens of GBP local posts instead of one. Each created a live GBP
 * local post AND an `image_social_posts` row. This command is driven off those
 * rows (the authoritative record, which carries project_image_id), deletes the
 * matching GBP post, then removes the row so the image returns to rotation.
 *
 * Dry-run by default; pass --force to apply. Deleting a GBP post that is already
 * gone (404) is treated as success so the run is idempotent.
 */
class GbpCleanupDuplicatePosts extends Command
{
    protected $signature = 'gbp:cleanup-duplicate-posts
                            {--keep=1 : Number of most-recent posts in the window to KEEP}
                            {--since=-24 hours : Only consider posts created at/after this time (strtotime-parseable, e.g. "-24 hours" or "2026-07-07")}
                            {--force : Actually delete from GBP + database (otherwise dry-run preview only)}';

    protected $description = 'Delete duplicate GBP local posts from GBP and the database, keeping N newest, with a JSON audit trail for re-posting.';

    public function handle(GoogleBusinessProfileService $gbp): int
    {
        if (! $gbp->isConfigured()) {
            $this->error('Google Business Profile is not configured/connected.');

            return self::FAILURE;
        }

        $keep = max(0, (int) $this->option('keep'));

        try {
            $since = Carbon::parse((string) $this->option('since'));
        } catch (\Throwable $e) {
            $this->error('Could not parse --since value: ' . $this->option('since'));

            return self::FAILURE;
        }

        $this->info("Finding published GBP posts created at/after {$since->toDateTimeString()}...");

        $rows = ImageSocialPost::query()
            ->where('platform', 'google_business')
            ->where('status', 'published')
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No matching posts in the window. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Found {$rows->count()} post(s); keeping {$keep} newest.");

        $toDelete = $rows->slice($keep)->values();
        if ($toDelete->isEmpty()) {
            $this->info('Post count is within the keep threshold. Nothing to delete.');

            return self::SUCCESS;
        }

        // Build the audit records BEFORE deleting anything.
        $records = $toDelete->map(fn (ImageSocialPost $p) => [
            'image_social_post_id' => $p->id,
            'project_image_id' => $p->project_image_id,
            'gbp_post_name' => $p->platform_post_id,
            'created_at' => optional($p->created_at)->toIso8601String(),
            'published_at' => optional($p->published_at)->toIso8601String(),
            'caption' => $p->caption,
            'permalink' => $p->platform_permalink,
        ]);

        $this->table(
            ['image_social_post_id', 'project_image_id', 'created', 'caption'],
            $records->map(fn ($r) => [
                $r['image_social_post_id'],
                $r['project_image_id'] ?? '—',
                $r['created_at'],
                Str::limit((string) $r['caption'], 50),
            ])->all(),
        );

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn("DRY RUN — would delete {$toDelete->count()} post(s) from GBP and the database. Re-run with --force to apply.");

            return self::SUCCESS;
        }

        // Persist the audit trail first so nothing is lost if deletion is interrupted.
        $file = 'gbp-cleanup/removed-posts-' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($file, json_encode($records->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Audit trail written: ' . Storage::disk('local')->path($file));

        $deleted = 0;
        $failed = 0;

        foreach ($toDelete as $post) {
            $name = (string) $post->platform_post_id;

            // Delete on GBP. A 404 means it's already gone — treat as success.
            $gbpOk = $name === '' || $gbp->deleteLocalPost($name);
            if (! $gbpOk && data_get($gbp->getLastError(), 'status') === 404) {
                $gbpOk = true;
                $this->line("  <fg=yellow>•</> {$name} already absent on GBP");
            }

            if (! $gbpOk) {
                $failed++;
                $this->line("  <fg=red>✗</> GBP delete failed for post #{$post->id} — " . json_encode($gbp->getLastError()));
                continue;
            }

            // Remove the database row so the image returns to the posting rotation.
            $post->delete();
            $deleted++;
            $this->line("  <fg=green>✓</> removed post #{$post->id} (image {$post->project_image_id}) from GBP + database");
        }

        $this->newLine();
        $this->info("Done. removed={$deleted} failed={$failed} kept={$keep}.");
        $this->line('Freed images return to the normal GBP rotation; re-post specific ones from the audit trail if needed.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
