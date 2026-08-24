<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily clicks/impressions split by GSC searchAppearance (AI Overview,
        // review snippet, FAQ rich result, …). AI Overviews now appear on
        // roughly half of Google queries; without this dimension the dashboard
        // cannot tell whether thin non-brand CTR is an AI-Overview effect or a
        // snippet problem.
        Schema::create('gsc_search_appearance_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->date('date');
            $table->string('appearance', 64);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 5)->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'date', 'appearance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_search_appearance_metrics');
    }
};
