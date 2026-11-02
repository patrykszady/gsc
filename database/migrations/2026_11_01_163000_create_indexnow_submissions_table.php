<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table SsSystems\Platform\Seo\IndexNow\IndexNowLedger reads and writes
 * (kit 0.19.4, copied from vendor/ss-systems/platform-kit/resources/stubs/
 * seo/create_indexnow_submissions_table.php.stub). One row per URL ever
 * submitted to IndexNow (Bing/Yandex/Seznam/Naver): the `lastmod` the
 * sitemap reported at submission time, so the hourly `seo:indexnow` sweep
 * only resends a URL that is new or has changed since — `seo:indexnow --all`
 * (the deploy line) bypasses this table entirely and resubmits everything.
 *
 * site_id nullable/indexed and part of the unique key: this app is
 * multi-tenant, so App\Providers\AppServiceProvider::registerSeoTools()
 * binds IndexNowLedger with tenantColumn: 'site_id', the same convention as
 * every other kit store there (RankSnapshotStore, MapPackStore, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indexnow_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('url', 500);
            // ISO 8601, kept as the raw sitemap string rather than parsed —
            // IndexNowLedger::due() compares it with strtotime() at read
            // time, so no information is lost re-encoding it on the way in.
            $table->string('lastmod', 40)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->string('last_status', 191)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexnow_submissions');
    }
};
