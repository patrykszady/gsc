<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Bing Webmaster Tools: daily rank/traffic snapshot per query.
        // Uses a SHA1 `dim_hash` for the unique constraint so long queries
        // don't blow MySQL's 3072-byte unique-index limit.
        Schema::create('bing_traffic_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site_url', 191);
            $table->string('query', 500);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->char('dim_hash', 40);
            $table->timestamps();

            $table->unique('dim_hash', 'bing_dim_hash_unique');
            $table->index(['site_url', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_traffic_stats');
    }
};
