<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Bing knows of each sitemap URL (GetUrlInfo: when it found and last
 * fetched it; GetPageStats: whether it showed it in results in the last 28
 * days), one row per URL keyed on url_hash. Filled by SsSystems\Platform\
 * Seo\Bing\BingCoverageSync through App\Support\Seo\BingCoverageWriter;
 * read by BingCoverageController (SsSystems\Platform\Seo\Bing\Http\
 * Concerns\ServesBingCoverage) for the hub's Bing card (kit 0.22.0,
 * 2026-11-10). A URL that left the sitemap keeps its row with
 * in_sitemap = false — never deleted.
 *
 * Copied from vendor/ss-systems/platform-kit/resources/stubs/seo/
 * create_bing_url_info_table.php.stub with its multi-tenant note applied:
 * gs.construction is multi-tenant (see App\Models\BingTrafficStat,
 * App\Models\GscCoverageState), so site_id is added and folded into the
 * unique index — same convention as 2026_07_30_200000_add_site_id_to_seo_
 * and_analytics_tables.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bing_url_info', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('url', 500);
            $table->char('url_hash', 40);
            $table->boolean('in_sitemap')->default(true)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('is_page')->nullable();
            $table->unsignedInteger('document_size')->nullable();
            $table->unsignedInteger('anchor_count')->nullable();
            $table->unsignedInteger('child_url_count')->nullable();
            $table->date('discovered_on')->nullable();
            $table->timestamp('last_crawled_at')->nullable()->index();
            $table->unsignedInteger('impressions_28d')->default(0);
            $table->unsignedInteger('clicks_28d')->default(0);
            $table->date('last_shown_on')->nullable();
            $table->timestamp('inspected_at')->nullable()->index();
            $table->string('inspect_error')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'url_hash'], 'bing_url_info_site_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_url_info');
    }
};
