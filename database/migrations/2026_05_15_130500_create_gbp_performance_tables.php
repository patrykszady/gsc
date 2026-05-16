<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // GBP Performance API: daily metric values per location.
        // Metrics: WEBSITE_CLICKS, CALL_CLICKS, BUSINESS_DIRECTION_REQUESTS,
        // BUSINESS_IMPRESSIONS_DESKTOP_MAPS, BUSINESS_IMPRESSIONS_DESKTOP_SEARCH,
        // BUSINESS_IMPRESSIONS_MOBILE_MAPS, BUSINESS_IMPRESSIONS_MOBILE_SEARCH,
        // BUSINESS_CONVERSATIONS, BUSINESS_BOOKINGS, BUSINESS_FOOD_ORDERS, etc.
        Schema::create('gbp_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('location_id', 64);
            $table->string('metric', 64);
            $table->unsignedInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['date', 'location_id', 'metric'], 'gbp_metrics_unique');
            $table->index(['location_id', 'date']);
        });

        // GBP Performance API: monthly search keyword impressions.
        Schema::create('gbp_search_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('location_id', 64);
            $table->string('keyword', 255);
            $table->unsignedInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('impressions')->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'keyword', 'year', 'month'], 'gbp_keywords_unique');
            $table->index(['location_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_search_keywords');
        Schema::dropIfExists('gbp_daily_metrics');
    }
};
