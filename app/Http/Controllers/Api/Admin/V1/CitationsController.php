<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\Citation;
use App\Support\Citations\ListingPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SsSystems\Platform\Citations\CitationsAdminActions;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Thin HTTP adapter over the kit's `Citations\CitationsAdminActions`
 * (citations-admin-actions, 2026-09-27 — moved from this controller,
 * which the 2026-09-27 audit found cosmetic-only against jpeterson's copy;
 * see citations.md #8 and that service's own docblock for the exact
 * behavior, unchanged by this port). This class only validates the
 * request, calls the service, and wraps this app's own `{"data": ...}`
 * envelope — `payload()` (per-site `ListingPayload::make()`) and
 * `screenshot()` (a file response, not JSON) are the two actions that stay
 * here in full, since neither is shared logic.
 */
class CitationsController extends Controller
{
    use BuildsApiResponses;

    public function __construct(protected CitationsAdminActions $service) {}

    public function index(): JsonResponse
    {
        return $this->itemResponse($this->service->index());
    }

    /** Queue the automatic run over every open directory (optionally some tiers only). */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate(['tiers' => ['nullable', 'array'], 'tiers.*' => ['integer', 'between:0,3'], 'only' => ['nullable', 'array'], 'only.*' => ['string', 'max:60']]);

        return $this->itemResponse($this->service->batch((array) ($data['tiers'] ?? []), (array) ($data['only'] ?? [])));
    }

    public function payload(): JsonResponse
    {
        return $this->itemResponse(ListingPayload::make());
    }

    public function start(Request $request, string $slug): JsonResponse
    {
        return $this->itemResponse($this->service->start($slug, $request->boolean('headless')));
    }

    public function poll(): JsonResponse
    {
        return $this->itemResponse($this->service->poll());
    }

    public function resume(string $slug): JsonResponse
    {
        return $this->itemResponse($this->service->resume($slug));
    }

    public function stop(): JsonResponse
    {
        return $this->itemResponse($this->service->stop());
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Citation::STATUSES)],
            'listing_url' => ['nullable', 'url', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'account_email' => ['nullable', 'email', 'max:191'],
        ]);

        return $this->itemResponse($this->service->update($slug, $data));
    }

    public function screenshot(string $slug, string $file): BinaryFileResponse
    {
        $citation = $this->service->find($slug);
        abort_unless(preg_match('/^[a-z0-9._-]+\.(png|jpg)$/i', $file), 404);
        $path = $this->service->sessionDir($citation).'/shots/'.$file;
        abort_unless(is_file($path), 404);

        return response()->file($path);
    }
}
