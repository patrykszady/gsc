<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project areas (adopted from jpeterson-design via ss-platform-kit 0.16.0):
 * a merged-away project's slug now redirects into a SECTION of the target's
 * page, not just the target itself, and an admin-API request for the
 * merged-away project's numeric id should answer "moved to" rather than a
 * bare 404. `project_slug_history` already remembers every slug a project
 * has ever had (see create_project_slug_history_table); this adds the two
 * columns a merge needs on top of that:
 *
 * - `anchor`: the target page's section id to jump to (e.g. "area-kitchen"),
 *   set only by `php artisan projects:merge` — a plain rename never has one.
 * - `source_project_id`: the numeric id of the project that no longer
 *   exists once merged away, so GscSlugRedirectRecorder::
 *   findBySourceProjectId() can answer an admin-API "not found" with where
 *   it went (see MergedProjectRedirectResponse, wired in bootstrap/app.php).
 *   Indexed, not a foreign key: the project it names is gone by the time
 *   anyone looks this column up.
 *
 * See SsSystems\Platform\Projects\Contracts\RecordsProjectSlugRedirect's
 * docblock (ss-platform-kit) for why gs.construction's own
 * repointRedirects() cannot be a no-op the way jpeterson-design's is: unlike
 * that site's `project_slug_redirects` (which only ever points at the
 * current project), every row here is a foreign key TO a project and
 * cascades away with it — so a merged-away source's whole address history
 * must be re-pointed at the target before the source row is deleted, or it
 * is lost rather than just its most recent slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_slug_history', function (Blueprint $table) {
            if (! Schema::hasColumn('project_slug_history', 'anchor')) {
                $table->string('anchor')->nullable()->after('project_id');
            }
            if (! Schema::hasColumn('project_slug_history', 'source_project_id')) {
                $table->unsignedBigInteger('source_project_id')->nullable()->after('anchor')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_slug_history', function (Blueprint $table) {
            if (Schema::hasColumn('project_slug_history', 'source_project_id')) {
                $table->dropIndex(['source_project_id']);
                $table->dropColumn('source_project_id');
            }
            if (Schema::hasColumn('project_slug_history', 'anchor')) {
                $table->dropColumn('anchor');
            }
        });
    }
};
