<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local Falcon, second pass: which platform a scan measured (Maps vs the AI
 * answer engines, which report SAIV instead of SoLV), and the businesses
 * that own the map pack at each scan — with what their own homepage says
 * they do and where, read for analysis only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_falcon_scans', function (Blueprint $table) {
            $table->string('platform', 20)->nullable()->after('keyword');
            $table->decimal('saiv', 6, 2)->nullable()->after('solv');
        });

        Schema::create('local_falcon_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('place_id', 64);
            $table->string('keyword', 191);
            $table->string('name');
            $table->string('url', 500)->nullable();
            $table->string('host', 191)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('reviews')->nullable();
            $table->boolean('claimed')->nullable();
            $table->json('categories')->nullable();
            $table->string('scan_id', 64)->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->unsignedSmallInteger('pack_points')->default(0);
            $table->unsignedSmallInteger('seen_points')->default(0);
            $table->unsignedSmallInteger('best_rank')->nullable();
            $table->string('site_title')->nullable();
            $table->string('site_description', 1000)->nullable();
            $table->text('site_excerpt')->nullable();
            $table->json('site_headings')->nullable();
            $table->json('site_towns')->nullable();
            $table->json('site_services')->nullable();
            $table->timestamp('site_fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'place_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_falcon_competitors');
        Schema::table('local_falcon_scans', function (Blueprint $table) {
            $table->dropColumn(['platform', 'saiv']);
        });
    }
};
