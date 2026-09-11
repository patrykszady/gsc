<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More page copy per area, and a per-page switch for each piece. Ported
 * file-for-file from jpeterson-design's identical migration (2026-09-11) —
 * same backbone, see that app's App\Models\Area.
 *
 * intro / local_intro / landmarks / permit_notes already exist on this
 * table; these join them so a city page has enough to say: the
 * neighborhoods we work in, what homeowners there ask for, how a project
 * runs from our office, and a short FAQ. All drafted by
 * GenerateAreaContentJob (via AiContentService::generateAreaContent) on
 * creation, all editable in admin.
 *
 * `sections` is the show/hide map ({intro: true, faq: false, …}): an
 * absent key means shown when that section has content — see
 * AreaServed::SECTIONS and AreaServed::showsSection().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->text('neighborhoods')->nullable()->after('landmarks');
            $table->text('popular_projects')->nullable()->after('neighborhoods');
            $table->text('how_we_work')->nullable()->after('popular_projects');
            $table->json('faq')->nullable()->after('how_we_work');
            $table->json('sections')->nullable()->after('permit_notes');
        });
    }

    public function down(): void
    {
        Schema::table('areas_served', function (Blueprint $table) {
            $table->dropColumn(['neighborhoods', 'popular_projects', 'how_we_work', 'faq', 'sections']);
        });
    }
};
