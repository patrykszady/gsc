<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Local Falcon is gone (subscription cancelled 2026-09-04); the geo-grid
 * map-pack scans now come from DataForSEO's Google Maps SERP at each grid
 * point. Same tables, honest names; the report-family archive had no reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('local_falcon_scans') && ! Schema::hasTable('map_pack_scans')) {
            Schema::rename('local_falcon_scans', 'map_pack_scans');
        }
        if (Schema::hasTable('local_falcon_competitors') && ! Schema::hasTable('map_pack_competitors')) {
            Schema::rename('local_falcon_competitors', 'map_pack_competitors');
        }
        Schema::dropIfExists('local_falcon_reports');
    }

    public function down(): void
    {
        if (Schema::hasTable('map_pack_scans')) {
            Schema::rename('map_pack_scans', 'local_falcon_scans');
        }
        if (Schema::hasTable('map_pack_competitors')) {
            Schema::rename('map_pack_competitors', 'local_falcon_competitors');
        }
    }
};
