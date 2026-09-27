<?php

namespace App\Console\Commands;

use App\Models\Citation;
use App\Models\Site;
use App\Support\Citations\KnownListings;
use Illuminate\Console\Command;
use SsSystems\Platform\Citations\Contracts\CitationSession;
use SsSystems\Platform\Citations\Sync;

/**
 * Bring the citations table in line with config/citations.php: one row per
 * directory, new ones planned, existing statuses untouched.
 *
 *   php artisan citations:sync
 *   php artisan citations:sync --list
 *
 * The register/cleanup body moved to the kit's Citations\Sync
 * (citations-batch-sync, 2026-09-27 — ported verbatim, see that class's
 * own docblock); this command is now a thin wrapper supplying gsc's own
 * scoping (`$baseQuery`/`$create`, both closed over `Site::current()`),
 * default tier and known-profiles source, then printing the same table
 * it always did. `KnownListings::reconcile()` is unchanged — a separate,
 * later kit unit, not part of this one.
 */
class CitationsSync extends Command
{
    protected $signature = 'citations:sync {--list : Show every directory and its status}';

    protected $description = 'Register the configured directories in the citations table and list their status';

    public function handle(CitationSession $sessions): int
    {
        $siteId = Site::current()?->id;

        $sync = new Sync(
            $sessions,
            fn () => Citation::query()->where('site_id', $siteId),
            fn (array $attrs) => Citation::create($attrs + ['site_id' => $siteId]),
            defaultTier: 2,
            // Profiles we already have (config/brand.php 'profiles') seed the listing
            // URL of the matching directory, so the link check can verify them right away.
            knownProfiles: (array) config('brand.profiles', []),
        );

        ['created' => $created] = $sync->register();
        $sync->cleanupStale();
        // Listings Platforms already knows (Houzz/Angi profiles, Yelp, the
        // Facebook page) read as live here rather than as work to do.
        $matched = KnownListings::reconcile();

        $this->info("Citations registry synced ({$created} new, {$matched} matched from Platforms).");

        if ($this->option('list')) {
            $rows = Citation::query()->where('site_id', $siteId)->orderBy('tier')->orderBy('name')->get();
            $this->table(['Tier', 'Directory', 'Status', 'Listing', 'Photos', 'Links to us', 'Human step / note'], $rows->map(fn ($c) => [
                $c->tier, $c->name, $c->status, $c->listing_url ? mb_substr($c->listing_url, 0, 50) : '—', $c->photos_uploaded,
                $c->links_to_us === null ? '?' : ($c->links_to_us ? 'yes' : 'no'), mb_substr((string) ($c->human_reason ?: $c->note), 0, 60),
            ])->all());
        }

        return self::SUCCESS;
    }
}
