<?php

namespace App\Console\Commands;

use App\Models\ReviewUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairTestimonialsProduction extends Command
{
    protected $signature = 'testimonials:repair-production
        {--dry-run : Show what would run and avoid writes where supported}
        {--run-migrate : Run php artisan migrate --force before repair steps}
        {--skip-google : Skip Google sync/match/backfill steps}
        {--skip-houzz : Skip Houzz sync step}
        {--houzz-profile-url=https://www.houzz.com/professionals/kitchen-and-bath-remodelers/gs-construction-pfvwus-pf~1225706575 : Houzz profile URL to scrape}
        {--no-browser : Do not use browser-based Houzz scraping}
        {--no-seed-from-db : Do not include existing Houzz URLs from DB as seeds}
        {--clean-google-urls : Remove generic Google short URLs during matching}';

    protected $description = 'Production repair pipeline for testimonials/review_urls (Google external_id, Google URL normalization, Houzz resync).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('run-migrate')) {
            $ok = $this->runStep('Running migrations', 'migrate', [
                '--force' => true,
            ]);

            if (! $ok) {
                return self::FAILURE;
            }
        }

        if (! $this->option('skip-google')) {
            if (! $this->ensureGoogleExternalIdColumn()) {
                return self::FAILURE;
            }

            $this->backfillGoogleExternalIdsFromLegacyField($dryRun);

            $syncArgs = [];
            if ($dryRun) {
                $syncArgs['--dry-run'] = true;
            }

            if (! $this->runStep('Syncing Google reviews', 'google-business-profile:sync-reviews', $syncArgs)) {
                return self::FAILURE;
            }

            $matchArgs = [
                '--normalize-google-urls' => true,
            ];
            if ($dryRun) {
                $matchArgs['--dry-run'] = true;
            }
            if ($this->option('clean-google-urls')) {
                $matchArgs['--clean-urls'] = true;
            }

            if (! $this->runStep('Matching Google reviews to testimonials', 'google-business-profile:match-reviews', $matchArgs)) {
                return self::FAILURE;
            }
        }

        if (! $this->option('skip-houzz')) {
            $houzzArgs = [
                '--profile-url' => (string) $this->option('houzz-profile-url'),
            ];
            if (! $this->option('no-browser')) {
                $houzzArgs['--browser-scrape'] = true;
            }
            if (! $this->option('no-seed-from-db')) {
                $houzzArgs['--seed-from-db'] = true;
            }
            if ($dryRun) {
                $houzzArgs['--dry-run'] = true;
            }

            if (! $this->runStep('Syncing Houzz reviews', 'testimonials:sync-houzz-reviews', $houzzArgs)) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Repair pipeline finished.');

        return self::SUCCESS;
    }

    private function ensureGoogleExternalIdColumn(): bool
    {
        if (! Schema::hasTable('review_urls')) {
            $this->error('review_urls table is missing. Run migrations first.');

            return false;
        }

        if (! Schema::hasColumn('review_urls', 'external_id')) {
            $this->error('review_urls.external_id is missing. Run: php artisan migrate --force');

            return false;
        }

        return true;
    }

    private function backfillGoogleExternalIdsFromLegacyField(bool $dryRun): void
    {
        if (! Schema::hasTable('testimonials') || ! Schema::hasColumn('testimonials', 'google_review_id')) {
            return;
        }

        $rows = DB::table('testimonials')
            ->whereNotNull('google_review_id')
            ->where('google_review_id', '!=', '')
            ->get(['id', 'google_review_id']);

        if ($rows->isEmpty()) {
            return;
        }

        $this->info('Backfilling Google external_id from testimonials.google_review_id...');

        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $existing = ReviewUrl::query()
                ->where('testimonial_id', $row->id)
                ->where('platform', 'google')
                ->first();

            if (! $existing) {
                $created++;
                if (! $dryRun) {
                    ReviewUrl::create([
                        'testimonial_id' => $row->id,
                        'platform' => 'google',
                        'url' => $this->buildFallbackGoogleUrl(),
                        'external_id' => $row->google_review_id,
                    ]);
                }
                continue;
            }

            if ((string) ($existing->external_id ?? '') === (string) $row->google_review_id) {
                continue;
            }

            $updated++;
            if (! $dryRun) {
                $existing->update([
                    'external_id' => $row->google_review_id,
                    'url' => $existing->url ?: $this->buildFallbackGoogleUrl(),
                ]);
            }
        }

        $this->line(($dryRun ? '[DRY RUN] ' : '') . "Backfill summary: created {$created}, updated {$updated}.");
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runStep(string $title, string $command, array $arguments = []): bool
    {
        $this->newLine();
        $this->info($title . '...');

        $exitCode = Artisan::call($command, $arguments);
        $output = trim((string) Artisan::output());
        if ($output !== '') {
            $this->line($output);
        }

        if ($exitCode !== 0) {
            $this->error("Step failed: {$command} (exit code {$exitCode})");

            return false;
        }

        return true;
    }

    private function buildFallbackGoogleUrl(): string
    {
        $placeId = (string) config('services.google.business_profile.place_id', '');

        if ($placeId !== '') {
            return 'https://search.google.com/local/reviews?placeid=' . $placeId;
        }

        return 'https://www.google.com/maps';
    }
}
