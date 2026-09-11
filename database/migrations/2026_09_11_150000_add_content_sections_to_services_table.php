<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page copy for the admin-managed services list, same backbone as
 * AreaServed's content sections (2026-09-11) and jpeterson-design's
 * identical migration on its own services table — see App\Models\Service.
 *
 * `sections` is the show/hide map ({intro: true, faq: false, …}): an
 * absent key means shown when that section has content — see
 * Service::SECTIONS and Service::showsSection().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->text('intro')->nullable()->after('blurb');
            $table->text('what_we_do')->nullable()->after('intro');
            $table->text('ideal_for')->nullable()->after('what_we_do');
            $table->json('faq')->nullable()->after('ideal_for');
            $table->json('sections')->nullable()->after('faq');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['intro', 'what_we_do', 'ideal_for', 'faq', 'sections']);
        });
    }
};
