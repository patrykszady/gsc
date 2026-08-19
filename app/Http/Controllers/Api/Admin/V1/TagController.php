<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Tag::query()->withCount('images')->orderBy('name')->get()->map(fn (Tag $tag) => $tag->toApiArray())->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $tag = Tag::create($data);

        return response()->json(['data' => $tag->fresh()->loadCount('images')->toApiArray()], 201);
    }

    public function update(Request $request, int $tag): JsonResponse
    {
        $model = Tag::findOrFail($tag);

        $data = $request->validate($this->rules());

        $model->update($data);

        return response()->json(['data' => $model->fresh()->loadCount('images')->toApiArray()]);
    }

    public function destroy(int $tag): Response
    {
        Tag::findOrFail($tag)->delete();

        return response()->noContent();
    }

    /**
     * No "slug" here: it is always derived from "name" (Tag::booted()), the
     * same as gsc's Tag model — never client-supplied.
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
