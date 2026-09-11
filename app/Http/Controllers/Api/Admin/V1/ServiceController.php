<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateServiceContentJob;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The company's services — the list behind the project form's "Project
 * Type" (ported from jpeterson-design; same API shape for both sites).
 *
 * A project stores a service's SLUG in its project_type column, which is
 * why two things are guarded here: a slug in use cannot change (the
 * projects would point at nothing), and a service in use cannot be
 * deleted. Renaming is always safe and is the usual way to fix wording.
 */
class ServiceController extends Controller
{
    use BuildsApiResponses;

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Service::ordered()->get()->map(fn (Service $s) => $s->toApiArray())->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->withContent($request->validate($this->rules()));

        // Slug from the name unless one was given: the admin's inline "add
        // a service" sends a name alone.
        $data['slug'] = isset($data['slug']) && $data['slug'] !== ''
            ? Service::uniqueSlug($data['slug'])
            : Service::uniqueSlug($data['name']);

        $service = Service::create($data);
        // Every new service gets its copy drafted at once, like a new area.
        GenerateServiceContentJob::launch($service);

        // fresh(): is_landing_page and sort_order have database defaults, so
        // the in-memory model returns null for them until it is re-read.
        return $this->itemResponse($service->fresh()->toApiArray(), 201);
    }

    public function show(int $service): JsonResponse
    {
        return $this->itemResponse(Service::findOrFail($service)->toApiArray());
    }

    public function update(Request $request, int $service): JsonResponse
    {
        $model = Service::findOrFail($service);
        $data = $request->validate($this->rules($model->id, partial: true));

        if (array_key_exists('slug', $data) && $data['slug'] !== $model->slug && $model->projectsCount() > 0) {
            return response()->json([
                'message' => 'That service is used by projects, so its slug cannot change. Rename it instead.',
                'errors' => ['slug' => ['In use by '.$model->projectsCount().' project(s).']],
            ], 422);
        }

        $model->update($this->withContent($data));

        return $this->itemResponse($model->fresh()->toApiArray());
    }

    /** Queue Gemini to fill the empty content fields (or everything with force=1). */
    public function generate(Request $request, int $service): JsonResponse
    {
        $model = Service::findOrFail($service);
        GenerateServiceContentJob::launch($model, $request->boolean('force'));

        return $this->acceptedResponse($model->fresh()->toApiArray());
    }

    public function destroy(int $service): JsonResponse|Response
    {
        $model = Service::findOrFail($service);

        if (($count = $model->projectsCount()) > 0) {
            return response()->json([
                'message' => "That service is used by {$count} project(s). Move them to another service first.",
            ], 422);
        }

        $model->delete();

        return response()->noContent();
    }

    /** Whole-list reorder, as the admin's drag-to-sort sends it. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach (array_values($data['order']) as $position => $id) {
            Service::where('id', $id)->update(['sort_order' => $position + 1]);
        }

        return response()->json([
            'data' => Service::ordered()->get()->map(fn (Service $s) => $s->toApiArray())->all(),
        ]);
    }

    /** Clean the structured content fields: a FAQ without blanks, a sections map with known keys only. */
    protected function withContent(array $data): array
    {
        if (array_key_exists('faq', $data)) {
            $data['faq'] = Service::normaliseFaq($data['faq']);
        }
        if (array_key_exists('sections', $data)) {
            $data['sections'] = collect((array) $data['sections'])
                ->only(array_keys(Service::SECTIONS))
                ->map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOL))
                ->all();
        }

        return $data;
    }

    protected function rules(?int $ignoreId = null, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'required', 'string', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('services', 'slug')->ignore($ignoreId)],
            'blurb' => ['sometimes', 'nullable', 'string'],
            'is_landing_page' => ['sometimes', 'nullable', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
            'intro' => ['sometimes', 'nullable', 'string'],
            'what_we_do' => ['sometimes', 'nullable', 'string'],
            'ideal_for' => ['sometimes', 'nullable', 'string'],
            'faq' => ['sometimes', 'nullable', 'array'],
            'faq.*.question' => ['nullable', 'string', 'max:500'],
            'faq.*.answer' => ['nullable', 'string', 'max:2000'],
            // Per-section show/hide — only known section keys, booleans.
            'sections' => ['sometimes', 'nullable', 'array'],
            'sections.*' => ['boolean'],
        ];
    }
}
