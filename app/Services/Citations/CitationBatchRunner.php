<?php

namespace App\Services\Citations;

use App\Models\Citation;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Runs directories one after another in automatic (headless) mode: the
 * runner submits plain listing forms itself and parks everything that
 * needs a person. One remote browser at a time, so the batch is strictly
 * sequential; progress lives in the cache for the admin board.
 */
class CitationBatchRunner
{
    public const CACHE_KEY = 'citations.batch';

    public const ELIGIBLE = [Citation::STATUS_PLANNED, Citation::STATUS_FAILED, Citation::STATUS_UNREACHABLE];

    public function __construct(protected CitationSessionService $sessions) {}

    /** Directories a batch would touch, in tier order. */
    public function eligible(array $tiers = [], array $only = [], int $limit = 100): Collection
    {
        return Citation::query()->where('site_id', Site::current()?->id)
            ->whereIn('status', self::ELIGIBLE)
            ->when($tiers !== [], fn ($q) => $q->whereIn('tier', array_map('intval', $tiers)))
            ->when($only !== [], fn ($q) => $q->whereIn('slug', $only))
            ->orderBy('tier')->orderBy('name')->limit($limit)->get();
    }

    /**
     * Run one directory to completion (done, parked, or timed out).
     *
     * @return array{slug: string, status: string, note: ?string, reason: ?string, seconds: int}
     */
    public function runOne(Citation $citation): array
    {
        $started = time();
        $ttl = (int) config('citations.session.auto_ttl_seconds', 240);
        // One browser slot: if a session is still winding down (or a person
        // has the viewer open), wait for it rather than failing this row.
        $waitUntil = time() + $ttl + 60;
        while (($this->sessions->status()['running'] ?? false) && time() < $waitUntil) {
            $this->sleep(5);
        }
        if ($this->sessions->status()['running'] ?? false) {
            $citation->addLog('Skipped: another browser session was still running', 'batch');
            $citation->save();

            return ['slug' => $citation->slug, 'status' => $citation->status, 'note' => 'Another browser session was still running; left on the board.', 'reason' => null, 'seconds' => time() - $started];
        }
        $result = $this->sessions->start($citation, true, true);
        if (! ($result['ok'] ?? false)) {
            $citation->status = Citation::STATUS_FAILED;
            $citation->note = (string) ($result['error'] ?? 'Could not start the session.');
            $citation->addLog('Automatic run could not start: ' . $citation->note, 'batch');
            $citation->save();

            return ['slug' => $citation->slug, 'status' => $citation->status, 'note' => $citation->note, 'reason' => null, 'seconds' => 0];
        }
        $citation->status = Citation::STATUS_RUNNING;
        $citation->human_reason = null;
        $citation->last_run_at = now();
        $citation->addLog('Automatic run started', 'batch');
        $citation->save();

        $deadline = $started + $ttl + 45;
        while (time() < $deadline) {
            $this->sleep(3);
            $status = $this->sessions->status();
            if (! ($status['running'] ?? false)) {
                break;
            }
        }
        if (($this->sessions->status()['running'] ?? false)) {
            $this->sessions->stop();
        }
        $this->sessions->syncCitation($citation->refresh());
        if ($citation->status === Citation::STATUS_RUNNING) {
            $citation->status = Citation::STATUS_FAILED;
            $citation->note = 'The automatic run ended without a result.';
            $citation->save();
        }

        return ['slug' => $citation->slug, 'status' => $citation->status, 'note' => $citation->note, 'reason' => $citation->human_reason, 'seconds' => time() - $started];
    }

    /**
     * Batch progress lives in the shared storage/app (like the session state),
     * not the cache: a deploy clears the cache mid-batch, the job chain does not care.
     *
     * @param  list<string>  $remaining
     */
    public static function progress(?array $remaining, int $done, ?string $current = null, bool $active = true): void
    {
        $file = self::progressFile();
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, json_encode(['active' => $active, 'remaining' => $remaining ?? [], 'done' => $done, 'current' => $current, 'updated_at' => now()->toDateTimeString()]));
        Cache::forget(self::CACHE_KEY);
    }

    /** A batch is in flight when its progress file says so and moved within the last ten minutes. */
    public static function isActive(): bool
    {
        $p = self::progressState();

        return ! empty($p['active']) && ! empty($p['updated_at']) && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($p['updated_at'])) < 10;
    }

    public static function progressState(): array
    {
        $file = self::progressFile();
        $p = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        // A batch that has not moved for six hours is over, whatever the file says.
        if (is_array($p) && ! empty($p['active']) && ! empty($p['updated_at']) && now()->diffInHours(\Illuminate\Support\Carbon::parse($p['updated_at'])) >= 6) {
            $p['active'] = false;
        }

        return is_array($p) ? $p : ['active' => false, 'remaining' => [], 'done' => 0, 'current' => null, 'updated_at' => null];
    }

    protected static function progressFile(): string
    {
        return rtrim((string) (config('citations.storage_dir') ?: storage_path('app/citations')), '/') . '/batch.json';
    }

    protected function sleep(int $seconds): void
    {
        if (! app()->runningUnitTests()) {
            sleep($seconds);
        }
    }
}
