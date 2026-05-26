<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            // Persist the 140-char Gemini SEO caption that was actually
            // uploaded to Yelp's business gallery. Source of truth for
            // audit/admin display; regenerated on --force re-syncs.
            $table->text('yelp_biz_caption')->nullable()->after('yelp_biz_photos_url');
        });
    }

    public function down(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->dropColumn('yelp_biz_caption');
        });
    }
};
