<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds editable, per-city content fields to areas_served so each /areas-served/{city}
 * page can be genuinely unique (Google treats near-duplicate area pages poorly).
 *
 * All fields are nullable so existing rows are not affected; the area-page view
 * only renders sections when the relevant column is populated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            // 1-3 sentence local intro, used at top of /areas-served/{city}.
            // Mention neighborhoods, township, ZIP codes naturally.
            $table->text('intro')->nullable()->after('longitude');

            // Longer narrative — why we're a great fit for THIS city specifically.
            // Mention recent projects, common home styles, local building patterns.
            $table->text('local_intro')->nullable()->after('intro');

            // Short list (one per line or comma-separated) of well-known landmarks,
            // subdivisions, school districts. Helps disambiguate city + boost relevance.
            $table->text('landmarks')->nullable()->after('local_intro');

            // City-specific permit / building-code notes (1-3 sentences).
            // Demonstrates local expertise — strong E-E-A-T signal.
            $table->text('permit_notes')->nullable()->after('landmarks');
        });
    }

    public function down(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->dropColumn(['intro', 'local_intro', 'landmarks', 'permit_notes']);
        });
    }
};
