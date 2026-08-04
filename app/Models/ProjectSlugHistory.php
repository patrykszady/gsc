<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A slug a project used to answer on.
 *
 * @see database/migrations/2026_08_02_170000_create_project_slug_history_table.php
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
