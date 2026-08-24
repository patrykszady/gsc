<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_traffic_daily', function (Blueprint $table): void {
            // WHICH pages AI assistants cite and crawl — the one GEO metric no
            // vendor can sell us, since only the origin sees its own referrers.
            // Nullable: pre-existing rows and the total row keep path NULL.
            $table->string('path', 191)->nullable()->after('source');
        });

        // The unique key must include path now that one (site,date,kind,source)
        // can have many per-path rows.
        Schema::table('ai_traffic_daily', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'date', 'kind', 'source']);
            $table->unique(['site_id', 'date', 'kind', 'source', 'path'], 'ai_traffic_daily_dim_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ai_traffic_daily', function (Blueprint $table): void {
            $table->dropUnique('ai_traffic_daily_dim_unique');
            $table->unique(['site_id', 'date', 'kind', 'source']);
            $table->dropColumn('path');
        });
    }
};
