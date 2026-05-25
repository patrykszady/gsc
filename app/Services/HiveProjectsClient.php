<?php

namespace App\Services;

use App\Models\HiveProjectZipCount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HiveProjectsClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $token = null,
    ) {
        $this->baseUrl ??= (string) config('services.hive.url');
        $this->token ??= (string) config('services.hive.token');
    }

    /**
     * Read the locally-stored zip counts (no network), aggregated by zip.
     * Returns Collection<{zip: string, count: int}> sorted desc — for the map heatmap.
     */
    public function storedZipCounts(): Collection
    {
        return HiveProjectZipCount::query()
            ->selectRaw('zip, SUM(`count`) as total')
            ->groupBy('zip')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['zip' => $row->zip, 'count' => (int) $row->total]);
    }

    /**
     * Distinct zip codes currently stored.
     *
     * @return array<int, string>
     */
    public function storedZips(): array
    {
        return HiveProjectZipCount::query()
            ->select('zip')
            ->distinct()
            ->orderBy('zip')
            ->pluck('zip')
            ->all();
    }

    /**
     * Zip codes associated with a given city name (case-insensitive).
     *
     * @return array<int, string>
     */
    public function zipsForCity(string $city): array
    {
        $city = trim($city);
        if ($city === '') {
            return [];
        }

        return HiveProjectZipCount::query()
            ->whereRaw('LOWER(city) = ?', [mb_strtolower($city)])
            ->select('zip')
            ->distinct()
            ->orderBy('zip')
            ->pluck('zip')
            ->all();
    }

    /**
     * Timestamp of the most recent successful sync, or null if never synced.
     */
    public function lastSyncedAt(): ?Carbon
    {
        $value = HiveProjectZipCount::query()->max('synced_at');
        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Fetch fresh zip counts from hive.contractors and overwrite the local table.
     * Returns the number of rows persisted. Throws on failure.
     */
    public function sync(): int
    {
        if ($this->token === '' || $this->baseUrl === '') {
            throw new RuntimeException('HIVE_API_URL or HIVE_API_TOKEN is not configured.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->acceptJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->retry(2, 500, throw: false)
                ->get('/api/v1/projects/zip-counts');
        } catch (ConnectionException $e) {
            Log::error('HiveProjectsClient sync connection failure', ['error' => $e->getMessage()]);
            throw new RuntimeException('Could not reach hive.contractors: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            Log::error('HiveProjectsClient sync unexpected error', ['error' => $e->getMessage()]);
            throw new RuntimeException('Hive API call failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            Log::error('HiveProjectsClient sync non-2xx', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);
            throw new RuntimeException('Hive API returned HTTP ' . $response->status());
        }

        $rows = $response->json('data');
        if (!is_array($rows)) {
            throw new RuntimeException('Hive API returned malformed payload (no "data" array).');
        }

        $now = now();
        $records = collect($rows)
            ->map(function ($row) use ($now) {
                $zip = trim((string) ($row['zip'] ?? ''));
                $count = (int) ($row['count'] ?? 0);
                if ($zip === '' || $count <= 0) {
                    return null;
                }
                $city = isset($row['city']) ? trim((string) $row['city']) : '';
                $state = isset($row['state']) ? trim((string) $row['state']) : '';
                return [
                    'zip' => $zip,
                    'city' => $city !== '' ? $city : null,
                    'state' => $state !== '' ? $state : null,
                    'count' => $count,
                    'synced_at' => $now,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (empty($records)) {
            throw new RuntimeException('Hive API returned zero usable rows; refusing to wipe local table.');
        }

        DB::transaction(function () use ($records) {
            // Note: TRUNCATE causes an implicit commit in MySQL, so we use DELETE
            // here to stay inside the transaction for atomic replacement.
            HiveProjectZipCount::query()->delete();
            foreach (array_chunk($records, 500) as $chunk) {
                HiveProjectZipCount::query()->insert($chunk);
            }
        });

        return count($records);
    }
}
