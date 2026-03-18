<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTimelapse extends Model
{
    protected $fillable = [
        'project_id',
        'title',
        'display_mode',
        'sort_order',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function frames(): HasMany
    {
        return $this->hasMany(ProjectTimelapseFrame::class)->orderBy('sort_order');
    }
}
