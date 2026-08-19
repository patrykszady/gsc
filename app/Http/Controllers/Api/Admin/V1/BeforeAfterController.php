<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectBeforeAfter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

/**
 * gsc-only (behind the 'before-afters' ping capability): CRUD for a
 * project's before/after comparison pairs, plus filling each side's image
 * either by direct upload or by picking an existing project gallery image.
 * Ported from app/Livewire/Admin/ProjectForm.php's before/after methods.
 *
 * Deviation from the legacy Livewire flow: that component only ever
 * INSERTs a project_before_afters row once both slots have an image ready
 * (it builds the whole thing in memory across one form submit). This
 * controller creates the row immediately on store() with empty slots —
 * before_path/after_path start as '' (the column is NOT NULL, no
 * migration needed) — because the central admin drives each action as its
 * own request rather than batching everything into one save(). A pair
 * with an empty slot serializes that slot's *_url as null (see
 * ProjectBeforeAfter::getBeforeUrlAttribute/getAfterUrlAttribute) so the
 * UI can render an upload placeholder for it, same as a freshly-added
 * timelapse with no frames yet.
 */
class BeforeAfterController extends Controller
{
    use BuildsApiResponses;

    public function index(int $project): JsonResponse
    {
        $project = Project::findOrFail($project);

        return response()->json([
            'data' => $project->beforeAfters()->get()->map(fn (ProjectBeforeAfter $ba) => $ba->toApiArray())->all(),
        ]);
    }

    public function store(Request $request, int $project): JsonResponse
    {
        $project = Project::findOrFail($project);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $model = $project->beforeAfters()->create([
            'title' => $data['title'] ?? null,
            'before_path' => '',
            'after_path' => '',
            'disk' => 'public',
            'sort_order' => (int) ($project->beforeAfters()->max('sort_order') ?? -1) + 1,
        ]);

        return $this->itemResponse($model->toApiArray(), 201);
    }

    public function update(Request $request, int $project, int $beforeAfter): JsonResponse
    {
        $project = Project::findOrFail($project);
        $model = $project->beforeAfters()->findOrFail($beforeAfter);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $model->update($data);

        return $this->itemResponse($model->fresh()->toApiArray());
    }

    public function destroy(int $project, int $beforeAfter): Response
    {
        $project = Project::findOrFail($project);
        $project->beforeAfters()->findOrFail($beforeAfter)->delete();

        return response()->noContent();
    }

    public function uploadSlot(Request $request, int $project, int $beforeAfter, string $slot): JsonResponse
    {
        $this->assertSlot($slot);
        $project = Project::findOrFail($project);
        $model = $project->beforeAfters()->findOrFail($beforeAfter);

        $data = $request->validate([
            'image' => ['required', 'image', 'max:51200', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $extension = strtolower($data['image']->getClientOriginalExtension() ?: 'jpg');
        $binary = file_get_contents($data['image']->getRealPath());

        $this->fillSlot($project, $model, $slot, $binary, $extension);

        return $this->itemResponse($model->fresh()->toApiArray());
    }

    public function fillSlotFromGallery(Request $request, int $project, int $beforeAfter, string $slot): JsonResponse
    {
        $this->assertSlot($slot);
        $project = Project::findOrFail($project);
        $model = $project->beforeAfters()->findOrFail($beforeAfter);

        $data = $request->validate([
            'image_id' => ['required', 'integer'],
        ]);

        $galleryImage = $project->images()->findOrFail($data['image_id']);
        $extension = strtolower(pathinfo($galleryImage->filename, PATHINFO_EXTENSION) ?: 'jpg');
        $binary = Storage::disk('public')->get($galleryImage->path);

        $this->fillSlot($project, $model, $slot, $binary, $extension);

        return $this->itemResponse($model->fresh()->toApiArray());
    }

    protected function assertSlot(string $slot): void
    {
        abort_unless(in_array($slot, ['before', 'after'], true), 404);
    }

    protected function fillSlot(Project $project, ProjectBeforeAfter $model, string $slot, string $binary, string $extension): void
    {
        $basePath = 'projects/'.$project->id.'/before-after';
        $filename = $slot.'_'.$model->id.'_'.Str::random(8).'.'.$extension;
        $path = $basePath.'/'.$filename;

        $image = Image::read($binary);
        if ($image->width() > 1920) {
            $image->scale(width: 1920);
        }
        $encoded = match ($extension) {
            'png' => $image->toPng()->toString(),
            'webp' => $image->toWebp(80)->toString(),
            default => $image->toJpeg(80)->toString(),
        };

        $column = $slot.'_path';
        $old = $model->{$column};

        Storage::disk('public')->put($path, $encoded);
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $model->update([$column => $path]);
    }
}
