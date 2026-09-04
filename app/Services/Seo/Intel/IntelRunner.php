<?php

namespace App\Services\Seo\Intel;

use App\Services\DataForSeoService;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;

/**
 * Runs intelligence sources: collect → store today's snapshots → compare with
 * the previous run → open/resolve findings → ledger the cost.
 */
class IntelRunner
{
    public const CACHE_KEY = 'seo_reports_intel_v1';

    public function __construct(protected DataForSeoService $dfs, protected IntelStore $store) {}

    /**
     * Registered sources, keyed by family. Classes that do not exist (yet)
     * are skipped so the registry can list families ahead of their code.
     *
     * @param  list<string>|null  $only  families to keep
     * @return array<string, IntelSource>
     */
    public function sources(?array $only = null): array
    {
        $out = [];
        foreach ((array) config('seo-intel.sources', []) as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, IntelSource::class)) {
                continue;
            }
            /** @var IntelSource $source */
            $source = app()->make($class, ['dfs' => $this->dfs, 'store' => $this->store]);
            if ($only === null || in_array($source->family(), $only, true)) {
                $out[$source->family()] = $source;
            }
        }

        return $out;
    }

    /**
     * @return array{family: string, ok: bool, cost: float, snapshots: int, findings: array{new: int, open: int, resolved: int}, error: ?string, duration_ms: int}
     */
    public function run(IntelSource $source, bool $dryRun = false, bool $findingsOnly = false): array
    {
        $family = $source->family();
        $runId = now()->format('Ymd-His');
        $takenOn = now()->toDateString();
        $started = microtime(true);
        $spentBefore = $this->dfs->spent();
        $stats = ['new' => 0, 'open' => 0, 'resolved' => 0];
        $count = 0;
        $error = null;

        try {
            if (! $findingsOnly) {
                $snapshots = $source->collect();
                $count = count($snapshots);
                if ($count === 0 && $this->dfs->getLastError()) {
                    $error = $this->dfs->getLastError();
                }
                if (! $dryRun) {
                    $this->store->save($family, $runId, $takenOn, $snapshots);
                }
            }
            if (! $dryRun) {
                $stats = $this->store->saveFindings($family, $runId, $takenOn, $source->findings());
            }
        } catch (\Throwable $e) {
            $error = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 500);
            report($e);
        }

        $cost = round($this->dfs->spent() - $spentBefore, 4);
        $duration = (int) round((microtime(true) - $started) * 1000);
        if (! $dryRun && ! $findingsOnly) {
            $this->store->recordRun($family, $runId, $takenOn, [
                'cost' => $cost, 'snapshots' => $count, 'findings_open' => $stats['open'], 'findings_new' => $stats['new'],
                'findings_resolved' => $stats['resolved'], 'duration_ms' => $duration, 'error' => $error,
            ]);
            Cache::forget(Tenancy::cacheKey(self::CACHE_KEY));
        }

        return ['family' => $family, 'ok' => $error === null, 'cost' => $cost, 'snapshots' => $count, 'findings' => $stats, 'error' => $error, 'duration_ms' => $duration];
    }
}
