<?php

namespace App\Console\Commands;

use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;

class BackfillGooglePlacesMediaUrls extends Command
{
    protected $signature = 'gbp:backfill-media-urls
        {--limit=0 : Maximum rows to process (0 = all)}
        {--sleep-ms=250 : Delay between API calls in milliseconds}
        {--dry-run : Show what would be updated without writing}';

    protected $description = 'Populate image_platform_uploads.remote_url for google_places rows missing it.';

    public function handle(GoogleBusinessProfileService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('GBP service is not configured.');
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $sleepUs = max(0, (int) $this->option('sleep-ms')) * 1000;
        $dryRun = (bool) $this->option('dry-run');

        $query = \App\Models\ImagePlatformUpload::query()
            ->where('platform', \App\Models\ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)
            ->whereNotNull('remote_id')
            ->whereNull('remote_url')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Backfilling {$total} rows...");
        $bar = $this->output->createProgressBar($total);

        $updated = 0;
        $missing = 0;

        $query->chunkById(50, function ($rows) use ($service, $bar, &$updated, &$missing, $sleepUs, $dryRun) {
            foreach ($rows as $row) {
                $url = $service->getMediaUrl($row->remote_id);

                if ($url) {
                    if (! $dryRun) {
                        $row->update(['remote_url' => $url]);
                    }
                    $updated++;
                } else {
                    $missing++;
                }

                $bar->advance();
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Updated: {$updated}");
        if ($missing > 0) {
            $this->warn("Missing/unresolved: {$missing}");
        }

        return self::SUCCESS;
    }
}
