<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Project;
use App\Services\Blog\ProjectBlogWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Blog posts for the central admin: list, edit, publish/unpublish, regenerate. */
class BlogPostController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $query = BlogPost::query()->with('project.images')->orderByDesc('updated_at');
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('title', 'like', "%{$search}%");
        }

        return $this->paginatedResponse($query->paginate($this->perPage($request)), fn (BlogPost $p) => $p->toApiArray());
    }

    public function show(int $post): JsonResponse
    {
        return $this->itemResponse(BlogPost::with('project.images')->findOrFail($post)->toApiArray());
    }

    public function update(Request $request, int $post): JsonResponse
    {
        $model = BlogPost::findOrFail($post);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:191'],
            'slug' => ['sometimes', 'string', 'max:191'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body' => ['sometimes', 'nullable', 'string'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:191'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'status' => ['sometimes', 'in:draft,published'],
        ]);

        if (($data['status'] ?? null) === BlogPost::STATUS_PUBLISHED && ! $model->published_at) {
            $data['published_at'] = now();
        }
        if (isset($data['slug'])) {
            $data['slug'] = BlogPost::uniqueSlug($data['slug'], $model->id);
        }
        if (array_intersect_key($data, array_flip(['title', 'body', 'excerpt']))) {
            $data['writer'] = 'manual';
        }

        $model->update($data);

        return $this->itemResponse($model->fresh('project.images')->toApiArray());
    }

    /** Rewrite the draft from the project with the AI writer (synchronous; ~10-20s). */
    public function regenerate(int $post, ProjectBlogWriter $writer): JsonResponse
    {
        $model = BlogPost::findOrFail($post);
        abort_unless($model->project, 422, 'Post has no project to regenerate from.');

        $fresh = $writer->write($model->project);
        abort_if($fresh === null, 502, 'AI writer failed: ' . ($writer->getLastError() ?? 'unknown'));

        return $this->itemResponse($fresh->fresh('project.images')->toApiArray());
    }

    /** Draft a post for a project that has none. */
    public function generateForProject(int $project, ProjectBlogWriter $writer): JsonResponse
    {
        $model = Project::findOrFail($project);
        $post = $model->blogPost ?: $writer->write($model);
        abort_if($post === null, 502, 'AI writer failed: ' . ($writer->getLastError() ?? 'unknown'));

        return $this->itemResponse($post->fresh('project.images')->toApiArray(), 201);
    }

    public function destroy(int $post): Response
    {
        BlogPost::findOrFail($post)->delete();

        return response()->noContent();
    }
}
