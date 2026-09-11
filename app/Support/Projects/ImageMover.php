<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Move photos from one project to another — an existing project, or a
 * new draft made on the spot (photos from one shoot that turn out to be
 * two projects). The files move with the rows into the target project's
 * folder (originals and renditions both live under projects/{id}/), the
 * moved photos go to the end of the target's order, and each side ends
 * up with a cover: the target's first photo if it had none, the source's
 * first remaining photo if its cover left.
 *
 * Same class on jpeterson-design — the two backends stay in step.
 */
class ImageMover
{
    /**
     * @param  array<int, int>  $imageIds  photos of $source to move
     * @param  Project|null  $target  an existing project, or null to create a draft
     * @param  string|null  $title  the draft's title (null: the photos-first placeholder)
     * @return array{target: Project, created: bool, moved: int}
     */
    public static function move(Project $source, array $imageIds, ?Project $target = null, ?string $title = null): array
    {
        $images = $source->images()->whereIn('id', $imageIds)->orderBy('sort_order')->orderBy('id')->get();

        if ($images->count() !== count(array_unique($imageIds))) {
            throw ValidationException::withMessages(['image_ids' => 'Every photo to move must belong to this project.']);
        }

        if ($target && (int) $target->id === (int) $source->id) {
            throw ValidationException::withMessages(['project_id' => 'Pick a different project to move the photos to.']);
        }

        $created = false;
        if (! $target) {
            $target = Project::create([
                'title' => filled($title) ? trim($title) : 'New project',
                'is_published' => false,
                'is_featured' => false,
                // The same shoot, so the same facts until someone says otherwise.
                'project_type' => $source->project_type,
                'location' => $source->location,
                'completed_at' => $source->completed_at,
            ]);
            $created = true;
        }

        $targetHadImages = $target->images()->exists();
        $sourceCoverMoved = $images->contains(fn (ProjectImage $image) => $image->is_cover);
        $next = (int) $target->images()->max('sort_order') + 1;

        foreach ($images as $image) {
            static::relocateFiles($image, $target);
            $image->forceFill([
                'project_id' => $target->id,
                'is_cover' => false,
                'sort_order' => $next++,
            ])->save();
        }

        if (! $targetHadImages) {
            $images->first()?->forceFill(['is_cover' => true])->save();
        }

        if ($sourceCoverMoved) {
            $source->images()->orderBy('sort_order')->orderBy('id')->first()?->forceFill(['is_cover' => true])->save();
        }

        return ['target' => $target, 'created' => $created, 'moved' => $images->count()];
    }

    /** Carry the original and every rendition into the target's folder, keeping names unless one is taken. */
    protected static function relocateFiles(ProjectImage $image, Project $target): void
    {
        $disk = Storage::disk($image->disk ?? 'public');
        $from = 'projects/'.$image->project_id.'/';
        $to = 'projects/'.$target->id.'/';

        $suffix = '';
        $newPath = $to.basename($image->path);
        if ($disk->exists($newPath)) {
            $suffix = '-m'.$image->id;
            $newPath = $to.static::withSuffix(basename($image->path), $suffix);
        }

        if ($disk->exists($image->path)) {
            $disk->move($image->path, $newPath);
        }

        $thumbnails = [];
        foreach ($image->thumbnails ?? [] as $key => $path) {
            $relative = str_starts_with($path, $from) ? substr($path, strlen($from)) : basename($path);
            $dir = dirname($relative) === '.' ? '' : dirname($relative).'/';
            $newThumb = $to.$dir.static::withSuffix(basename($relative), $suffix);
            if ($disk->exists($path)) {
                $disk->move($path, $newThumb);
            }
            $thumbnails[$key] = $newThumb;
        }

        $image->forceFill([
            'path' => $newPath,
            'filename' => basename($newPath),
            'thumbnails' => $thumbnails ?: $image->thumbnails,
        ]);
    }

    protected static function withSuffix(string $filename, string $suffix): string
    {
        if ($suffix === '') {
            return $filename;
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        return $ext !== '' ? "{$name}{$suffix}.{$ext}" : "{$name}{$suffix}";
    }
}
