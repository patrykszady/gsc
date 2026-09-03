<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\FetchCollaboratorSiteJob;
use App\Models\Project;
use App\Models\ProjectCollaborator;
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
        $collaborators = $this->pullCollaborators($data);

        $project = Project::create($data);
        $this->syncTestimonials($project, $testimonialIds);
        $this->syncCollaborators($project, $collaborators);

        return $this->itemResponse($this->withCrmFields($project->fresh(['images.tags', 'testimonials', 'collaborators'])->toApiArray(), $project), 201);
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
        $collaborators = $this->pullCollaborators($data);

        $model->update($data);
        $this->syncTestimonials($model, $testimonialIds);
        $this->syncCollaborators($model, $collaborators);

        return $this->itemResponse($this->withCrmFields($model->fresh(['images.tags', 'testimonials', 'collaborators'])->toApiArray(), $model));
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
                // "Worked with" vocabulary for the project form: role select
                // options, and the partner directory — everyone ever added on
                // any of this site's projects (most recently used first) plus
                // the design partners listed on /design-partners. Picking one
                // fills the row.
                'collaborator_roles' => ProjectCollaborator::roles(),
                'collaborator_suggestions' => $this->partnerDirectory(),
            ],
        ]);
    }

    /**
     * gsc-only fields (yelp_portfolio_url, testimonial links, partner credits) layered onto Project::toApiArray() here, in the
     * controller, rather than in the model — that method's contract
     * deliberately matches jpeterson's (see its docblock), and these columns
     * don't exist on jpeterson at all. The central admin's ProjectForm shows
     * each behind its own ping capability, which only gsc declares.
     */
    protected function withCrmFields(array $data, Project $project): array
    {
        return $data + [
            'yelp_portfolio_url' => $project->yelp_portfolio_url,
            // The other end of the testimonial<->project pivot the review
            // form already writes. Serialized here (not in toApiArray) for
            // the same reason as the CRM fields: jpeterson has no pivot.
            'testimonial_ids' => $project->testimonials->pluck('id')->values()->all(),
            'collaborators' => $project->collaborators->map(fn ($c) => $c->toApiArray())->values()->all(),
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

    /**
     * The partner directory: distinct partners across all of this site's
     * projects, newest use first, then the site's listed design partners
     * that haven't been used on a project yet.
     *
     * @return array<int, array{name: string, url: ?string, role: string, note: ?string, source: string}>
     */
    protected function partnerDirectory(): array
    {
        $key = fn (string $name, ?string $url) => mb_strtolower(trim($name)) . '|' . mb_strtolower(trim((string) $url));

        $used = ProjectCollaborator::query()
            ->whereIn('project_id', Project::query()->select('id'))
            ->orderByDesc('updated_at')
            ->get()
            ->unique(fn ($c) => $key($c->name, $c->url))
            ->map(fn ($c) => [
                'name' => $c->name,
                'url' => $c->url,
                'role' => $c->role,
                'note' => $c->note,
                'source' => 'projects',
            ])
            ->values();

        $seenNames = $used->map(fn ($p) => mb_strtolower($p['name']))->all();

        $listed = collect(config('design-partners.groups', []))
            ->flatMap(fn ($g) => collect($g['partners'] ?? [])->map(fn ($p) => [
                'name' => $p['name'],
                'url' => $p['url'] ?? null,
                'role' => $g['trade_slug'] ?? 'other',
                'note' => null,
                'source' => 'design-partners',
            ]))
            ->reject(fn ($p) => in_array(mb_strtolower($p['name']), $seenNames, true));

        return $used->concat($listed)->values()->all();
    }

    /** Same absent-vs-empty rule as testimonial_ids. */
    protected function pullCollaborators(array &$data): ?array
    {
        $rows = array_key_exists('collaborators', $data) ? ($data['collaborators'] ?? []) : null;
        unset($data['collaborators']);

        return $rows;
    }

    /**
     * Replace the project's partner credits with the submitted rows. A row
     * whose name+url matches an existing credit keeps its cached site_*
     * columns; anything new with a URL gets its site read in the background
     * so the blog writer has it by the time the draft is written.
     */
    protected function syncCollaborators(Project $project, ?array $rows): void
    {
        if ($rows === null) {
            return;
        }

        $existing = $project->collaborators()->get();
        $keep = [];

        foreach (array_values($rows) as $i => $row) {
            $url = isset($row['url']) && trim((string) $row['url']) !== '' ? trim((string) $row['url']) : null;
            if ($url && ! preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }
            $attrs = [
                'role' => $row['role'] ?? 'other',
                'name' => trim((string) $row['name']),
                'url' => $url,
                'note' => isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null,
                'sort_order' => $i,
            ];

            $match = $existing->first(fn ($c) => $c->name === $attrs['name'] && $c->url === $attrs['url']);
            if ($match) {
                $match->update($attrs);
                $keep[] = $match->id;

                continue;
            }

            // A partner we've read before (same URL on another project) keeps
            // that read — no second fetch of the same homepage.
            $cached = $url
                ? ProjectCollaborator::query()->where('url', $url)->whereNotNull('site_fetched_at')->orderByDesc('site_fetched_at')->first()
                : null;
            if ($cached) {
                $attrs += $cached->only(['site_title', 'site_description', 'site_excerpt', 'site_fetched_at']);
            }

            $created = $project->collaborators()->create($attrs);
            $keep[] = $created->id;
            // Site read still needed, or an estimate of what they did here
            // (the estimate is per project, so it is never copied over).
            if ($created->url && (! $cached || ! $created->note)) {
                FetchCollaboratorSiteJob::dispatch($created);
            }
        }

        $project->collaborators()->whereNotIn('id', $keep)->delete();
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
            'collaborators' => ['sometimes', 'nullable', 'array', 'max:20'],
            'collaborators.*.role' => ['required', 'string', Rule::in(array_keys(ProjectCollaborator::roles()))],
            'collaborators.*.name' => ['required', 'string', 'max:255'],
            'collaborators.*.url' => ['nullable', 'string', 'max:500'],
            'collaborators.*.note' => ['nullable', 'string', 'max:500'],
            'yelp_portfolio_url' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
