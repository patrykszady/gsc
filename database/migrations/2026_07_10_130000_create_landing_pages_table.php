<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demand-driven programmatic landing pages, served under the dedicated
 * /remodeling/{slug} namespace so they never collide with — or cannibalize —
 * the existing /areas-served and /services page families.
 *
 * A page is only INDEXED when it is published AND proof-gated (has real
 * project/testimonial backing), which keeps the Autopilot from ever
 * re-creating the thin-content sprawl that the area-page pruning removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            // Targeting: the query cluster + the structured intent behind it.
            $table->string('template')->default('service_modifier_city');
            $table->string('service')->nullable();   // service slug (kitchen-remodeling, …)
            $table->string('city')->nullable();       // human city name
            $table->string('modifier')->nullable();   // luxury | affordable | small-space | condo | …
            $table->string('target_query')->nullable();

            // Rendered, unique content.
            $table->string('title');
            $table->string('h1');
            $table->text('meta_description')->nullable();
            $table->text('intro')->nullable();
            $table->json('sections')->nullable();     // [{heading, body}, …]
            $table->json('faq')->nullable();          // [{q, a}, …]
            $table->string('hero_image')->nullable();
            $table->json('proof_project_ids')->nullable();

            // Lifecycle + indexing gate.
            $table->string('status')->default('draft')->index();   // draft | published
            $table->boolean('indexed')->default(true);
            $table->string('source')->default('manual');           // manual | autopilot
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
