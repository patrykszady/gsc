<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $query = ContactSubmission::query();

        if ($status = $request->string('status')->toString()) {
            // 'real' is the legacy admin's binary view of this column:
            // anything not marked spam (pending OR legitimate) reads as a
            // real lead. Exact values still work for direct API callers.
            $status === 'real'
                ? $query->where('status', '!=', 'spam')
                : $query->where('status', $status);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Restored from the legacy ContactSubmissions Livewire's dateFilter
        // (all|today|week|month) — see stats() below for the same windows.
        $this->applyDateRange($query, $request->string('date_range')->toString());

        $query = $this->applySort($query, $request->string('sort')->toString() ?: null, '-created_at');

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, fn (ContactSubmission $lead) => $lead->toApiArray());
    }

    public function show(int $lead): JsonResponse
    {
        $model = ContactSubmission::findOrFail($lead);

        return $this->itemResponse($model->toApiArray());
    }

    /**
     * Restored from the legacy monolith's ContactSubmissions Livewire
     * (app/Livewire/Admin/ContactSubmissions.php): the 5-card stats grid
     * plus the Top Cities / Traffic Sources (UTM) aggregate cards, computed
     * here with a server-side GROUP BY rather than shipped as raw rows.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => ContactSubmission::count(),
            'today' => ContactSubmission::whereDate('created_at', today())->count(),
            'week' => ContactSubmission::where('created_at', '>=', now()->subWeek())->count(),
            'month' => ContactSubmission::where('created_at', '>=', now()->subMonth())->count(),
            'spam' => ContactSubmission::where('status', 'spam')->count(),
            'top_cities' => ContactSubmission::query()
                ->selectRaw('city, COUNT(*) as count')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->groupBy('city')
                ->orderByDesc('count')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['label' => $row->city, 'count' => (int) $row->count])
                ->all(),
            'traffic_sources' => ContactSubmission::query()
                ->selectRaw('utm_source, COUNT(*) as count')
                ->whereNotNull('utm_source')
                ->where('utm_source', '!=', '')
                ->groupBy('utm_source')
                ->orderByDesc('count')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['label' => $row->utm_source, 'count' => (int) $row->count])
                ->all(),
        ];

        return $this->itemResponse($stats);
    }

    /** 'all' (default, no-op) | 'today' | 'week' | 'month'. */
    protected function applyDateRange($query, string $range): void
    {
        match ($range) {
            'today' => $query->whereDate('created_at', today()),
            'week' => $query->where('created_at', '>=', now()->subWeek()),
            'month' => $query->where('created_at', '>=', now()->subMonth()),
            default => null,
        };
    }

    public function updateStatus(Request $request, int $lead): JsonResponse
    {
        $model = ContactSubmission::findOrFail($lead);

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'spam', 'legitimate'])],
        ]);

        match ($data['status']) {
            'spam' => $model->markAsSpam('manual'),
            'legitimate' => $model->markAsReal(),
            'pending' => $model->update(['status' => 'pending']),
        };

        return $this->itemResponse($model->fresh()->toApiArray());
    }

    public function destroy(int $lead): Response
    {
        ContactSubmission::findOrFail($lead)->delete();

        return response()->noContent();
    }
}
