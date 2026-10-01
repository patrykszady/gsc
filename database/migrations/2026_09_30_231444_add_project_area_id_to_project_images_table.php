<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which area (if any) a photo belongs to — null means it belongs to the
 * project as a whole. Null on delete (not cascade): deleting an area never
 * deletes its photos, it only makes them area-less again.
 *
 * Copy this stub into your own database/migrations/ keeping THIS EXACT
 * filename — see the create_project_areas_table stub's docblock and
 * docs/PROJECT-AREAS.md. Adjust the table name below if your own photos
 * table isn't named `project_images`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('project_images', 'project_area_id')) {
            return;
        }

        Schema::table('project_images', function (Blueprint $table) {
            $table->foreignId('project_area_id')->nullable()->after('project_id')->constrained('project_areas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_area_id');
        });
    }
};
