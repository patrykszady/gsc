<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic mirror for every Local Falcon report FAMILY the paid tier
        // exposes beyond plain scans (which keep their richer table):
        // trend, competitor, keyword, location, campaign, guard, reviews.
        // One row per report; family+key unique; full payload archived.
        Schema::create('local_falcon_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('family', 32)->index();
            $table->string('report_key', 64);
            $table->string('keyword')->nullable();
            $table->timestamp('reported_at')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['family', 'report_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_falcon_reports');
    }
};
