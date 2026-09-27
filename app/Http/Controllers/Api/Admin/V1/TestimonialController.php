<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\ReviewUrl;
use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use SsSystems\Platform\Reviews\Http\Concerns\ServesTestimonials;

/**
 * index()/filters()/show()/destroy() now come from the kit's
 * ServesTestimonials (2026-09-27, see docs/audit-2026-09-27/
 * admin-api-skeleton.md §10) — this class keeps only what's real,
 * gsc-specific business logic: store()/update()'s review_urls/project_ids
 * sync onto real pivots, and this app's own rules()/query hooks.
 */
class TestimonialController extends Controller
{
    use BuildsApiResponses;
    use ServesTestimonials;

    /** Known review-platform icons this app ships under public/images/socials/. */
    protected const KNOWN_PLATFORMS = ['google', 'yelp', 'facebook', 'angi', 'houzz'];

    protected function testimonialModel(): string
    {
        return Testimonial::class;
    }

    /** Never had a whitelist — any `sort` column is taken as-is, same as BuildsApiResponses::applySort(). */
    protected function sortable(): ?array
    {
        return null;
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function eagerLoads(): array
    {
        return ['reviewUrls', 'projects'];
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        if ($search = $request->string('search')->toString()) {
            $query->where('reviewer_name', 'like', "%{$search}%");
        }

        if ($request->has('hidden')) {
            $query->where('is_hidden', $request->boolean('hidden'));
        }

        if ($request->filled('star_rating')) {
            $query->where('star_rating', (int) $request->string('star_rating')->toString());
        }

        if ($type = $request->string('project_type')->toString()) {
            $query->where('project_type', $type);
        }

        if ($platform = $request->string('platform')->toString()) {
            $query->whereHas('reviewUrls', fn ($q) => $q->where('platform', $platform));
        }
    }

    protected function projectTypeOptions(): array
    {
        return Testimonial::query()
            ->whereNotNull('project_type')
            ->where('project_type', '!=', '')
            ->distinct()
            ->orderBy('project_type')
            ->pluck('project_type')
            ->all();
    }

    protected function platformOptions(): array
    {
        return ReviewUrl::query()
            ->whereNotNull('platform')
            ->where('platform', '!=', '')
            ->distinct()
            ->orderBy('platform')
            ->pluck('platform')
            ->map(fn (string $platform) => [
                'value' => $platform,
                'label' => ucfirst($platform),
                'icon' => in_array($platform, self::KNOWN_PLATFORMS, true)
                    ? asset("images/socials/{$platform}.svg")
                    : null,
            ])
            ->values()
            ->all();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        [$reviewUrls, $projectIds] = $this->pullExtras($data);

        $testimonial = Testimonial::create($data);
        $this->syncExtras($testimonial, $reviewUrls, $projectIds);

        return $this->itemResponse($testimonial->fresh(['reviewUrls', 'projects'])->toApiArray(), 201);
    }

    public function update(Request $request, int $testimonial): JsonResponse
    {
        $model = Testimonial::findOrFail($testimonial);

        $data = $request->validate($this->rules($model->id));
        [$reviewUrls, $projectIds] = $this->pullExtras($data);

        $model->update($data);
        $this->syncExtras($model, $reviewUrls, $projectIds);

        return $this->itemResponse($model->fresh(['reviewUrls', 'projects'])->toApiArray());
    }

    /**
     * Pull review_urls[]/project_ids[] out of the validated payload before
     * the model write — this app has no columns for either (see rules()).
     *
     * @return array{0: array<int, array{platform: ?string, url: ?string}>, 1: array<int, int>}
     */
    protected function pullExtras(array &$data): array
    {
        // null when the key was NOT sent: a partial update must leave the
        // existing rows alone — "absent" and "explicitly empty" are
        // different requests. (A restore-style PUT without review_urls once
        // silently deleted a testimonial's platform links.)
        $reviewUrls = array_key_exists('review_urls', $data) ? ($data['review_urls'] ?? []) : null;
        $projectIds = array_key_exists('project_ids', $data) ? ($data['project_ids'] ?? []) : null;
        unset($data['review_url'], $data['google_review_id'], $data['review_urls'], $data['project_ids']);

        return [$reviewUrls, $projectIds];
    }

    /**
     * Replace this testimonial's review_urls and linked projects. Mirrors
     * the legacy TestimonialForm::save(): review URLs are fully replaced
     * (delete then recreate) rather than diffed, and rows with no url are
     * dropped. Platform inference / UTM stripping happen on the caller side
     * (the ss-systems TestimonialForm, matching where the legacy Livewire
     * component did it) — this just persists whatever platform/url pairs
     * arrive.
     */
    protected function syncExtras(Testimonial $testimonial, ?array $reviewUrls, ?array $projectIds): void
    {
        if ($reviewUrls !== null) {
            $testimonial->reviewUrls()->delete();
            foreach ($reviewUrls as $entry) {
                $url = trim((string) ($entry['url'] ?? ''));
                $platform = trim((string) ($entry['platform'] ?? ''));

                if ($url === '' || $platform === '') {
                    continue;
                }

                $testimonial->reviewUrls()->create([
                    'platform' => $platform,
                    'url' => $url,
                    // The platform's own id for the review (a Google review id),
                    // so an import can tell what is already here — 2026-09-22.
                    'external_id' => trim((string) ($entry['external_id'] ?? '')) ?: null,
                ]);
            }
        }

        if ($projectIds !== null) {
            $testimonial->projects()->sync(array_values(array_filter(array_map('intval', $projectIds))));
        }
    }

    protected function rules(?int $ignoreId = null): array
    {
        return [
            'reviewer_name' => ['required', 'string', 'max:255'],
            'project_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'review_description' => ['required', 'string'],
            'review_date' => ['sometimes', 'nullable', 'date'],
            // Part of the shared contract but columnless on this app —
            // validated so callers get sane errors, then dropped before the
            // model write (see rules() callers; Testimonial::toApiArray()
            // serializes them as null).
            'review_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'google_review_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'star_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'is_hidden' => ['sometimes', 'boolean'],
            // gsc-only extras — restored for pixel parity, see filters()/syncExtras().
            'review_urls' => ['sometimes', 'nullable', 'array'],
            'review_urls.*.platform' => ['sometimes', 'nullable', 'string', 'max:50'],
            'review_urls.*.url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'review_urls.*.external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project_ids' => ['sometimes', 'nullable', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
        ];
    }
}
