<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Tracks GSC URL Inspection results over time so we can see when a
        // page moved between coverage states (e.g. "Crawled - currently not
        // indexed" → "Submitted and indexed") and trigger re-crawl signals
        // when it regresses. One row per URL; rewritten on every inspection.
        // Historical snapshots are appended to gsc_coverage_state_history.
        Schema::create('gsc_coverage_states', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500);
            $table->string('verdict', 32)->nullable();          // PASS | PARTIAL | FAIL | NEUTRAL
            $table->string('coverage_state', 191)->nullable();   // "Submitted and indexed" etc.
            $table->string('robots_txt_state', 32)->nullable();  // ALLOWED | DISALLOWED
            $table->string('indexing_state', 32)->nullable();    // INDEXING_ALLOWED | BLOCKED_BY_META_TAG | ...
            $table->string('page_fetch_state', 32)->nullable();  // SUCCESSFUL | SOFT_404 | ACCESS_DENIED | NOT_FOUND | ...
            $table->string('sitemap_url', 500)->nullable();
            $table->timestamp('last_crawl_time')->nullable();
            $table->string('user_canonical', 500)->nullable();
            $table->string('google_canonical', 500)->nullable();
            $table->timestamp('inspected_at');
            $table->timestamp('last_changed_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->unique('url', 'gsc_coverage_url_unique');
            $table->index(['verdict', 'coverage_state'], 'gsc_coverage_state_idx');
            $table->index('inspected_at', 'gsc_coverage_inspected_idx');
        });

        Schema::create('gsc_coverage_state_history', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500);
            $table->string('verdict', 32)->nullable();
            $table->string('coverage_state', 191)->nullable();
            $table->string('page_fetch_state', 32)->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['url', 'observed_at'], 'gsc_coverage_hist_url_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_coverage_state_history');
        Schema::dropIfExists('gsc_coverage_states');
    }
};
