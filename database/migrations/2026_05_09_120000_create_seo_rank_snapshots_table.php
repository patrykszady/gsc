<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seo_rank_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 32);              // gsc (current) | google | google_maps (legacy)
            $table->string('query');                   // search term
            $table->string('location')->nullable();    // legacy location string / "ll" coords
            $table->string('city_slug')->nullable();   // links to areas_served if applicable
            $table->unsignedSmallInteger('gsc_position')->nullable(); // null = not in result set
            $table->string('gsc_match_title')->nullable();            // which listing matched (e.g. "Greg's Bathroom Remodeling Contractors")
            $table->unsignedSmallInteger('result_count')->nullable();
            $table->json('top_results')->nullable();   // array of top N {position,title,link/host,rating,reviews,address}
            $table->json('meta')->nullable();          // raw extras (search_id, related questions, ads count, etc.)
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['engine', 'query', 'location', 'fetched_at'], 'seo_rank_lookup_idx');
            $table->index(['city_slug', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rank_snapshots');
    }
};
