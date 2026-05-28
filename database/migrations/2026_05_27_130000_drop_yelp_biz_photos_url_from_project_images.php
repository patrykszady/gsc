<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Drop the `yelp_biz_photos_url` column.
     *
     * It stored the business's owner-only admin gallery URL
     * (https://biz.yelp.com/biz_photos/{biz_id}), which is:
     *   - identical for every project image (constant per business),
     *   - not visitor-facing (biz.yelp.com requires Yelp owner login),
     *   - redundant — the public per-photo URL is derived deterministically
     *     in the blade from config('services.yelp.public_biz_slug') +
     *     `yelp_biz_photo_id` (https://www.yelp.com/biz_photos/{slug}?select={id}).
     */
    public function up(): void
    {
        if (Schema::hasColumn('project_images', 'yelp_biz_photos_url')) {
            Schema::table('project_images', function (Blueprint $table) {
                $table->dropColumn('yelp_biz_photos_url');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('project_images', 'yelp_biz_photos_url')) {
            Schema::table('project_images', function (Blueprint $table) {
                $table->string('yelp_biz_photos_url')->nullable()->after('yelp_biz_uploaded_at');
            });
        }
    }
};
