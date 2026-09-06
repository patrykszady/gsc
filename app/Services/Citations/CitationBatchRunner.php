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

    /** @param  list<string>  $remaining */
    public static function progress(?array $remaining, int $done, ?string $current = null, bool $active = true): void
    {
        Cache::put(self::CACHE_KEY, ['active' => $active, 'remaining' => $remaining ?? [], 'done' => $done, 'current' => $current, 'updated_at' => now()->toDateTimeString()], now()->addHours(6));
    }

    public static function progressState(): array
    {
        $p = Cache::get(self::CACHE_KEY);

        return is_array($p) ? $p : ['active' => false, 'remaining' => [], 'done' => 0, 'current' => null, 'updated_at' => null];
    }

    protected function sleep(int $seconds): void
    {
        if (! app()->runningUnitTests()) {
            sleep($seconds);
        }
    }
}
