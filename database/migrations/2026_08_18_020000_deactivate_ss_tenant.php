<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ss.systems left this app.
 *
 * It now runs as its own Laravel application (repo patrykszady/ss-systems) on
 * its own Forge site, so this app no longer serves that host — the domain was
 * detached from the gs.construction site in nginx and points at the new one.
 *
 * Deactivated rather than deleted, deliberately:
 *
 *   - `Site::forHost()` only matches ACTIVE sites, so is_active = 0 is what
 *     actually stops the host resolving here. That is the whole functional
 *     change; the row itself is inert.
 *   - The row still owns real history — 678 tracked_404s and an
 *     ai_traffic_daily row in production at the time of writing. Deleting the
 *     site would orphan them (or force deleting analytics that cost nothing
 *     to keep). Keeping the row keeps those rows meaningful and the FKs sound.
 *
 * The theme (resources/themes/ss), the config overlay (config/sites/ss) and
 * docs/sites/ss.md are removed in the same change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sites')->where('slug', 'ss')->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Reversible, but note this only flips the flag back: the theme and
        // config overlay would have to come back from git for the tenant to
        // actually render anything.
        DB::table('sites')->where('slug', 'ss')->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }
};
