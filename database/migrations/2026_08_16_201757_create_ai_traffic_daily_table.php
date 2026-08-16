<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily counters for AI-driven traffic: humans arriving from AI assistants
 * (referral) and AI crawlers fetching pages to build their answers (crawler).
 * One row per site/day/kind/source, incremented by TrackAiTraffic middleware.
 * Exists so GEO work is measured instead of faith-based — nothing on the
 * dashboard could previously say whether ChatGPT/Perplexity ever send anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->date('date');
            $table->string('kind', 16);      // referral | crawler
            $table->string('source', 64);    // chatgpt, perplexity, GPTBot, ClaudeBot, …
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'date', 'kind', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_traffic_daily');
    }
};
