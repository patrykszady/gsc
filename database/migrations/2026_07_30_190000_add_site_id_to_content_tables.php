<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 wave 1: scope public content to a Site.
     *
     * Only "root" entities get a site_id. Child rows (project_before_afters,
     * project_timelapses, project_timelapse_frames, and the pivots) are always
     * reached through a scoped parent, so a column there would be redundant
     * state that can drift out of sync.
     *
     * project_images is included despite having a parent, because it is bound
     * directly by slug in a public route (/project-images/{slug}) and slugs are
     * globally unique — without its own scope one site could serve another's
     * image page.
     *
     * Column is nullable so this migration cannot fail on existing rows; the
     * BelongsToSite trait always populates it going forward.
     */
    protected array $tables = [
        'projects',
        'project_images',
        'testimonials',
        'areas_served',
        'landing_pages',
        'tags',
        'contact_submissions',
        'review_urls',
        'short_links',
    ];

    public function up(): void
    {
        $defaultId = (int) (DB::table('sites')->where('slug', config('sites.default', 'gsc'))->value('id') ?? 1);

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'site_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('site_id')->nullable()->after('id')->index()
                    ->constrained('sites')->nullOnDelete();
            });

            // Everything that exists today belongs to the founding site.
            DB::table($table)->whereNull('site_id')->update(['site_id' => $defaultId]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'site_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('site_id');
            });
        }
    }
};
