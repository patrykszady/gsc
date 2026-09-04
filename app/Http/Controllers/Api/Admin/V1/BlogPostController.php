<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateProjectBlogPostJob;
use App\Models\BlogPost;
use App\Models\Project;
use App\Services\Blog\ProjectBlogWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Write (or rewrite) the project's draft in the background. The writer
     * takes 30–60s — longer than the admin proxy allows a request — so this
     * queues the job and answers at once; the admin polls status() until the
     * flag clears. A rewrite always lands as a draft.
     */
    public function generateForProject(int $project): JsonResponse
    {
        $model = Project::findOrFail($project);

        Cache::put(GenerateProjectBlogPostJob::generatingKey($model), now()->toIso8601String(), now()->addMinutes(10));
        GenerateProjectBlogPostJob::dispatch($model, force: true);

        return response()->json(['data' => $this->status($model)], 202);
    }

    /** The project's post (summary) and whether a draft is being written right now. */
    public function statusForProject(int $project): JsonResponse
    {
        return response()->json(['data' => $this->status(Project::findOrFail($project))]);
    }

    /** @return array{post: ?array, generating: bool} */
    public static function status(Project $project): array
    {
        $post = $project->blogPost()->first();

        return [
            'post' => $post ? [
                'id' => $post->id,
                'title' => $post->title,
                'status' => $post->status,
                'writer' => $post->writer,
                'url' => $post->url(),
                'preview_url' => $post->previewUrl(),
                'published_at' => $post->published_at?->toIso8601String(),
                'updated_at' => $post->updated_at?->toIso8601String(),
            ] : null,
            'generating' => Cache::has(GenerateProjectBlogPostJob::generatingKey($project)),
        ];
    }

    public function destroy(int $post, \Illuminate\Http\Request $request): Response
    {
        $model = BlogPost::findOrFail($post);
        // The only code path that removes a post. Logged so a vanished draft
        // can be traced to the click that removed it.
        \Illuminate\Support\Facades\Log::channel('ai_content')->info('Blog post deleted via admin API', [
            'post_id' => $model->id, 'project_id' => $model->project_id, 'title' => $model->title, 'status' => $model->status,
            'ip' => $request->ip(), 'user_agent' => (string) $request->userAgent(),
        ]);
        $model->delete();

        return response()->noContent();
    }
}
