<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The building-code edition(s) a served town's building department has
 * adopted, sourced from hive.contractors (GET /api/v1/building-codes — a
 * fresh town starts a background lookup there and answers "pending" until a
 * later daily sync picks the result up). See App\Services\HiveProjectsClient
 * ::buildingCodes() and the areas:sync-building-codes command.
 *
 * Only the FOUND result is ever stored — a pending/failed/not_found answer
 * never writes here, so these columns only ever hold real data or nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->json('building_codes')->nullable()->after('permit_notes');
            $table->string('building_codes_source_url', 500)->nullable()->after('building_codes');
            $table->string('building_codes_note', 300)->nullable()->after('building_codes_source_url');
            $table->timestamp('building_codes_checked_at')->nullable()->after('building_codes_note');
        });
    }

    public function down(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->dropColumn(['building_codes', 'building_codes_source_url', 'building_codes_note', 'building_codes_checked_at']);
        });
    }
};
