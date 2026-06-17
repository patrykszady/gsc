<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clarity_daily_metrics', function (Blueprint $table) {
            // Clarity reports these but they were previously dropped. Storing
            // them lets seo:clarity-health alert when the JS error rate spikes.
            $table->unsignedInteger('script_errors')->default(0)->after('quickbacks');
            $table->unsignedInteger('error_clicks')->default(0)->after('script_errors');
        });
    }

    public function down(): void
    {
        Schema::table('clarity_daily_metrics', function (Blueprint $table) {
            $table->dropColumn(['script_errors', 'error_clicks']);
        });
    }
};
