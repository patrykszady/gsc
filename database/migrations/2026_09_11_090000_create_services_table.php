<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's services — one admin-managed list replacing the hardcoded
 * Project::projectTypes() vocabulary behind the project form's "Project
 * Type", which could not change without a deploy. Same table and API as
 * jpeterson-design's, so the central admin's Services screen and sidebar
 * serve both sites.
 *
 * The landing-page generator deliberately stays on its own fixed catalogue
 * (TitleMetaGenerator::SERVICES): those slugs are baked into live
 * /areas/{city}/{service} URLs and the SEO autopilot, and are not the same
 * words as the project types.
 *
 * `slug` is what projects store in their existing project_type column, so
 * the seeder keeps the seven old keys verbatim and nothing needs remapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Optional description; nothing public reads it on this site yet.
            $table->text('blurb')->nullable();
            // Landing pages are generated per service; not every service needs one.
            $table->boolean('is_landing_page')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
