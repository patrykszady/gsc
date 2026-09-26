<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table SsSystems\Platform\Pulse\Storage\DatabaseTableStorage reads and
 * writes for this app's Pulse telemetry (see docs/PULSE.md in the kit and
 * AppServiceProvider's Recorder/SnapshotBuilder bindings). Adapted from the
 * kit's resources/stubs/pulse/create_site_events_table.php.stub: this app is
 * multi-tenant, so site_id is uncommented per this repo's own convention —
 * nullable, indexed, no .constrained() (see e.g.
 * 2026_09_19_120000_create_clarity_page_metrics_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('event', 24);
            $table->string('path', 191)->nullable();
            $table->json('meta')->nullable();
            $table->string('vhash', 64);          // sha256(ip+key+day) — never the IP
            $table->string('city', 60)->nullable(); // a city-guess callable's answer, if any
            $table->boolean('mobile')->default(false);
            $table->timestamp('created_at');
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_events');
    }
};
