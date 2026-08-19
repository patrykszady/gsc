<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\ClientError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Management API for gsc's Livewire\Admin\ClientErrors screen (aggregated,
 * deduped JS error records — see App\Http\Controllers\ClientErrorController
 * for the public ingest beacon this data comes from; deliberately a
 * different class, under Api\Admin\V1, so the two never collide).
 */
class ClientErrorAdminController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $query = $this->applyFilters(ClientError::query(), $request)->orderByDesc('last_seen_at');

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, fn (ClientError $error) => $error->toApiArray());
    }

    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'open' => ClientError::whereNull('resolved_at')->count(),
                'occurrences' => (int) ClientError::whereNull('resolved_at')->sum('occurrences'),
                'last_24h' => ClientError::where('last_seen_at', '>=', now()->subDay())->count(),
                'resolved' => ClientError::whereNotNull('resolved_at')->count(),
            ],
        ]);
    }

    public function resolve(int $clientError): JsonResponse
    {
        $error = ClientError::findOrFail($clientError);
        $error->update(['resolved_at' => now()]);

        return response()->json(['data' => $error->fresh()->toApiArray()]);
    }

    public function unresolve(int $clientError): JsonResponse
    {
        $error = ClientError::findOrFail($clientError);
        $error->update(['resolved_at' => null]);

        return response()->json(['data' => $error->fresh()->toApiArray()]);
    }

    public function destroy(int $clientError): Response
    {
        ClientError::findOrFail($clientError)->delete();

        return response()->noContent();
    }

    public function resolveAll(): JsonResponse
    {
        $count = ClientError::whereNull('resolved_at')->count();
        ClientError::whereNull('resolved_at')->update(['resolved_at' => now()]);

        return response()->json(['data' => ['resolved_count' => $count]]);
    }

    protected function applyFilters($query, Request $request)
    {
        $status = $request->string('status')->toString() ?: 'open';
        $kind = $request->string('kind')->toString();

        return $query
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($kind && $kind !== 'all', fn ($q) => $q->where('kind', $kind));
    }
}
