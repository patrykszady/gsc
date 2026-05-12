<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->string('yelp_photo_id')->nullable()->after('google_places_uploaded_at');
            $table->timestamp('yelp_uploaded_at')->nullable()->after('yelp_photo_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            // Edit URL of the Yelp Portfolio Project on biz.yelp.com,
            // e.g. https://biz.yelp.com/portfolio/<biz>/<project>/edit
            $table->string('yelp_portfolio_url')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->dropColumn(['yelp_photo_id', 'yelp_uploaded_at']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['yelp_portfolio_url']);
        });
    }
};
