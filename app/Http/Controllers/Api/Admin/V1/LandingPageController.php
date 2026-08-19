<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Services\Seo\LandingPageContentGenerator;
use App\Services\Seo\TitleMetaGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Management API for gsc's Livewire\Admin\LandingPages screen — demand-driven
 * /remodeling/ pages. Generation and publish stay proof-gated exactly as the
 * original component enforced; only the transport changed.
 */
class LandingPageController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $query = LandingPage::query()->orderByDesc('created_at');

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, fn (LandingPage $page) => $page->toApiArray());
    }

    /** The fixed service catalogue the generate form offers — see TitleMetaGenerator::SERVICES. */
    public function services(): JsonResponse
    {
        return response()->json(['data' => TitleMetaGenerator::SERVICES]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:255'],
            'modifier' => ['nullable', 'string', 'max:100'],
        ]);

        $content = (new LandingPageContentGenerator)->build(
            $data['service'],
            trim($data['city']),
            $data['modifier'] ?? null,
        );

        if ($content === null) {
            return response()->json([
                'errors' => [
                    'service' => ["No matching project proof for {$data['service']} — can't build a non-thin page. Add a relevant project first."],
                ],
            ], 422);
        }

        if (LandingPage::where('slug', $content['slug'])->exists()) {
            return response()->json([
                'errors' => [
                    'city' => ["A page already exists at /remodeling/{$content['slug']}."],
                ],
            ], 422);
        }

        $page = LandingPage::create(array_merge($content, [
            'source' => 'manual',
            'status' => LandingPage::STATUS_DRAFT,
        ]));

        return $this->itemResponse($page->toApiArray(), 201);
    }

    public function publish(int $landingPage): JsonResponse
    {
        $page = LandingPage::findOrFail($landingPage);

        if (! $page->hasProof()) {
            return response()->json([
                'errors' => ['page' => ['Cannot publish a page with no project proof.']],
            ], 422);
        }

        $page->update(['status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);
        $this->regenerateSitemapQuietly();

        return $this->itemResponse($page->fresh()->toApiArray());
    }

    public function unpublish(int $landingPage): JsonResponse
    {
        $page = LandingPage::findOrFail($landingPage);

        $page->update(['status' => LandingPage::STATUS_DRAFT, 'published_at' => null]);
        $this->regenerateSitemapQuietly();

        return $this->itemResponse($page->fresh()->toApiArray());
    }

    public function destroy(int $landingPage): Response
    {
        LandingPage::findOrFail($landingPage)->delete();

        return response()->noContent();
    }

    /** Sitemap can be regenerated manually; don't block the response on it. */
    private function regenerateSitemapQuietly(): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Throwable) {
            // Intentionally silent — same as the original Livewire component.
        }
    }
}
