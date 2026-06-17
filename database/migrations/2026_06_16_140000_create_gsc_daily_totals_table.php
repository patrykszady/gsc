<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gsc_daily_totals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site_url', 191);
            // True site-wide totals from a date-dimension-only query. These
            // include clicks/impressions from anonymized queries that the
            // query-dimension sync (gsc_query_metrics) silently drops.
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 5)->default(0);
            $table->decimal('position', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['date', 'site_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_daily_totals');
    }
};
