<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A slug a project used to answer on.
 *
 * `anchor` (the target page's section id, e.g. "area-kitchen") and
 * `source_project_id` (the numeric id of a project merged away entirely,
 * see `php artisan projects:merge`) are written only by
 * `App\Support\Projects\GscSlugRedirectRecorder` — see that class and
 * `SsSystems\Platform\Projects\ProjectMerger` (ss-platform-kit 0.16.0).
 *
 * @see database/migrations/2026_08_02_170000_create_project_slug_history_table.php
 * @see database/migrations/2026_10_01_011533_add_anchor_and_source_project_id_to_project_slug_history_table.php
 */
class ProjectSlugHistory extends Model
{
    protected $table = 'project_slug_history';

    protected $guarded = [];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
