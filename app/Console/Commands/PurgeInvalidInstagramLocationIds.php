<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Services\MetaSocialService;
use Illuminate\Console\Command;

/**
 * Purge cached areas_served.ig_location_id values that the Instagram Graph API
 * cannot use as a media location tag.
 *
 * Historically these IDs were scraped as Instagram location PKs, but the Graph
 * /media `location_id` parameter only accepts a Facebook Page place ID, so each
 * daily IG post that tags such a city fails with
 * `(#100) Param location_id is not a valid location page ID` and re-clears it.
 *
 * This command validates each cached ID against the Graph API directly (no
 * Puppeteer session required, only the Meta token) and nulls the unusable ones
 * in one pass. Dry-run by default; pass --force to apply.
 */
class PurgeInvalidInstagramLocationIds extends Command
{
    protected $signature = 'instagram:purge-invalid-locations
                            {--force : Actually null invalid IDs (otherwise dry-run preview only)}';

    protected $description = 'Validate cached Instagram location IDs against the Graph API and clear the unusable ones.';

    public function handle(MetaSocialService $meta): int
    {
        if (! $meta->isInstagramConfigured()) {
            $this->error('Instagram/Meta is not configured (missing token or IG account). Cannot validate IDs.');

            return self::FAILURE;
        }

        $areas = AreaServed::query()
            ->whereNotNull('ig_location_id')
            ->orderBy('city')
            ->get(['id', 'city', 'slug', 'ig_location_id']);

        if ($areas->isEmpty()) {
            $this->info('No cached Instagram location IDs to check.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('force');
        $this->info(sprintf('Validating %d cached IG location IDs%s...', $areas->count(), $apply ? '' : ' (dry-run)'));

        $valid = 0;
        $invalid = 0;

        foreach ($areas as $area) {
            $id = (string) $area->ig_location_id;
            if ($meta->isUsableInstagramLocationId($id)) {
                $valid++;
                $this->line(sprintf('  <info>✓</info> %s → %s (valid place page)', $area->city, $id));

                continue;
            }

            $invalid++;
            if ($apply) {
                $area->update(['ig_location_id' => null]);
                $this->line(sprintf('  <comment>×</comment> %s → %s (cleared)', $area->city, $id));
            } else {
                $this->line(sprintf('  <comment>×</comment> %s → %s (would clear)', $area->city, $id));
            }
        }

        $this->newLine();
        $this->info("Valid: {$valid}");
        if ($invalid > 0) {
            $apply
                ? $this->warn("Cleared: {$invalid}")
                : $this->warn("Would clear: {$invalid} (re-run with --force to apply)");
        }

        return self::SUCCESS;
    }
}
