<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateAreaContentJob;
use App\Jobs\RunSeoChannelSyncJob;
use App\Models\AreaServed;
use App\Support\Areas\TownCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * gsc-only resource (jpeterson's ping omits the "areas" capability, so the
 * central admin hides the screen there). The shared contract's field is
 * "name"; this app's column is "city" — mapped here on the way in and in
 * AreaServed::toApiArray() on the way out. Map/geocode tooling stays on
 * the legacy admin.
 */
class AreaController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $query = AreaServed::query()
            ->when($request->filled('search'), fn ($q) => $q->where('city', 'like', '%'.$request->string('search').'%'));

        $this->applySort($query, $request->string('sort')->toString() ?: null, 'city');

        $paginator = $query->paginate($this->perPage($request, 50));

        return $this->paginatedResponse($paginator, fn (AreaServed $area) => $area->toApiArray());
    }

    /**
     * Towns around the office that are not areas yet, best candidates first
     * (projects there, researched demand, Business Profile service area,
     * distance). Backs the "pick a town" dropdown on the admin form.
     */
    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:60'], 'limit' => ['nullable', 'integer', 'between:1,300']]);

        return $this->itemResponse([
            'candidates' => TownCatalog::candidates($data['q'] ?? null, (int) ($data['limit'] ?? 60)),
            'radius_miles' => (float) config('areas.candidate_radius_miles', 35),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['generate' => ['sometimes', 'boolean']]);
        $generate = (bool) ($data['generate'] ?? false);
        unset($data['generate']);
        $data = $this->mapped($data);
        // A town from the catalog brings its own coordinates (and its slug
        // convention: state-suffixed outside the home state): no geocoder round trip.
        if ($town = TownCatalog::find((string) $data['city'])) {
            $data['latitude'] = $data['latitude'] ?? $town['lat'];
            $data['longitude'] = $data['longitude'] ?? $town['lng'];
            if (empty($request->input('slug'))) {
                $data['slug'] = TownCatalog::slugFor($town);
            }
            if (Str::slug((string) $data['city']) === Str::slug($town['name']) || Str::slug((string) $data['city']) === Str::slug(TownCatalog::label($town))) {
                $data['city'] = TownCatalog::cityFor($town);
            }
        }
        $area = AreaServed::create($this->withContent($data));
        $this->queueGeocodeIfMissingCoords($area);
        if ($generate) {
            GenerateAreaContentJob::launch($area);
        }

        return $this->itemResponse($area->fresh()->toApiArray(), 201);
    }

    /** Queue Gemini to fill the empty content fields (or everything with force=1). */
    public function generate(Request $request, int $area): JsonResponse
    {
        $model = AreaServed::findOrFail($area);
        GenerateAreaContentJob::launch($model, $request->boolean('force'));

        return $this->acceptedResponse($model->fresh()->toApiArray());
    }

    public function show(int $area): JsonResponse
    {
        return $this->itemResponse(AreaServed::findOrFail($area)->toApiArray());
    }

    public function update(Request $request, int $area): JsonResponse
    {
        $model = AreaServed::findOrFail($area);

        $model->update($this->withContent($this->mapped($request->validate($this->rules($model->id)))));
        $this->queueGeocodeIfMissingCoords($model);

        return $this->itemResponse($model->fresh()->toApiArray());
    }

    /**
     * Restored from the legacy AreaForm::save(): coordless areas are
     * invisible to the coverage map and get no local proof stats, so
     * saving one queues the same geocoder the legacy form did (it only
     * touches rows missing coords) — same job class, this site's own queue.
     */
    protected function queueGeocodeIfMissingCoords(AreaServed $area): void
    {
        if ($area->latitude === null || $area->longitude === null) {
            RunSeoChannelSyncJob::dispatch('gbp:geocode-areas');
        }
    }

    public function destroy(int $area): Response
    {
        AreaServed::findOrFail($area)->delete();

        return response()->noContent();
    }

    /** Contract "name" → column "city"; slug derived when absent. */
    protected function mapped(array $data): array
    {
        if (array_key_exists('name', $data)) {
            $data['city'] = $data['name'];
            unset($data['name']);
        }

        if (empty($data['slug']) && isset($data['city'])) {
            $data['slug'] = Str::slug($data['city']);
        }

        return $data;
    }

    /** Clean the structured content fields: a FAQ without blanks, a sections map with known keys only. */
    protected function withContent(array $data): array
    {
        if (array_key_exists('faq', $data)) {
            $data['faq'] = AreaServed::normaliseFaq($data['faq']);
        }
        if (array_key_exists('sections', $data)) {
            $data['sections'] = collect((array) $data['sections'])
                ->only(array_keys(AreaServed::SECTIONS))
                ->map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOL))
                ->all();
        }

        return $data;
    }

    protected function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('areas_served', 'slug')->ignore($ignoreId)],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'intro' => ['sometimes', 'nullable', 'string'],
            'local_intro' => ['sometimes', 'nullable', 'string'],
            'landmarks' => ['sometimes', 'nullable', 'string'],
            'neighborhoods' => ['sometimes', 'nullable', 'string'],
            'popular_projects' => ['sometimes', 'nullable', 'string'],
            'how_we_work' => ['sometimes', 'nullable', 'string'],
            'faq' => ['sometimes', 'nullable', 'array'],
            'faq.*.question' => ['nullable', 'string', 'max:500'],
            'faq.*.answer' => ['nullable', 'string', 'max:2000'],
            'permit_notes' => ['sometimes', 'nullable', 'string'],
            // Per-section show/hide — only known section keys, booleans.
            'sections' => ['sometimes', 'nullable', 'array'],
            'sections.*' => ['boolean'],
        ];
    }
}
