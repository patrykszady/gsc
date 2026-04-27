<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
        $this->normalizeStorageLocation();

        return Storage::disk($this->disk)->url($this->path);
    }

    public function normalizeStorageLocation(): bool
    {
        $disk = Storage::disk($this->disk);
        $legacyPath = $this->legacyPath();
        $publicExists = $disk->exists($this->path);
        $legacyExists = is_file($legacyPath);

        if (!$publicExists && !$legacyExists) {
            return false;
        }

        if (!$publicExists && $legacyExists) {
            $stream = fopen($legacyPath, 'r');

            if ($stream === false) {
                throw new RuntimeException("Unable to read legacy timelapse frame at [{$legacyPath}].");
            }

            try {
                $disk->writeStream($this->path, $stream);
            } finally {
                fclose($stream);
            }

            $publicExists = true;
        }

        if ($publicExists && $legacyExists) {
            @unlink($legacyPath);
            return true;
        }

        return $publicExists;
    }

    public function legacyPath(): string
    {
        return storage_path('app/' . ltrim($this->path, '/'));
    }

    protected static function booted(): void
    {
        static::deleting(function (self $frame) {
            Storage::disk($frame->disk)->delete($frame->path);

            $legacyPath = $frame->legacyPath();

            if (is_file($legacyPath)) {
                @unlink($legacyPath);
            }
        });
    }
}
