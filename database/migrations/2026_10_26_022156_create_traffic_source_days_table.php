<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Traffic\TrafficSchema;

/**
 * Every provider's per-source numbers by day (kit 0.17): beacon visits,
 * tracked contacts, leads, Clarity, Search Console, Bing, Business Profile and
 * the access log — scoped by site_id like the rest of this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_source_days', fn (Blueprint $table) => TrafficSchema::createDailyTable($table, 'site_id'));
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_source_days');
    }
};
