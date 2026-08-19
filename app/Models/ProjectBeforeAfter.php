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

    /**
     * Nullable, unlike the column: the central admin's REST-driven form
     * creates a before/after pair immediately (empty slots) rather than
     * only once both images exist — see ProjectController@store in
     * BeforeAfterController's docblock. before_path/after_path start as ''
     * (still satisfies the NOT NULL column) until a slot is filled.
     */
    public function getBeforeUrlAttribute(): ?string
    {
        return $this->before_path ? Storage::disk($this->disk)->url($this->before_path) : null;
    }

    public function getAfterUrlAttribute(): ?string
    {
        return $this->after_path ? Storage::disk($this->disk)->url($this->after_path) : null;
    }

    protected static function booted(): void
    {
        static::deleting(function (self $model) {
            $disk = Storage::disk($model->disk);
            if ($model->before_path && $disk->exists($model->before_path)) {
                $disk->delete($model->before_path);
            }
            if ($model->after_path && $disk->exists($model->after_path)) {
                $disk->delete($model->after_path);
            }
        });
    }

    /** Management-API shape — see Project::toApiArray(). gsc-only (behind the 'before-afters' ping capability). */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'before_url' => $this->before_url,
            'after_url' => $this->after_url,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
