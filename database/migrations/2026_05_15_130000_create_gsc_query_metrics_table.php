<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Daily query × page × country × device rows from the Search Console
        // Search Analytics API. One row per dimension combo per date.
        //
        // GSC queries and URLs can be very long, so the natural unique key
        // (date,site_url,query,page,country,device) blows past MySQL's 3072-byte
        // index limit on utf8mb4. We instead store a deterministic SHA1 of the
        // dimensions in `dim_hash` and make THAT unique. The sync command
        // computes the hash on every upsert.
        Schema::create('gsc_query_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site_url', 191);
            $table->string('query', 500);
            $table->string('page', 500);
            $table->string('country', 8)->nullable();
            $table->string('device', 16)->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->char('dim_hash', 40);
            $table->timestamps();

            $table->unique('dim_hash', 'gsc_dim_hash_unique');
            $table->index(['date', 'site_url'], 'gsc_date_site_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_query_metrics');
    }
};
