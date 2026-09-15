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

        // How the enquiry reached us: web (the site form), crew-email (the
        // shared inbox), yelp (Request a Quote) — the same values the
        // Source column badges.
        if ($source = $request->string('source')->toString()) {
            $query->where('source', $source);
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

    /**
     * A lead hive captured itself — crew@ inbox, Angi, Houzz, hive's own form —
     * pushed here the moment it exists, so every lead starts on ss.systems
     * whatever channel it came through. Identity is (source, hive_lead_id),
     * exactly as leads:pull-from-hive matches; a second push updates in place.
     * The row is born already forwarded (hive_lead_id set), so nothing sends
     * it back to hive.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hive_lead_id' => ['required', 'integer', 'min:1'],
            'source' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'message' => ['nullable', 'string', 'max:20000'],
            'subject' => ['nullable', 'string', 'max:255'],
            // Hive's own identity for the lead. For an email that is the
            // RFC Message-ID hash this site's reader would compute too.
            'external_id' => ['nullable', 'string', 'max:64'],
            'received_at' => ['nullable', 'date'],
        ]);

        $isEmail = $data['source'] === \App\Services\EmailLeadReader::SOURCE;
        $identity = $isEmail && ! empty($data['external_id']) ? (string) $data['external_id'] : null;

        $existing = ContactSubmission::query()
            ->where('source', $data['source'])
            ->where('hive_lead_id', (int) $data['hive_lead_id'])
            ->first();

        // An email this site read first is already here without a hive id:
        // this push is hive answering it, not a second enquiry.
        if (! $existing && $identity !== null) {
            $existing = ContactSubmission::query()
                ->where('source', $data['source'])
                ->where('email_message_id', $identity)
                ->first();
        }

        $attributes = [
            'name' => \Illuminate\Support\Str::limit((string) ($data['name'] ?? 'Unknown'), 250, ''),
            'email' => \Illuminate\Support\Str::limit((string) ($data['email'] ?? ''), 250, ''),
            'phone' => ! empty($data['phone']) ? \Illuminate\Support\Str::limit((string) $data['phone'], 20, '') : null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'message' => (string) ($data['message'] ?? ''),
            'subject' => isset($data['subject']) ? \Illuminate\Support\Str::limit((string) $data['subject'], 255, '') : null,
            'source' => $data['source'],
            'hive_lead_id' => (int) $data['hive_lead_id'],
            'hive_sent_at' => now(),
        ] + ($identity !== null ? ['email_message_id' => $identity] : []);

        if ($existing) {
            $existing->fill($attributes)->save();

            return $this->itemResponse($existing->fresh()->toApiArray());
        }

        $submission = ContactSubmission::create($attributes + ['status' => 'pending']);
        // Filed when it arrived, not when it was pushed — the admin sorts by created_at.
        $submission->forceFill(['created_at' => ! empty($data['received_at']) ? \Illuminate\Support\Carbon::parse($data['received_at']) : now()])->saveQuietly();

        return $this->itemResponse($submission->fresh()->toApiArray(), 201);
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
            // Every channel with its count, for the Source filter's options
            // — so the admin lists what actually exists, not a guessed list.
            'sources' => ContactSubmission::query()
                ->selectRaw('source, COUNT(*) as count')
                ->whereNotNull('source')
                ->where('source', '!=', '')
                ->groupBy('source')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($row) => ['source' => $row->source, 'count' => (int) $row->count])
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
