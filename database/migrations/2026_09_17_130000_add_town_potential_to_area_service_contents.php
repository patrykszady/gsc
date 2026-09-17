<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "What that means for kitchen remodeling in Palatine": the town's housing
 * history read through one trade, written per (town, service) by
 * seo:generate-area-service-potential. The town page folds the town-wide
 * version; a service page folds this one, so the fold is unique per page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('area_service_contents', function (Blueprint $table) {
            $table->text('town_potential')->nullable()->after('permit_notes');
        });
    }

    public function down(): void
    {
        Schema::table('area_service_contents', function (Blueprint $table) {
            $table->dropColumn('town_potential');
        });
    }
};
