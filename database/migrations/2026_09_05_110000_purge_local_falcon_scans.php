<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local Falcon is cancelled; the geo-grid is now scanned through DataForSEO
 * (seo:map-pack-grid). Drop the scan rows and competitor rows the old sync
 * wrote so the map-pack card only ever shows the new source. DataForSEO
 * scans are the ones whose scan id starts with "grid-" (MySQL re-spaces
 * JSON columns, so matching the raw detail text is not reliable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('map_pack_scans')) {
            DB::table('map_pack_scans')->where(fn ($q) => $q->whereNull('scan_id')->orWhere('scan_id', 'not like', 'grid-%'))->delete();
        }
        if (Schema::hasTable('map_pack_competitors')) {
            DB::table('map_pack_competitors')->where(fn ($q) => $q->whereNull('scan_id')->orWhere('scan_id', 'not like', 'grid-%'))->delete();
        }
        \Illuminate\Support\Facades\Cache::forget(\App\Support\Tenancy::cacheKey('seo_reports_map_pack_v1'));
    }

    public function down(): void
    {
        // Data purge; nothing to restore.
    }
};
