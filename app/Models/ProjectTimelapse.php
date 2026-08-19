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

    /** Management-API shape — see Project::toApiArray(). gsc-only (behind the 'timelapses' ping capability). */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'display_mode' => $this->display_mode,
            'sort_order' => (int) $this->sort_order,
            'frames' => ($this->relationLoaded('frames') ? $this->frames : $this->frames()->get())
                ->map(fn (ProjectTimelapseFrame $frame) => $frame->toApiArray())
                ->values()
                ->all(),
        ];
    }
}
