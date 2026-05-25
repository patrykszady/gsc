<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            // Mirrors yelp_photo_id / yelp_uploaded_at, but for the
            // account-wide Business Photos gallery (biz.yelp.com/biz_photos)
            // rather than per-project Portfolio uploads.
            $table->string('yelp_biz_photo_id')->nullable()->after('yelp_uploaded_at');
            $table->timestamp('yelp_biz_uploaded_at')->nullable()->after('yelp_biz_photo_id');
            $table->string('yelp_biz_photos_url')->nullable()->after('yelp_biz_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->dropColumn(['yelp_biz_photo_id', 'yelp_biz_uploaded_at', 'yelp_biz_photos_url']);
        });
    }
};
