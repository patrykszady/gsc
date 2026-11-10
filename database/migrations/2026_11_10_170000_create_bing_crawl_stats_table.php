<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bing Webmaster Tools crawl stats, one row per day (GetCrawlStats): pages
 * Bing crawled, pages in its index, the errors and status codes it met.
 * Filled by SsSystems\Platform\Seo\Bing\BingCoverageSync through
 * App\Support\Seo\BingCoverageWriter; read by BingCoverageController
 * (SsSystems\Platform\Seo\Bing\Http\Concerns\ServesBingCoverage) for the
 * hub's Bing card (kit 0.22.0, 2026-11-10).
 *
 * Copied from vendor/ss-systems/platform-kit/resources/stubs/seo/
 * create_bing_crawl_stats_table.php.stub with its multi-tenant note
 * applied: gs.construction is multi-tenant (see App\Models\BingTrafficStat,
 * App\Models\GscCoverageState), so site_id is added and folded into the
 * unique index — same convention as 2026_07_30_200000_add_site_id_to_seo_
 * and_analytics_tables.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bing_crawl_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->date('date');
            $table->string('site_url', 191);
            $table->unsignedInteger('crawled_pages')->default(0);
            $table->unsignedInteger('in_index')->default(0);
            $table->unsignedInteger('in_links')->default(0);
            $table->unsignedInteger('crawl_errors')->default(0);
            $table->unsignedInteger('blocked_by_robots')->default(0);
            $table->unsignedInteger('code_2xx')->default(0);
            $table->unsignedInteger('code_301')->default(0);
            $table->unsignedInteger('code_302')->default(0);
            $table->unsignedInteger('code_4xx')->default(0);
            $table->unsignedInteger('code_5xx')->default(0);
            $table->unsignedInteger('other_codes')->default(0);
            $table->unsignedInteger('connection_timeouts')->default(0);
            $table->unsignedInteger('dns_failures')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'date', 'site_url'], 'bing_crawl_stats_site_date_url_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_crawl_stats');
    }
};
