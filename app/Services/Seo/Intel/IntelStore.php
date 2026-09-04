<?php

namespace App\Services\Seo\Intel;

use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Persistence for the intelligence sources: snapshots per day, findings
 * keyed by fingerprint (opened / kept open / resolved), and the run ledger.
 */
class IntelStore
{
    public function ready(): bool
    {
        return Schema::hasTable('seo_intel_snapshots') && Schema::hasTable('seo_intel_findings') && Schema::hasTable('seo_intel_runs');
    }

    /** @param  Snapshot[]  $snapshots */
    public function save(string $family, string $runId, string $takenOn, array $snapshots): int
    {
        $n = 0;
        foreach ($snapshots as $s) {
            Tenancy::table('seo_intel_snapshots')->updateOrInsert(
                ['site_id' => $this->siteId(), 'family' => $family, 'kind' => $s->kind, 'subject' => mb_substr($s->subject, 0, 191), 'taken_on' => $takenOn],
                ['run_id' => $runId, 'metrics' => json_encode($s->metrics), 'payload' => json_encode($s->payload), 'updated_at' => now(), 'created_at' => now()]
            );
            $n++;
        }

        return $n;
    }

    /** @return array{taken_on: string, metrics: array, payload: array}|null */
    public function latest(string $family, string $kind, string $subject): ?array
    {
        $row = Tenancy::table('seo_intel_snapshots')->where('family', $family)->where('kind', $kind)->where('subject', mb_substr($subject, 0, 191))
            ->orderByDesc('taken_on')->first();

        return $row ? $this->hydrate($row) : null;
    }

    /** @return array{taken_on: string, metrics: array, payload: array}|null */
    public function previous(string $family, string $kind, string $subject): ?array
    {
        $latest = $this->latest($family, $kind, $subject);
        if (! $latest) {
            return null;
        }
        $row = Tenancy::table('seo_intel_snapshots')->where('family', $family)->where('kind', $kind)->where('subject', mb_substr($subject, 0, 191))
            ->where('taken_on', '<', $latest['taken_on'])->orderByDesc('taken_on')->first();

        return $row ? $this->hydrate($row) : null;
    }

    /** Latest day a kind was measured for the family, or null. */
    public function latestDay(string $family, ?string $kind = null): ?string
    {
        $q = Tenancy::table('seo_intel_snapshots')->where('family', $family);
        if ($kind !== null) {
            $q->where('kind', $kind);
        }
        $d = $q->max('taken_on');

        return $d ? substr((string) $d, 0, 10) : null;
    }

    /** The measured day before $day for a family/kind, or null. */
    public function previousDay(string $family, string $day, ?string $kind = null): ?string
    {
        $q = Tenancy::table('seo_intel_snapshots')->where('family', $family)->where('taken_on', '<', $day);
        if ($kind !== null) {
            $q->where('kind', $kind);
        }
        $d = $q->max('taken_on');

        return $d ? substr((string) $d, 0, 10) : null;
    }

    /** All subjects of a kind on the latest day it was measured, keyed by subject. */
    public function latestSet(string $family, string $kind): Collection
    {
        $day = $this->latestDay($family, $kind);

        return $day ? $this->set($family, $kind, $day) : collect();
    }

    /** All subjects of a kind on the day before the latest, keyed by subject. */
    public function previousSet(string $family, string $kind): Collection
    {
        $day = $this->latestDay($family, $kind);
        $prev = $day ? $this->previousDay($family, $day, $kind) : null;

        return $prev ? $this->set($family, $kind, $prev) : collect();
    }

    public function set(string $family, string $kind, string $day): Collection
    {
        return Tenancy::table('seo_intel_snapshots')->where('family', $family)->where('kind', $kind)->where('taken_on', $day)->get()
            ->mapWithKeys(fn ($r) => [$r->subject => $this->hydrate($r)]);
    }

    /**
     * Upsert this run's findings by fingerprint; resolve the family's open
     * findings the run no longer reports.
     *
     * @param  Finding[]  $findings
     * @return array{new: int, open: int, resolved: int}
     */
    public function saveFindings(string $family, string $runId, string $takenOn, array $findings): array
    {
        $seen = [];
        $new = 0;
        foreach ($findings as $f) {
            $fp = substr(sha1($family . '|' . $f->fingerprint), 0, 40);
            $seen[] = $fp;
            $existing = Tenancy::table('seo_intel_findings')->where('fingerprint', $fp)->first();
            $values = [
                'family' => $family, 'code' => mb_substr($f->code, 0, 60), 'severity' => $f->severity,
                'subject' => $f->subject !== null ? mb_substr($f->subject, 0, 191) : null, 'key' => $f->key,
                'title' => mb_substr($f->title, 0, 255), 'detail' => $f->detail,
                'delta' => $f->delta ? json_encode($f->delta) : null, 'action' => $f->action ? json_encode($f->action) : null,
                'last_seen_on' => $takenOn, 'last_run_id' => $runId, 'resolved_at' => null, 'updated_at' => now(),
            ];
            if ($existing) {
                // A finding that had been resolved and comes back starts a new streak.
                if ($existing->resolved_at !== null) {
                    $values['first_seen_on'] = $takenOn;
                    $new++;
                }
                Tenancy::table('seo_intel_findings')->where('id', $existing->id)->update($values);
            } else {
                Tenancy::table('seo_intel_findings')->insert($values + ['site_id' => $this->siteId(), 'fingerprint' => $fp, 'first_seen_on' => $takenOn, 'created_at' => now()]);
                $new++;
            }
        }
        $resolveQuery = Tenancy::table('seo_intel_findings')->where('family', $family)->whereNull('resolved_at');
        if ($seen) {
            $resolveQuery->whereNotIn('fingerprint', $seen);
        }
        $resolved = $resolveQuery->update(['resolved_at' => now(), 'updated_at' => now()]);

        return ['new' => $new, 'open' => count($seen), 'resolved' => (int) $resolved];
    }

    /** Open findings, most severe first. */
    public function openFindings(?string $family = null, int $limit = 50): Collection
    {
        $q = Tenancy::table('seo_intel_findings')->whereNull('resolved_at');
        if ($family !== null) {
            $q->where('family', $family);
        }
        $order = "case severity when 'critical' then 0 when 'warn' then 1 when 'win' then 2 else 3 end";

        return $q->orderByRaw($order)->orderByDesc('last_seen_on')->orderBy('id')->limit($limit)->get()->map(function ($r) {
            $r->delta = $r->delta ? json_decode((string) $r->delta, true) : [];
            $r->action = $r->action ? json_decode((string) $r->action, true) : null;

            return $r;
        });
    }

    public function recordRun(string $family, string $runId, string $takenOn, array $stats): void
    {
        Tenancy::table('seo_intel_runs')->updateOrInsert(
            ['site_id' => $this->siteId(), 'family' => $family, 'run_id' => $runId],
            array_merge([
                'taken_on' => $takenOn, 'cost' => 0, 'snapshots' => 0, 'findings_open' => 0, 'findings_new' => 0, 'findings_resolved' => 0,
                'duration_ms' => 0, 'error' => null, 'updated_at' => now(), 'created_at' => now(),
            ], $stats)
        );
    }

    /** Latest runs of a family, newest first. */
    public function runs(string $family, int $limit = 12): Collection
    {
        return Tenancy::table('seo_intel_runs')->where('family', $family)->orderByDesc('taken_on')->orderByDesc('id')->limit($limit)->get();
    }

    protected function hydrate(object $row): array
    {
        return [
            'taken_on' => substr((string) $row->taken_on, 0, 10),
            'run_id' => $row->run_id,
            'metrics' => (array) json_decode((string) $row->metrics, true),
            'payload' => (array) json_decode((string) $row->payload, true),
        ];
    }

    protected function siteId(): ?int
    {
        return Site::current()?->id;
    }
}
