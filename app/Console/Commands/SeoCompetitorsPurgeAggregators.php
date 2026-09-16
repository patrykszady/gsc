<?php

namespace App\Console\Commands;

use App\Support\Seo\CompetitorFilter;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup for the SEO intel data an aggregator/directory domain
 * leaked into before CompetitorFilter existed to stop it at the source:
 *
 *  - Findings that named a directory "a competitor" (serp.competitor_top3,
 *    labs.keyword_gap, labs.new_competitor) are RESOLVED (resolved_at set),
 *    never deleted — mirrors IntelStore::saveFindings' own resolve semantics.
 *  - labs "competitor" snapshots for an aggregator or a giant (by its own
 *    stored metrics) are hard-DELETED — pure re-derivable cache with no
 *    status column, repopulated correctly every run now that the writer is
 *    filtered.
 *  - map_pack_competitors rows whose host is an aggregator have host set to
 *    NULL — the business (name/pack_points/reviews/rating/site_*) is real
 *    and untouched; only the host-as-a-competitor-domain misuse is undone.
 *
 * Dry-run by default: computes every change and prints it, writes nothing.
 * --apply performs all three write groups in one transaction. Idempotent —
 * every condition becomes false once applied, so a second --apply finds
 * nothing left to do.
 */
class SeoCompetitorsPurgeAggregators extends Command
{
    protected $signature = 'seo:competitors-purge-aggregators {--apply : perform the changes; default is dry-run and makes zero writes}';

    protected $description = 'Resolve findings / delete snapshots / clear map_pack_competitors.host where an aggregator domain was miscategorized as a local competitor';

    public function handle(): int
    {
        $findings = $this->findingsToResolve();
        $snapshots = $this->snapshotsToDelete();
        $mapPack = $this->mapPackHostsToClear();

        $this->summarize($findings, $snapshots, $mapPack);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry run — nothing written. Re-run with --apply to perform these changes.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($findings, $snapshots, $mapPack): void {
            if ($findings->isNotEmpty()) {
                Tenancy::table('seo_intel_findings')->whereIn('id', $findings->pluck('id'))
                    ->update(['resolved_at' => now(), 'updated_at' => now()]);
            }
            if ($snapshots->isNotEmpty()) {
                Tenancy::table('seo_intel_snapshots')->whereIn('id', $snapshots->pluck('id'))->delete();
            }
            if ($mapPack->isNotEmpty()) {
                Tenancy::table('map_pack_competitors')->whereIn('id', $mapPack->pluck('id'))
                    ->update(['host' => null, 'updated_at' => now()]);
            }
        });

        $this->newLine();
        $this->info('Applied.');

        return self::SUCCESS;
    }

    /** Open findings that named an aggregator "a competitor". */
    protected function findingsToResolve(): Collection
    {
        $rows = collect();

        // 1. serp.competitor_top3 — key is the offending domain directly.
        $rows = $rows->concat(
            Tenancy::table('seo_intel_findings')
                ->where('family', 'serp')->where('code', 'serp.competitor_top3')->whereNull('resolved_at')->get()
                ->filter(fn ($f) => $f->key !== null && CompetitorFilter::isAggregator((string) $f->key))
        );

        // 2. labs.keyword_gap — key is intentionally null (see LabsSource);
        // extract the leading domain token from detail's fixed sprintf format.
        $rows = $rows->concat(
            Tenancy::table('seo_intel_findings')
                ->where('family', 'labs')->where('code', 'labs.keyword_gap')->whereNull('resolved_at')->get()
                ->filter(fn ($f) => preg_match('/^(\S+) ranks #/', (string) $f->detail, $m) === 1 && CompetitorFilter::isAggregator($m[1]))
        );

        // 3. labs.new_competitor — subject is the domain directly.
        $rows = $rows->concat(
            Tenancy::table('seo_intel_findings')
                ->where('family', 'labs')->where('code', 'labs.new_competitor')->whereNull('resolved_at')->get()
                ->filter(fn ($f) => $f->subject !== null && CompetitorFilter::isAggregator((string) $f->subject))
        );

        return $rows->values();
    }

    /** Cached labs "competitor" snapshots for an aggregator, or a giant by its own stored metrics. */
    protected function snapshotsToDelete(): Collection
    {
        return Tenancy::table('seo_intel_snapshots')
            ->where('family', 'labs')->where('kind', 'competitor')->get()
            ->filter(function ($row) {
                if (CompetitorFilter::isAggregator((string) $row->subject)) {
                    return true;
                }
                $metrics = (array) json_decode((string) $row->metrics, true);

                return CompetitorFilter::isGiant(
                    isset($metrics['organic_count']) ? (float) $metrics['organic_count'] : null,
                    isset($metrics['organic_etv']) ? (float) $metrics['organic_etv'] : null,
                );
            })
            ->values();
    }

    /** map_pack_competitors rows whose host is an aggregator — the row stays, host is cleared. */
    protected function mapPackHostsToClear(): Collection
    {
        return Tenancy::table('map_pack_competitors')
            ->whereNotNull('host')->get()
            ->filter(fn ($row) => CompetitorFilter::isAggregator((string) $row->host))
            ->values();
    }

    protected function summarize(Collection $findings, Collection $snapshots, Collection $mapPack): void
    {
        $this->line(sprintf('Findings to resolve: %d', $findings->count()));
        if ($findings->isNotEmpty()) {
            $this->table(
                ['id', 'family', 'code', 'subject', 'key'],
                $findings->map(fn ($f) => [$f->id, $f->family, $f->code, $f->subject, $f->key])->all()
            );
        }

        $this->line(sprintf('Snapshots to delete: %d', $snapshots->count()));
        if ($snapshots->isNotEmpty()) {
            $this->table(['id', 'subject'], $snapshots->map(fn ($s) => [$s->id, $s->subject])->all());
        }

        $this->line(sprintf('map_pack_competitors hosts to clear: %d', $mapPack->count()));
        if ($mapPack->isNotEmpty()) {
            $this->table(['id', 'name', 'host'], $mapPack->map(fn ($m) => [$m->id, $m->name, $m->host])->all());
        }
    }
}
