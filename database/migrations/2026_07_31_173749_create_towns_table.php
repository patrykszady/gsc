<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local gazetteer of towns, so the service-area map never waits on a
 * third-party API while someone is using it.
 *
 * The candidate dots were queried live from Overpass on every map idle.
 * Overpass is free public infrastructure and its availability genuinely
 * fluctuates — measured here answering in 3s, gateway-timing-out on the next
 * call, and returning HTML error pages that parse as "no towns". The result
 * was "could not load towns — pan slightly to retry" during normal use.
 *
 * Overpass is now an IMPORT-TIME dependency instead of a request-time one:
 * towns:import fetches a region once, and the map reads this table.
 *
 * NOT site-scoped, deliberately. A town is a fact about the world, not about
 * a tenant — Oak Park is at the same coordinates whoever serves it — so every
 * site shares one gazetteer and an import done for one benefits all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('towns', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('state', 8)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // 'city' | 'town' | 'village' from OSM place=*, kept so the map can
            // filter or weight by settlement size later.
            $table->string('kind', 16)->nullable();

            $table->timestamps();

            // The map's only query shape: everything inside a viewport.
            // Composite over both axes — a bbox filters on lat AND lng, and a
            // single-column index would leave the second as a scan.
            $table->index(['latitude', 'longitude'], 'towns_bbox_index');

            // Re-importing a region must update rows, not duplicate them.
            // Coordinates are part of the key because distinct places legitimately
            // share a name within a state (two Washingtons in different counties).
            $table->unique(['name', 'state', 'latitude', 'longitude'], 'towns_identity_unique');
        });

        // Records which viewports have been imported, so the map can tell
        // "no towns here" (a lake) apart from "never fetched this area".
        Schema::create('town_imports', function (Blueprint $table) {
            $table->id();
            $table->decimal('south', 10, 7);
            $table->decimal('west', 10, 7);
            $table->decimal('north', 10, 7);
            $table->decimal('east', 10, 7);
            $table->unsignedInteger('towns_found')->default(0);
            $table->timestamps();

            $table->unique(['south', 'west', 'north', 'east'], 'town_imports_bbox_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('town_imports');
        Schema::dropIfExists('towns');
    }
};
