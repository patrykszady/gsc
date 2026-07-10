<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL-path-keyed title/description overrides applied at the very top of the SEO
 * precedence chain (above the programmatic SeoService/SEOBuilder title).
 *
 * Area pages set their <title> through SeoService::setTags() without binding a
 * model, so the polymorphic `seo` (SeoOverride) row is never consulted for them.
 * This table lets the Autopilot (and, in future, the admin) override ANY URL's
 * title/meta uniformly and reversibly — deleting the row restores the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_path_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique(); // normalized request path, home = '/'
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('source')->default('autopilot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_path_overrides');
    }
};
