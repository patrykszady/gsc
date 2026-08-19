<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProjectImageController extends Controller
{
    use BuildsApiResponses;

    public function index(int $project): JsonResponse
    {
        $project = Project::findOrFail($project);

        return response()->json([
            'data' => $project->images()->with('tags')->get()->map(fn (ProjectImage $image) => $image->toApiArray())->all(),
        ]);
    }

    public function store(Request $request, int $project): JsonResponse
    {
        $project = Project::findOrFail($project);

        $data = $request->validate([
            'image' => ['required', 'image', 'max:51200', 'mimes:jpg,jpeg,png,webp'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'is_cover' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $file = $request->file('image');
        $path = $file->store('projects/'.$project->id, 'public');
        $dimensions = @getimagesize($file->getRealPath());

        $isCover = (bool) ($data['is_cover'] ?? false);

        $image = $project->images()->create([
            'filename' => basename($path),
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            // No 'disk' column on this app's project_images — the public
            // disk is implicit (ProjectImage::getUrlAttribute hardcodes it).
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
            'caption' => $data['caption'] ?? null,
            'is_cover' => $isCover,
            'sort_order' => $data['sort_order'] ?? ((int) $project->images()->max('sort_order') + 1),
        ]);

        if ($isCover) {
            $this->unsetOtherCovers($project, $image->id);
        }

        return $this->itemResponse($image->fresh()->toApiArray(), 201);
    }

    public function update(Request $request, int $project, int $image): JsonResponse
    {
        $project = Project::findOrFail($project);
        $model = $project->images()->findOrFail($image);

        $data = $request->validate([
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'is_cover' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $model->update($data);

        if ($model->is_cover) {
            $this->unsetOtherCovers($project, $model->id);
        }

        return $this->itemResponse($model->fresh()->toApiArray());
    }

    public function destroy(int $project, int $image): Response
    {
        $project = Project::findOrFail($project);
        $project->images()->findOrFail($image)->delete();

        return response()->noContent();
    }

    public function reorder(Request $request, int $project): JsonResponse
    {
        $project = Project::findOrFail($project);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach (array_values($data['order']) as $position => $imageId) {
            $project->images()->where('id', $imageId)->update(['sort_order' => $position]);
        }

        return response()->json([
            'data' => $project->images()->with('tags')->get()->map(fn (ProjectImage $image) => $image->toApiArray())->all(),
        ]);
    }

    /**
     * Full replace of an image's tag set — gsc-only, behind the 'image-tags'
     * ping capability. A full sync (not attach/toggle) so the central
     * admin's bulk "Assign Tag" action stays simple: it reads each selected
     * image's current tags out of the project payload it already has,
     * unions in the newly-picked tag id client-side, and PUTs the result —
     * same net effect as gsc's own Livewire syncWithoutDetaching(), without
     * needing a second toggle-style endpoint.
     */
    public function syncTags(Request $request, int $project, int $image): JsonResponse
    {
        $project = Project::findOrFail($project);
        $model = $project->images()->findOrFail($image);

        $data = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $model->tags()->sync($data['tag_ids']);

        return $this->itemResponse($model->fresh(['tags'])->toApiArray());
    }

    protected function unsetOtherCovers(Project $project, int $exceptImageId): void
    {
        $project->images()
            ->where('id', '!=', $exceptImageId)
            ->where('is_cover', true)
            ->update(['is_cover' => false]);
    }
}
