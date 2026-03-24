<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('testimonial_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // google, yelp, facebook, etc.
            $table->string('url');
            $table->timestamps();

            $table->unique(['testimonial_id', 'platform']);
        });

        // Migrate existing review_url data
        $rows = DB::table('testimonials')->whereNotNull('review_url')->where('review_url', '!=', '')->get(['id', 'review_url']);
        foreach ($rows as $row) {
            DB::table('review_urls')->insert([
                'testimonial_id' => $row->id,
                'platform' => 'google',
                'url' => $row->review_url,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropColumn('review_url');
        });
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->string('review_url')->nullable()->after('review_date');
        });

        // Migrate back: take first URL per testimonial
        $rows = DB::table('review_urls')->get();
        foreach ($rows as $row) {
            DB::table('testimonials')->where('id', $row->testimonial_id)->update(['review_url' => $row->url]);
        }

        Schema::dropIfExists('review_urls');
    }
};
