<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors towns.kind onto areas_served: 'city'/'town'/'village'/'hamlet' for
 * an ordinary service area, or 'neighbourhood'/'suburb'/'quarter' for one
 * added from a big city's OSM subdivision (see TownsImport's neighbourhood
 * pass and AreaMapController::createFromMap). Nullable — every row created
 * before this, and anything typed into the plain New Area form rather than
 * clicked from the map, carries no OSM kind at all and that is fine; the
 * admin is what decides which kinds actually get an orange dot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->string('kind', 32)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
