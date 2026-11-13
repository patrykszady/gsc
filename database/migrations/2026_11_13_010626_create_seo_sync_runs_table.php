<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where this site keeps the SEO data-status heartbeats (kit 0.28.0,
 * 2026-11-12): one row per tenant and data source (`search_console`,
 * `bing`, `snapshot`, …) holding the summary SsSystems\Platform\Seo\Status\
 * Heartbeat writes after every collector run and `GET seo/status` reads
 * back, through App\Support\Seo\TenantHeartbeatStore (App\Models\SeoSyncRun).
 *
 * The kit's create_seo_sync_runs_table.php.stub with its multi-tenant note
 * applied, the same convention as 2026_11_10_170100_create_bing_url_info_
 * table.php: `site_id` is added and `sync_key` is unique per tenant rather
 * than globally. The other five sites already had this table for their
 * Search Console bookkeeping; this one keeps that on oauth_tokens.metadata
 * and never needed it until every collector started reporting in.
 *
 * `site_id` is NULL on exactly one kind of row: a key every tenant shares.
 * Today that is `scheduler` alone — the scheduler's own beat, written by
 * `schedule:run` where no tenant is bound. The unique index does not hold
 * NULLs apart on MySQL, so the store reads the newest of such rows should a
 * second one ever be written.
 *
 * No separate index on `site_id`: every read names the tenant and the key,
 * which the unique index answers from its leftmost columns.
 *
 * Safe to run again after a partial failure (MySQL commits the CREATE
 * before the index is added): each step is skipped when already done.
 */
return new class extends Migration
{
    private const TABLE = 'seo_sync_runs';

    private const UNIQUE_INDEX = 'seo_sync_runs_site_id_sync_key_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->nullable();
                $table->string('sync_key', 191);
                $table->json('summary')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['site_id', 'sync_key'], self::UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
