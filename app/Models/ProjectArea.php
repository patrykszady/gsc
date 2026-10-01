<?php

namespace App\Models;

use App\Jobs\GenerateAreaDescriptionJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SsSystems\Platform\Projects\Concerns\IsProjectArea;
use SsSystems\Platform\Projects\Support\ProjectTypeLabels;

/**
 * One area of a Project — a real job split into rooms/categories (Kitchen,
 * Mudroom, Basement…), each with its own category (project_type), optional
 * heading and description, and its own photos. See
 * Project::scopeOfType()/belongsToType() (SsSystems\Platform\Projects\
 * Concerns\HasProjectAreas): a project belongs to its own project_type AND
 * every one of its areas' project_type.
 *
 * `cover()`/`anchor()`/`heading()`/`typeLabel()`/`clearStaleCovers()`/
 * `toApiArray()` all live in the kit (ss-platform-kit 0.16.0, see
 * IsProjectArea) — this class keeps only its own relations and the two
 * hooks that trait needs. Ported from jpeterson-design.com (2026-09-30);
 * adopted here so gs.construction runs the same admin code as every other
 * ss.systems tenant. No site_id: this table is always reached through a
 * `BelongsToSite`-scoped Project (`$project->areas()`), per gsc's own
 * child-row convention (see database/migrations/
 * 2026_07_30_190000_add_site_id_to_content_tables.php) — never query it by
 * a bare id from outside a scoped project.
 *
 * Contract with ss-systems' admin half: ss-platform-kit's
 * docs/PROJECT-AREAS.md and the project-areas admin-API contract — the
 * shape there, Project::toApiArray()'s "areas" key and
 * ProjectController@update's "areas" handling must not drift from it
 * without updating both sides.
 */
class ProjectArea extends Model
{
    use IsProjectArea;

    protected $fillable = [
        'project_id',
        'project_type',
        'title',
        'description',
        'sort_order',
        'cover_image_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProjectImage::class)->orderBy('sort_order');
    }

    /** The admin-chosen cover image, when it still belongs to this area. See IsProjectArea::cover(). */
    public function coverImage(): BelongsTo
    {
        return $this->belongsTo(ProjectImage::class, 'cover_image_id');
    }

    /** @see IsProjectArea */
    protected function projectTypeLabel(string $type): string
    {
        return Project::projectTypes()[$type] ?? ProjectTypeLabels::fallback($type);
    }

    /** @see IsProjectArea */
    protected function descriptionStatus(): array
    {
        return GenerateAreaDescriptionJob::status($this);
    }
}
