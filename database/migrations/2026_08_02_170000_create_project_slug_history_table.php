<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every slug a project has ever had.
 *
 * Project pages and their photo pages are indexed, and the photo URLs nest
 * under the project slug — renaming one project moves its whole photo set
 * with it. Without a record of the old slug those URLs 404, which loses the
 * ranking the rename was meant to improve.
 *
 * Kept as a table rather than a JSON column so the lookup is a single indexed
 * query on the 404 path, and so a slug can never be reused by another project
 * while an old URL still points at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_slug_history', function (Blueprint $table) {
            $table->id();
            // Unique across ALL projects: two projects must never claim the
            // same historical slug, or the redirect becomes ambiguous.
            $table->string('slug')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_slug_history');
    }
};
