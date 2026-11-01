<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kit SEO tools port (0.19.1, 2026-11-01): map_pack_scans' unique key was a
 * bare `scan_id` (still named local_falcon_scans_scan_id_unique — renaming
 * the table in 2026_09_05_000000 never renamed its indexes), computed by
 * SeoMapPackGrid as 'grid-'.date.'-'.substr(md5(keyword), 0, 8). The kit's
 * MapPackStore::recordScan() keys its race-safe upsert on
 * (site_id, scan_id) instead — see resources/stubs/seo/
 * create_map_pack_scans_table.php.stub's own docblock — because a bare
 * scan_id collides across tenants scanning the same keyword on the same
 * day. gs.construction runs one active tenant today, so this is a
 * forward-looking correctness fix, not a live bug; finding the index by
 * its columns (not its name) keeps this safe to run whatever the index
 * happens to be called on this database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_pack_scans', function (Blueprint $table): void {
            $existing = collect(Schema::getIndexes('map_pack_scans'))
                ->first(fn (array $idx) => $idx['unique'] && $idx['columns'] === ['scan_id']);

            if ($existing !== null) {
                $table->dropUnique($existing['name']);
            }

            $table->unique(['site_id', 'scan_id'], 'map_pack_scans_site_id_scan_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('map_pack_scans', function (Blueprint $table): void {
            $table->dropUnique('map_pack_scans_site_id_scan_id_unique');
            $table->unique('scan_id', 'local_falcon_scans_scan_id_unique');
        });
    }
};
