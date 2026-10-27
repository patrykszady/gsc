<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Services\HiveProjectsClient;
use Illuminate\Console\Command;

/**
 * Asks hive.contractors for the adopted building-code set of every served
 * town and stores what it finds on AreaServed. A town hive has never seen
 * before has no answer yet — it starts a background lookup there and
 * answers "pending", which a later day's run picks up — so a pending,
 * failed or not_found answer (or an HTTP error) is logged and skipped,
 * NEVER wiping whatever is already stored for that town.
 *
 *   php artisan areas:sync-building-codes
 *   php artisan areas:sync-building-codes --area=schaumburg
 *   php artisan areas:sync-building-codes --dry-run
 *
 * Does nothing (and exits cleanly) when services.hive.token is empty — the
 * same gate HiveProjectsClient::isConfigured() applies everywhere else.
 */
class AreasSyncBuildingCodes extends Command
{
    protected $signature = 'areas:sync-building-codes
        {--area= : Only this area\'s slug, instead of every served town}
        {--dry-run : Show what would be stored without writing to the database}';

    protected $description = "Sync each served town's adopted building codes from hive.contractors";

    public function handle(HiveProjectsClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->info('Hive is not configured (services.hive.token is empty) — skipping.');

            return self::SUCCESS;
        }

        $areaSlug = $this->option('area');
        $dryRun = (bool) $this->option('dry-run');
        $state = (string) config('brand.state', 'IL');

        $areas = AreaServed::query()
            ->when($areaSlug, fn ($q) => $q->where('slug', $areaSlug))
            ->orderBy('city')
            ->get();

        if ($areas->isEmpty()) {
            $this->error($areaSlug ? "No area found for slug \"{$areaSlug}\"." : 'No served areas found.');

            return self::FAILURE;
        }

        $found = 0;
        $kept = 0;
        $skipped = 0;

        foreach ($areas as $area) {
            $result = $client->buildingCodes($area->city, $state);

            if ($result === null) {
                // Already logged by the client (not configured, unreachable,
                // refused, or a malformed payload) — never wipe what's stored.
                $this->warn("{$area->city}: could not get an answer from hive — keeping stored codes.");
                $skipped++;

                continue;
            }

            if ($result['status'] !== 'found') {
                $this->line("{$area->city}: hive status \"{$result['status']}\" — keeping stored codes.");
                $kept++;

                continue;
            }

            $codeCount = count($result['codes']);

            if ($dryRun) {
                $this->info("{$area->city}: would store {$codeCount} code(s) from {$result['source_url']}.");
                $found++;

                continue;
            }

            $area->forceFill([
                'building_codes' => $result['codes'],
                'building_codes_source_url' => $result['source_url'],
                'building_codes_note' => $result['note'],
                'building_codes_checked_at' => $result['checked_at'],
            ])->save();

            $this->info("{$area->city}: stored {$codeCount} code(s).");
            $found++;
        }

        $this->newLine();
        $this->info("Done. Found: {$found}, pending/failed/not_found: {$kept}, no answer: {$skipped}.".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
