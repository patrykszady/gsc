<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $query = Project::query()->with(['images.tags', 'testimonials']);

        if ($search = $request->string('search')->toString()) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($type = $request->string('type')->toString()) {
            $query->where('project_type', $type);
        }

        if ($request->has('published')) {
            $query->where('is_published', $request->boolean('published'));
        }

        if ($request->has('featured')) {
            $query->where('is_featured', $request->boolean('featured'));
        }

        $query = $this->applySort($query, $request->string('sort')->toString() ?: null, 'sort_order');

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, fn (Project $project) => $this->withCrmFields($project->toApiArray(), $project));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $testimonialIds = $this->pullTestimonialIds($data);

        $project = Project::create($data);
        $this->syncTestimonials($project, $testimonialIds);

        return $this->itemResponse($this->withCrmFields($project->fresh(['images.tags', 'testimonials'])->toApiArray(), $project), 201);
    }

    public function show(int $project): JsonResponse
    {
        $model = Project::with(['images.tags', 'testimonials'])->findOrFail($project);

        return $this->itemResponse($this->withCrmFields($model->toApiArray(), $model));
    }

    public function update(Request $request, int $project): JsonResponse
    {
        $model = Project::findOrFail($project);

        $data = $request->validate($this->rules($model->id));
        $testimonialIds = $this->pullTestimonialIds($data);

        $model->update($data);
        $this->syncTestimonials($model, $testimonialIds);

        return $this->itemResponse($this->withCrmFields($model->fresh(['images.tags', 'testimonials'])->toApiArray(), $model));
    }

    /**
     * project_type vocabulary (+ tag_type vocabulary, same shape, so
     * ss-systems' TagList can populate its own select from one round trip)
     * for the central admin's flux:select controls. jpeterson exposes the
     * same route with its own static map — see that app's ProjectController.
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'data' => [
                'project_types' => Project::projectTypes(),
                'tag_types' => Tag::tagTypes(),
            ],
        ]);
    }

    /**
     * gsc-only CRM fields (client_name, client_email, review_request_sent_at,
     * yelp_portfolio_url) layered onto Project::toApiArray() here, in the
     * controller, rather than in the model — that method's contract
     * deliberately matches jpeterson's (see its docblock), and these columns
     * don't exist on jpeterson at all. The central admin's ProjectForm shows
     * them only behind the 'crm' ping capability, which only gsc declares.
     */
    protected function withCrmFields(array $data, Project $project): array
    {
        return $data + [
            'client_name' => $project->client_name,
            'client_email' => $project->client_email,
            'review_request_sent_at' => optional($project->review_request_sent_at)->toIso8601String(),
            'yelp_portfolio_url' => $project->yelp_portfolio_url,
            // The other end of the testimonial<->project pivot the review
            // form already writes. Serialized here (not in toApiArray) for
            // the same reason as the CRM fields: jpeterson has no pivot.
            'testimonial_ids' => $project->testimonials->pluck('id')->values()->all(),
            'testimonials' => $project->testimonials->map(fn ($t) => [
                'id' => $t->id,
                'reviewer_name' => $t->reviewer_name,
                'star_rating' => $t->star_rating,
                'review_date' => optional($t->review_date)->format('Y-m-d'),
            ])->values()->all(),
        ];
    }

    /**
     * null when the key was absent — a partial update must leave existing
     * links alone; an explicit [] clears them. Same rule as the review
     * form's project_ids.
     */
    protected function pullTestimonialIds(array &$data): ?array
    {
        $ids = array_key_exists('testimonial_ids', $data) ? ($data['testimonial_ids'] ?? []) : null;
        unset($data['testimonial_ids']);

        return $ids;
    }

    protected function syncTestimonials(Project $project, ?array $testimonialIds): void
    {
        if ($testimonialIds !== null) {
            $project->testimonials()->sync(array_values(array_filter(array_map('intval', $testimonialIds))));
        }
    }

    /**
     * Reviews available to link from the project form — the picker's option
     * list. Small enough to send whole (gsc has ~70) and ordered newest
     * first, matching how an operator thinks about "the review for this job".
     */
    public function linkableTestimonials(): JsonResponse
    {
        return response()->json([
            'data' => Testimonial::query()
                ->orderByDesc('review_date')
                ->orderByDesc('id')
                ->get(['id', 'reviewer_name', 'star_rating', 'review_date', 'project_location'])
                ->map(fn (Testimonial $t) => [
                    'id' => $t->id,
                    'reviewer_name' => $t->reviewer_name,
                    'star_rating' => $t->star_rating,
                    'review_date' => optional($t->review_date)->format('Y-m-d'),
                    'project_location' => $t->project_location,
                ])->all(),
        ]);
    }

    public function destroy(int $project): Response
    {
        $model = Project::findOrFail($project);

        // Delete images through Eloquent, not the FK cascade: the cascade
        // bypasses model events, so ProjectImage's deleting hook (which
        // removes the file from disk) never fires and the files orphan.
        $model->images()->get()->each->delete();

        $model->delete();

        return response()->noContent();
    }

    /**
     * Every optional column is 'sometimes' — including the merely-nullable
     * ones — so a client that omits a field on update leaves it untouched
     * rather than nulling it out. Without that, an update payload that
     * doesn't resend e.g. "slug" would null a NOT NULL/unique column and
     * fail with a database error instead of just... not changing it.
     */
    protected function rules(?int $ignoreId = null): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('projects', 'slug')->ignore($ignoreId)],
            'description' => ['sometimes', 'nullable', 'string'],
            'project_type' => ['required', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'testimonial_ids' => ['sometimes', 'nullable', 'array'],
            'testimonial_ids.*' => ['integer', 'exists:testimonials,id'],
            // gsc-only CRM fields — never shown/set on jpeterson.
            // review_request_sent_at is deliberately absent: it's set only by
            // the automated post-completion review-request mailer, never by
            // an admin API client.
            'client_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'yelp_portfolio_url' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
