<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_urls', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('url');
            $table->index(['platform', 'external_id']);
        });

        // Backfill Google review IDs from testimonials.google_review_id into review_urls.external_id.
        $rows = DB::table('testimonials')
            ->whereNotNull('google_review_id')
            ->where('google_review_id', '!=', '')
            ->get(['id', 'google_review_id']);

        foreach ($rows as $row) {
            $googleUrl = DB::table('review_urls')
                ->where('testimonial_id', $row->id)
                ->where('platform', 'google')
                ->first();

            $fallbackUrl = 'https://www.google.com/maps/reviews?reviewid='.$row->google_review_id;

            if ($googleUrl) {
                DB::table('review_urls')
                    ->where('id', $googleUrl->id)
                    ->update([
                        'external_id' => $row->google_review_id,
                        'url' => $googleUrl->url ?: $fallbackUrl,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('review_urls')->insert([
                    'testimonial_id' => $row->id,
                    'platform' => 'google',
                    'url' => $fallbackUrl,
                    'external_id' => $row->google_review_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropColumn('google_review_id');
        });
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->string('google_review_id')->nullable()->unique()->after('review_image');
        });

        $googleRows = DB::table('review_urls')
            ->where('platform', 'google')
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->get(['testimonial_id', 'external_id']);

        foreach ($googleRows as $row) {
            DB::table('testimonials')
                ->where('id', $row->testimonial_id)
                ->update(['google_review_id' => $row->external_id]);
        }

        Schema::table('review_urls', function (Blueprint $table) {
            $table->dropIndex(['platform', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
