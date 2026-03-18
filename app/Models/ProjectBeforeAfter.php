<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProjectBeforeAfter extends Model
{
    protected $fillable = [
        'project_id',
        'title',
        'before_path',
        'after_path',
        'disk',
        'sort_order',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function getBeforeUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->before_path);
    }

    public function getAfterUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->after_path);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $model) {
            $disk = Storage::disk($model->disk);
            if ($disk->exists($model->before_path)) {
                $disk->delete($model->before_path);
            }
            if ($disk->exists($model->after_path)) {
                $disk->delete($model->after_path);
            }
        });
    }
}
