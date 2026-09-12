<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a coverage row came from: 'sitemap' (the nightly sweep), 'console'
 * (a URL from Search Console's Page indexing export, inspected on import)
 * or 'tracked' (a URL Googlebot hit that we track ourselves). Only sitemap
 * rows are pruned when they leave the sitemap — the others were never in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsc_coverage_states', function (Blueprint $table) {
            $table->string('source', 20)->default('sitemap')->after('url')->index();
            $table->string('console_reason')->nullable()->after('coverage_state');
        });
    }

    public function down(): void
    {
        Schema::table('gsc_coverage_states', function (Blueprint $table) {
            $table->dropColumn(['source', 'console_reason']);
        });
    }
};
