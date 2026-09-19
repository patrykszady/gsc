<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-page behaviour from Microsoft Clarity, one row per path per day.
 *
 * clarity_daily_metrics is unique on (project, date): site-wide totals, no
 * page. That could say "frustration is up 40% this week" and never which
 * page — a tile to glance at, not a thing to act on. This table is the same
 * signals broken out by URL, which is what lets the recommendation engine
 * name the page and cross it with what search already sends there.
 *
 * `path` is the normalised path, never the full URL: the Clarity project
 * sees every host that carries its tag (including local dev), so the sync
 * keeps only the site's own hosts and folds http/https/www/trailing-slash
 * variants together before writing. `path_hash` keeps the unique index
 * inside MySQL's key-length limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarity_page_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('project_id', 64);
            $table->date('date');
            $table->string('path', 500);
            $table->char('path_hash', 40);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('rage_clicks')->default(0);
            $table->unsignedInteger('dead_clicks')->default(0);
            $table->unsignedInteger('quickbacks')->default(0);
            $table->unsignedInteger('script_errors')->default(0);
            $table->decimal('scroll_depth', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'project_id', 'date', 'path_hash'], 'clarity_page_site_project_date_path_unique');
            $table->index(['site_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarity_page_metrics');
    }
};
