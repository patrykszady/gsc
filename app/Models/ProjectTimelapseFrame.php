<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProjectTimelapseFrame extends Model
{
    protected $fillable = [
        'project_timelapse_id',
        'filename',
        'original_filename',
        'path',
        'disk',
        'sort_order',
    ];

    public function timelapse(): BelongsTo
    {
        return $this->belongsTo(ProjectTimelapse::class, 'project_timelapse_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $frame) {
            Storage::disk($frame->disk)->delete($frame->path);
        });
    }
}
