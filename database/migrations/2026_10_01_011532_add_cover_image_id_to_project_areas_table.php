<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An area's own chosen cover (2026-10-01 addendum to the project-areas
 * contract): the admin-picked image.id this area leads with, falling back
 * to its is_cover image, then its first — see
 * `SsSystems\Platform\Projects\Concerns\IsProjectArea::cover()`. Null on
 * delete, not cascade: deleting the cover photo never deletes the area, it
 * only goes back to the automatic choice.
 *
 * Copy this stub into your own database/migrations/ keeping THIS EXACT
 * filename — see create_project_areas_table's docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('project_areas', 'cover_image_id')) {
            return;
        }

        Schema::table('project_areas', function (Blueprint $table) {
            $table->foreignId('cover_image_id')->nullable()->after('sort_order')->constrained('project_images')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_image_id');
        });
    }
};
