<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The keyword universe: every phrase we know about (Search Console, generated
 * town × service phrases, competitors' ranked keywords, keyword ideas) with
 * its search volume, where we stand and where the competitors stand. Fed
 * weekly by seo:keyword-research; read by the autopilot and the SEO page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('keyword', 191);
            $table->unsignedInteger('volume')->nullable();
            $table->decimal('cpc', 8, 2)->nullable();
            $table->decimal('competition', 4, 2)->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->string('service', 40)->nullable()->index();
            $table->string('city', 80)->nullable()->index();
            $table->string('modifier', 30)->nullable();
            $table->json('sources')->nullable();
            $table->json('competitor_domains')->nullable();
            $table->unsignedSmallInteger('competitor_best_position')->nullable();
            $table->decimal('our_position', 6, 1)->nullable();
            $table->unsignedInteger('our_impressions')->nullable();
            $table->unsignedInteger('our_clicks')->nullable();
            $table->decimal('opportunity', 10, 2)->default(0)->index();
            $table->timestamp('researched_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keywords');
    }
};
