<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bing_daily_totals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site_url', 191);
            // True site-wide totals from GetRankAndTrafficStats. These include
            // clicks/impressions that the per-query GetQueryStats sync
            // (bing_traffic_stats) silently drops.
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 5)->default(0);
            $table->timestamps();

            $table->unique(['date', 'site_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_daily_totals');
    }
};
