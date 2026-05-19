<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clarity_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('project_id', 64);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->decimal('scroll_depth', 6, 2)->default(0);
            $table->unsignedInteger('active_time_seconds')->default(0);
            $table->decimal('bounce_rate', 6, 4)->default(0);
            $table->unsignedInteger('dead_clicks')->default(0);
            $table->unsignedInteger('rage_clicks')->default(0);
            $table->unsignedInteger('quickbacks')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'date'], 'clarity_project_date_unique');
            $table->index(['date', 'project_id'], 'clarity_date_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarity_daily_metrics');
    }
};
