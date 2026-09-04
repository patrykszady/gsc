<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DataForSEO beyond keyword volume: search intent + difficulty on the
 * keyword universe, organic share of voice for us and the competitors,
 * the link gap (domains linking to competitors but not to us), and whether
 * AI answer engines name the business when asked for a contractor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->string('intent', 20)->nullable()->after('difficulty');
            $table->decimal('intent_probability', 4, 2)->nullable()->after('intent');
        });

        Schema::create('seo_domain_overviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('domain', 191);
            $table->boolean('is_us')->default(false);
            $table->date('date');
            $table->unsignedInteger('pos_1')->default(0);
            $table->unsignedInteger('pos_2_3')->default(0);
            $table->unsignedInteger('pos_4_10')->default(0);
            $table->unsignedInteger('pos_11_20')->default(0);
            $table->unsignedInteger('keywords_total')->default(0);
            $table->decimal('etv', 12, 2)->default(0);
            $table->unsignedInteger('is_new')->default(0);
            $table->unsignedInteger('is_lost')->default(0);
            $table->unsignedInteger('backlinks')->nullable();
            $table->unsignedInteger('referring_domains')->nullable();
            $table->unsignedInteger('domain_rank')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'domain', 'date']);
        });

        Schema::create('seo_backlink_prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('domain', 191);
            $table->unsignedInteger('rank')->nullable();
            $table->json('links_to')->nullable();
            $table->unsignedTinyInteger('competitor_count')->default(0);
            $table->boolean('links_to_us')->default(false);
            $table->string('platform_type', 60)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'domain']);
        });

        Schema::create('seo_ai_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('platform', 30);
            $table->string('model', 60)->nullable();
            $table->string('prompt', 255);
            $table->string('town', 80)->nullable();
            $table->string('service', 40)->nullable();
            $table->boolean('mentioned')->default(false);
            $table->unsignedTinyInteger('mention_rank')->nullable();
            $table->json('businesses_named')->nullable();
            $table->text('answer_excerpt')->nullable();
            $table->date('asked_on');
            $table->timestamps();
            $table->index(['site_id', 'asked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ai_mentions');
        Schema::dropIfExists('seo_backlink_prospects');
        Schema::dropIfExists('seo_domain_overviews');
        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->dropColumn(['intent', 'intent_probability']);
        });
    }
};
