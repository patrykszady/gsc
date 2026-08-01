<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make per-tenant slugs unique PER TENANT rather than globally.
 *
 * These tables carry site_id and are scoped by BelongsToSite, but their unique
 * indexes were created before multi-tenancy and still span the whole table. The
 * effect is that two tenants can never share a slug: J. Peterson Design cannot
 * add Arlington Heights as a service area because gs.construction already has
 * it, and the insert dies with a 1062 duplicate-key error. Since both serve
 * greater Chicago, that is most of the map.
 *
 * The public URLs are host-scoped (jpeterson-design.com/areas-served/oak-park
 * and gs.construction/areas-served/oak-park are different pages), so a global
 * unique was never the right constraint — only the composite is.
 *
 *   areas_served.slug   -> (site_id, slug)
 *   projects.slug       -> (site_id, slug)
 *   landing_pages.slug  -> (site_id, slug)
 *   short_links.code    -> (site_id, code)
 *
 * short_links is included for consistency, though a shared short code across
 * tenants would resolve by host anyway.
 */
return new class extends Migration
{
    /** @var array<string, array{index: string, column: string}> */
    private array $targets = [
        'areas_served' => ['index' => 'areas_served_slug_unique', 'column' => 'slug'],
        'projects' => ['index' => 'projects_slug_unique', 'column' => 'slug'],
        'landing_pages' => ['index' => 'landing_pages_slug_unique', 'column' => 'slug'],
        'short_links' => ['index' => 'short_links_code_unique', 'column' => 'code'],
    ];

    public function up(): void
    {
        foreach ($this->targets as $table => $meta) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'site_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $meta) {
                // dropUnique tolerates the index being absent on a fresh DB
                // built after this migration, so re-running is safe.
                try {
                    $t->dropUnique($meta['index']);
                } catch (\Throwable) {
                    // already gone
                }

                $t->unique(['site_id', $meta['column']], "{$table}_site_{$meta['column']}_unique");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $table => $meta) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'site_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $meta) {
                try {
                    $t->dropUnique("{$table}_site_{$meta['column']}_unique");
                } catch (\Throwable) {
                    // already gone
                }

                // Only restorable while no two tenants share a slug.
                $t->unique($meta['column'], $meta['index']);
            });
        }
    }
};
