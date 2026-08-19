<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ported from the Livewire admin's SeoOverridesPanel — a generic per-morph
 * "title/description/author/image/canonical_url/robots" override editor,
 * embedded on the Project, Area and Testimonial edit forms there.
 *
 * The Livewire original takes a bound Eloquent model directly; over the API
 * the caller can only name it, so {type} is a small allowlist mapping the
 * three admin resources that actually embed the panel onto their classes —
 * NOT an arbitrary class-name lookup.
 */
class SeoOverrideController extends Controller
{
    use BuildsApiResponses;

    /** @var array<string, class-string<Model>> */
    protected const TYPES = [
        'project' => Project::class,
        'area' => AreaServed::class,
        'testimonial' => Testimonial::class,
    ];

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->resolve($type, $id);

        return $this->itemResponse($this->fields($model));
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->resolve($type, $id);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'author' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:2048'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'robots' => ['nullable', 'string', 'max:255'],
        ]);

        $attrs = array_map(fn ($v) => $this->nullIfBlank($v), $data);

        // Polymorphic morphOne; updateOrCreate works on the relation, same
        // as the Livewire original.
        $model->seo()->updateOrCreate([], $attrs);

        $payload = $this->fields($model->fresh());
        $payload['message'] = 'SEO overrides saved.';

        return $this->itemResponse($payload);
    }

    public function destroy(Request $request, string $type, int $id): Response
    {
        $model = $this->resolve($type, $id);

        if ($seo = $model->seo) {
            $seo->update([
                'title' => null, 'description' => null, 'author' => null,
                'image' => null, 'canonical_url' => null, 'robots' => null,
            ]);
        }

        return response()->noContent();
    }

    protected function resolve(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? null;
        abort_if($class === null, 404, "Unknown SEO override target type \"{$type}\".");

        /** @var Model $model */
        $model = $class::findOrFail($id);
        abort_unless(method_exists($model, 'seo'), 422, ucfirst($type).' does not support SEO overrides.');

        return $model;
    }

    protected function fields(Model $model): array
    {
        $seo = method_exists($model, 'seo') ? $model->seo : null;

        return [
            'title' => $seo->title ?? null,
            'description' => $seo->description ?? null,
            'author' => $seo->author ?? null,
            'image' => $seo->image ?? null,
            'canonical_url' => $seo->canonical_url ?? null,
            'robots' => $seo->robots ?? null,
        ];
    }

    protected function nullIfBlank(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }
}
