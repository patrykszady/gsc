<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

/**
 * gsc-only (behind the 'timelapses' ping capability): CRUD for a project's
 * timelapses plus their frames — upload, pick-from-gallery, reorder,
 * delete, and the in-browser redaction editor's save (a re-encoded data
 * URL). Ported from app/Livewire/Admin/ProjectForm.php's timelapse methods,
 * split into stateless per-action endpoints because the central admin talks
 * over REST rather than sharing one long-lived Livewire component state.
 *
 * File handling (resize to max 1920px wide, JPEG/PNG/WebP re-encode,
 * 'projects/{project}/timelapse/{timelapse}' storage path) matches that
 * Livewire component byte-for-byte so timelapse frames look identical
 * whether they came from the legacy admin or this one.
 */
class TimelapseController extends Controller
{
    use BuildsApiResponses;

    public function index(int $project): JsonResponse
    {
        $project = Project::findOrFail($project);

        return response()->json([
            'data' => $project->timelapses()->with('frames')->get()
                ->map(fn (ProjectTimelapse $tl) => $tl->toApiArray())->all(),
        ]);
    }

    public function store(Request $request, int $project): JsonResponse
    {
        $project = Project::findOrFail($project);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_mode' => ['sometimes', 'string', 'in:slider,accordion'],
        ]);

        $timelapse = $project->timelapses()->create([
            'title' => $data['title'] ?? null,
            'display_mode' => $data['display_mode'] ?? 'slider',
            'sort_order' => (int) ($project->timelapses()->max('sort_order') ?? -1) + 1,
        ]);

        return $this->itemResponse($timelapse->fresh('frames')->toApiArray(), 201);
    }

    public function update(Request $request, int $project, int $timelapse): JsonResponse
    {
        $project = Project::findOrFail($project);
        $model = $project->timelapses()->findOrFail($timelapse);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_mode' => ['sometimes', 'string', 'in:slider,accordion'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $model->update($data);

        return $this->itemResponse($model->fresh('frames')->toApiArray());
    }

    public function destroy(int $project, int $timelapse): Response
    {
        $project = Project::findOrFail($project);
        $model = $project->timelapses()->findOrFail($timelapse);
        $model->frames->each->delete();
        $model->delete();

        return response()->noContent();
    }

    public function storeFrame(Request $request, int $project, int $timelapse): JsonResponse
    {
        $project = Project::findOrFail($project);
        $model = $project->timelapses()->findOrFail($timelapse);

        $data = $request->validate([
            'image' => ['required', 'image', 'max:51200', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $frame = $this->createFrameFromBinary(
            $project, $model,
            file_get_contents($data['image']->getRealPath()),
            $data['image']->getClientOriginalName(),
            strtolower($data['image']->getClientOriginalExtension() ?: 'jpg'),
        );

        return $this->itemResponse($frame->toApiArray(), 201);
    }

    /**
     * Copy an existing project gallery image in as a frame — resized the
     * same way as a direct upload, not a byte-for-byte copy, so a 12MB
     * gallery original doesn't ship as a "frame" untouched.
     */
    public function storeFrameFromGallery(Request $request, int $project, int $timelapse): JsonResponse
    {
        $project = Project::findOrFail($project);
        $model = $project->timelapses()->findOrFail($timelapse);

        $data = $request->validate([
            'image_id' => ['required', 'integer'],
        ]);

        $galleryImage = $project->images()->findOrFail($data['image_id']);
        $extension = strtolower(pathinfo($galleryImage->filename, PATHINFO_EXTENSION) ?: 'jpg');
        $sourceContent = Storage::disk('public')->get($galleryImage->path);

        $frame = $this->createFrameFromBinary(
            $project, $model, $sourceContent, $galleryImage->original_filename, $extension,
        );

        return $this->itemResponse($frame->toApiArray(), 201);
    }

    protected function createFrameFromBinary(Project $project, ProjectTimelapse $timelapse, string $binary, string $originalFilename, string $extension): ProjectTimelapseFrame
    {
        $sortOrder = (int) ($timelapse->frames()->max('sort_order') ?? 0) + 1;
        $basePath = 'projects/'.$project->id.'/timelapse/'.$timelapse->id;
        $filename = $sortOrder.'_'.Str::random(8).'.'.$extension;

        $encoded = $this->encode($binary, $extension);

        $path = $basePath.'/'.$filename;
        Storage::disk('public')->put($path, $encoded);

        return ProjectTimelapseFrame::create([
            'project_timelapse_id' => $timelapse->id,
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'path' => $path,
            'disk' => 'public',
            'sort_order' => $sortOrder,
        ]);
    }

    protected function encode(string $binary, string $extension): string
    {
        $image = Image::read($binary);
        if ($image->width() > 1920) {
            $image->scale(width: 1920);
        }

        return match ($extension) {
            'png' => $image->toPng()->toString(),
            'webp' => $image->toWebp(80)->toString(),
            default => $image->toJpeg(80)->toString(),
        };
    }

    public function reorderFrames(Request $request, int $project, int $timelapse): JsonResponse
    {
        $project = Project::findOrFail($project);
        $model = $project->timelapses()->findOrFail($timelapse);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach (array_values($data['order']) as $position => $frameId) {
            $model->frames()->where('id', $frameId)->update(['sort_order' => $position]);
        }

        return response()->json([
            'data' => $model->frames()->get()->map(fn (ProjectTimelapseFrame $f) => $f->toApiArray())->all(),
        ]);
    }

    public function destroyFrame(int $project, int $timelapse, int $frame): Response
    {
        $project = Project::findOrFail($project);
        $model = $project->timelapses()->findOrFail($timelapse);
        $model->frames()->findOrFail($frame)->delete();

        return response()->noContent();
    }

    /**
     * Save the in-browser redaction editor's output: the client sends a
     * data URL of the edited frame (e.g. after drawing blur boxes over
     * personal info), which is decoded and re-encoded server-side with
     * Intervention — same as ProjectForm::updateTimelapseFrame() — so the
     * stored file is always a real, re-compressed image rather than
     * whatever the browser's canvas happened to export.
     */
    public function updateFrame(Request $request, int $project, int $timelapse, int $frame): JsonResponse
    {
        $project = Project::findOrFail($project);
        $model = $project->timelapses()->findOrFail($timelapse);
        $frameModel = $model->frames()->findOrFail($frame);

        $data = $request->validate([
            'data_url' => ['required', 'string'],
        ]);

        if (! preg_match('#^data:image/(png|jpeg|jpg|webp);base64,(.+)$#i', $data['data_url'], $m)) {
            abort(422, 'Not a valid image data URL.');
        }

        $binary = base64_decode($m[2], true);
        if ($binary === false || strlen($binary) < 32) {
            abort(422, 'Could not decode the submitted image.');
        }

        $extension = strtolower(pathinfo($frameModel->path, PATHINFO_EXTENSION) ?: 'jpg');
        $encoded = $this->encode($binary, $extension);

        Storage::disk($frameModel->disk)->put($frameModel->path, $encoded);
        $frameModel->touch();

        return $this->itemResponse($frameModel->fresh()->toApiArray());
    }
}
