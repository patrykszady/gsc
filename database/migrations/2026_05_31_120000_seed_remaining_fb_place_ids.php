<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill fb_place_id (and ig_location_id when available) on areas_served
 * from database/data/areas_served_ids.csv.
 *
 * Idempotent: only fills NULL/empty cells, never clobbers existing values.
 * Safe to re-run the CSV is updated again later — just bump the timestamp
 * on a new copy of this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('areas_served')) return;

        $csv = database_path('data/areas_served_ids.csv');
        if (! is_file($csv)) return;

        $fh = fopen($csv, 'rb');
        if (! $fh) return;

        $header = fgetcsv($fh);
        if (! is_array($header)) { fclose($fh); return; }
        $idx = array_flip($header);
        if (! isset($idx['city'], $idx['fb_place_id'])) { fclose($fh); return; }

        $filled = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $city = trim((string) ($row[$idx['city']] ?? ''));
            $fb = trim((string) ($row[$idx['fb_place_id']] ?? ''));
            $ig = isset($idx['ig_location_id']) ? trim((string) ($row[$idx['ig_location_id']] ?? '')) : '';
            if ($city === '') continue;

            $existing = DB::table('areas_served')
                ->where('city', $city)
                ->first(['ig_location_id', 'fb_place_id']);
            if (! $existing) continue;

            $patch = [];
            if ($fb !== '' && empty($existing->fb_place_id)) $patch['fb_place_id'] = $fb;
            if ($ig !== '' && empty($existing->ig_location_id)) $patch['ig_location_id'] = $ig;
            if ($patch) {
                DB::table('areas_served')->where('city', $city)->update($patch);
                $filled++;
            }
        }
        fclose($fh);

        if ($filled > 0) {
            echo "  - filled platform IDs for {$filled} areas_served row(s)\n";
        }
    }

    public function down(): void
    {
        // Non-destructive: backfill only. Nothing to undo.
    }
};
