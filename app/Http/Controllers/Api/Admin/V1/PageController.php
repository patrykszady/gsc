<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\SeoPathOverride;
use App\Services\SeoService;
use App\Support\SEO\SEOBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * ss-systems' "Pages" screen (the 'pages' ping domain) for gs.construction.
 *
 * Unlike dawnsellshomes.com — whose pages are DB rows (see the reference
 * Api\Admin\V1\PageController there) — gsc's public pages are built into
 * routes/views: home, about, contact, the services index + each service
 * page, and the areas-served index (PAGES below). There is no per-page
 * Eloquent row to CRUD, so this lists that small, curated set of the
 * site's real top-level marketing pages — each entry checked against
 * Route::has() so a renamed/removed route quietly drops off the list
 * rather than 404ing — and reads/writes title + meta description through
 * SeoPathOverride: the path-keyed table SEOBuilder::build() consults at
 * the TOP of its precedence chain for every request, above any per-model
 * SEO override AND above the programmatic title SeoService computes (see
 * that model's docblock and SeoAutopilotService/TitleMetaApplier, which
 * already write it for exactly this reason — area pages set their title
 * without binding a model, so it is the only override channel that
 * reaches them).
 *
 * This deliberately does NOT reuse the polymorphic `seo` table
 * SeoOverrideController writes: nothing in gsc's actual render path ever
 * binds a model to SEOBuilder (its ->for() is never called by any route),
 * so a title saved there would round-trip through the API but never
 * change a single byte of what a visitor sees. Wiring this screen to
 * SeoPathOverride instead is what makes "declaring `pages`" actually mean
 * the endpoints work.
 *
 * The "current effective value" shown when nothing has been overridden
 * yet is computed live: the same SeoService call the real page makes is
 * run against a throwaway SEOBuilder instance, so the admin sees today's
 * real title/description (deterministic for every page in PAGES — none
 * of them carry the per-town random seeding AreaServed pages do).
 *
 * Creating or deleting a page always refuses (422): these pages are part
 * of the site's code, not rows a screen can add or remove.
 */
class PageController extends Controller
{
    use BuildsApiResponses;

    /**
     * path (SeoPathOverride-normalized form, '' meaning home) => [route
     * name, type]. type is 'home' | 'page' | 'service' — matches what GET
     * pages/types groups by.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected const PAGES = [
        '' => ['home', 'home'],
        'about' => ['about', 'page'],
        'contact' => ['contact', 'page'],
        'services' => ['services.index', 'page'],
        'services/kitchen-remodeling' => ['services.kitchen', 'service'],
        'services/bathroom-remodeling' => ['services.bathroom', 'service'],
        'services/home-remodeling' => ['services.home', 'service'],
        'services/basement-remodeling' => ['services.basement', 'service'],
        'services/home-additions' => ['services.additions', 'service'],
        'services/mudroom-remodeling' => ['services.mudroom', 'service'],
        'areas-served' => ['areas.index', 'page'],
    ];

    public function index(Request $request): JsonResponse
    {
        $entries = $this->allEntries();

        if ($type = trim((string) $request->string('type'))) {
            $entries = array_values(array_filter($entries, fn (array $e) => $e['type'] === $type));
        }

        $rows = array_map(fn (array $e) => $this->toRow($e), $entries);

        if ($search = mb_strtolower(trim((string) $request->string('search')))) {
            $rows = array_values(array_filter($rows, function (array $row) use ($search) {
                return str_contains(mb_strtolower((string) $row['title']), $search)
                    || str_contains(mb_strtolower($row['path']), $search);
            }));
        }

        // No per-page sitemap opt-out exists for these built-in pages yet —
        // every one is always in the sitemap, so "not in sitemap" is
        // honestly an empty result rather than a fabricated row.
        if ((string) $request->string('sitemap') === '0') {
            $rows = [];
        }

        $sort = (string) $request->string('sort', 'updated');
        $dir = strtolower((string) $request->string('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        usort($rows, function (array $a, array $b) use ($sort) {
            return match ($sort) {
                'title' => strcasecmp((string) $a['title'], (string) $b['title']),
                'path' => strcmp($a['path'], $b['path']),
                default => strcmp((string) $a['updated_at'], (string) $b['updated_at']),
            };
        });
        if ($dir === 'desc') {
            $rows = array_reverse($rows);
        }

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = $this->perPage($request, 20, 100);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator($slice, count($rows), $perPage, $page);

        return $this->paginatedResponse($paginator, fn (array $row) => $row);
    }

    public function types(): JsonResponse
    {
        $counts = [];
        foreach ($this->allEntries() as $entry) {
            $counts[$entry['type']] = ($counts[$entry['type']] ?? 0) + 1;
        }

        $rows = [];
        foreach ($counts as $type => $count) {
            $rows[] = ['type' => $type, 'count' => $count];
        }

        return $this->itemResponse($rows);
    }

    public function show(string $page): JsonResponse
    {
        return $this->itemResponse($this->toRow($this->findPage($page), detail: true));
    }

    /**
     * title/meta_description round-trip through SeoPathOverride — see the
     * class docblock for why. canonical/meta_keywords/in_sitemap are
     * accepted (so a partial PUT never 422s) but not persisted: gsc has no
     * admin-editable canonical, keyword or per-page sitemap-exclusion
     * mechanism for these built-in pages today — which is why the rows send
     * in_sitemap as null: the admin offers its sitemap switch only for a
     * value a site can actually change.
     */
    public function update(Request $request, string $page): JsonResponse
    {
        $entry = $this->findPage($page);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'canonical' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'meta_keywords' => ['sometimes', 'nullable', 'string'],
            'in_sitemap' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $data) || array_key_exists('meta_description', $data)) {
            $existing = SeoPathOverride::where('path', $entry['normalized'])->first();

            $title = array_key_exists('title', $data) ? $this->nullIfBlank($data['title']) : $existing?->title;
            $description = array_key_exists('meta_description', $data) ? $this->nullIfBlank($data['meta_description']) : $existing?->description;

            if ($title === null && $description === null) {
                // Nothing left to override — remove the row rather than
                // leave an empty husk sitting above the real default.
                $existing?->delete();
            } else {
                SeoPathOverride::updateOrCreate(
                    ['path' => $entry['normalized']],
                    ['title' => $title, 'description' => $description, 'source' => 'admin']
                );
            }
        }

        return $this->itemResponse($this->toRow($entry, detail: true));
    }

    public function store(): JsonResponse
    {
        return response()->json([
            'message' => "This site's pages are built into it; ask us to add one.",
        ], 422);
    }

    public function destroy(string $page): JsonResponse
    {
        // 404 for an id that names no real page — refusal is for a page
        // that exists but cannot be deleted, never a cover for "not found".
        $this->findPage($page);

        return response()->json([
            'message' => "This site's pages are built into it; ask us to add one.",
        ], 422);
    }

    /**
     * @return array<int, array{path: string, normalized: string, type: string, routeName: string, id: int, url: string}>
     */
    protected function allEntries(): array
    {
        $entries = [];
        foreach (self::PAGES as $path => [$routeName, $type]) {
            if (! RouteFacade::has($routeName)) {
                continue;
            }

            $normalized = SeoPathOverride::normalizePath($path);
            $entries[] = [
                'path' => $path,
                'normalized' => $normalized,
                'type' => $type,
                'routeName' => $routeName,
                'id' => crc32($normalized),
                'url' => route($routeName),
            ];
        }

        return $entries;
    }

    /** @return array{path: string, normalized: string, type: string, routeName: string, id: int, url: string} */
    protected function findPage(string $id): array
    {
        $needle = (int) $id;
        foreach ($this->allEntries() as $entry) {
            if ($entry['id'] === $needle) {
                return $entry;
            }
        }

        abort(404, 'Unknown page.');
    }

    protected function toRow(array $entry, bool $detail = false): array
    {
        $override = SeoPathOverride::where('path', $entry['normalized'])->first();
        $default = $this->computedDefault($entry['path']);

        $row = [
            'id' => $entry['id'],
            'title' => $override?->title ?? $default['title'],
            'meta_description' => $override?->description ?? $default['description'],
            'path' => $entry['normalized'],
            'url' => $entry['url'],
            'type' => $entry['type'],
            'in_sitemap' => null, // no per-page sitemap setting here: the admin shows no switch
            'updated_at' => optional($override?->updated_at)->toIso8601String(),
        ];

        if ($detail) {
            $row['canonical'] = null;
            $row['meta_keywords'] = null;
            $row['editable_body'] = false;
        }

        return $row;
    }

    /**
     * The title/description SeoService would set for this page right now,
     * computed by running the exact same call the real route makes against
     * a throwaway SEOBuilder — never the singleton any concurrently
     * rendering request might be using.
     *
     * @return array{title: ?string, description: ?string}
     */
    protected function computedDefault(string $path): array
    {
        $previous = app()->bound(SEOBuilder::class) ? app(SEOBuilder::class) : null;
        $builder = new SEOBuilder;
        app()->instance(SEOBuilder::class, $builder);

        try {
            match (true) {
                $path === '' => SeoService::home(),
                $path === 'about' => SeoService::about(),
                $path === 'contact' => SeoService::contact(),
                $path === 'services' => SeoService::services(),
                str_starts_with($path, 'services/') => SeoService::service(substr($path, strlen('services/'))),
                $path === 'areas-served' => SeoService::areasServed(),
                default => null,
            };

            $data = $builder->build();
        } finally {
            if ($previous) {
                app()->instance(SEOBuilder::class, $previous);
            } else {
                app()->forgetInstance(SEOBuilder::class);
            }
        }

        return ['title' => $data->title ?? null, 'description' => $data->description ?? null];
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
