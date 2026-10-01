<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project areas — one real job is one Project; its photography and
 * write-up can split into areas (Kitchen, Mudroom, Living Space…), each
 * with its own category (project_type), optional heading and description,
 * and its own photos (see the sibling stub adding
 * project_images.project_area_id). A project belongs to its own
 * project_type AND every area's project_type — see
 * `SsSystems\Platform\Projects\Concerns\HasProjectAreas::scopeOfType()`.
 *
 * Copy this stub into your own database/migrations/ keeping THIS EXACT
 * filename (same timestamp as jpeterson-design.com's own, already-applied
 * migration of the same name) — see docs/PROJECT-AREAS.md. The
 * `Schema::hasTable` guard makes a copy harmless on a site that somehow
 * already has this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_areas')) {
            return;
        }

        Schema::create('project_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('project_type');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
            $table->index('project_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_areas');
    }
};
