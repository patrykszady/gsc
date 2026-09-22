<?php

namespace App\Support\Seo\Inspection;

use App\Models\GscCoverageState;
use App\Models\GscCoverageStateHistory;
use App\Models\GscRichResultIssue;
use Illuminate\Support\Carbon;
use SsSystems\Platform\Seo\Inspection\Contracts\CoverageStore;

/**
 * The kit's CoverageStore over this site's own gsc_coverage_states,
 * gsc_coverage_state_history and gsc_rich_result_issues tables — every
 * method here is the same Eloquent call the original command's
 * persist()/persistRichResults()/writeReport() made directly, just behind
 * the interface UrlInspectionSweep now calls through.
 *
 * upsert()/find() go through GscCoverageState::updateOrCreate()/where()
 * (Eloquent), so BelongsToSite's global scope and creating() site_id stamp
 * apply exactly as before. replaceRichResultIssues() keeps the original's
 * raw GscRichResultIssue::query()->insert($rows) for the bulk write — a
 * query-builder insert, which does NOT run Eloquent's creating() hook, so
 * those rows land with a NULL site_id exactly as they always have (see
 * BelongsToSite's docblock: a NULL site_id is treated as belonging to every
 * site). This is the original command's own behaviour, not something new.
 */
final class EloquentCoverageStore implements CoverageStore
{
    public function knownUrls(): array
    {
        return GscCoverageState::query()->orderBy('inspected_at')->pluck('url')->all();
    }

    public function knownUrlsAmong(array $urls): array
    {
        return GscCoverageState::query()
            ->whereIn('url', $urls)
            ->orderBy('inspected_at')
            ->pluck('url')
            ->all();
    }

    public function find(string $url): ?array
    {
        $row = GscCoverageState::query()->where('url', $url)->first();

        if (! $row) {
            return null;
        }

        return [
            'url' => $row->url,
            'source' => $row->source,
            'console_reason' => $row->console_reason,
            'verdict' => $row->verdict,
            'coverage_state' => $row->coverage_state,
            'robots_txt_state' => $row->robots_txt_state,
            'indexing_state' => $row->indexing_state,
            'page_fetch_state' => $row->page_fetch_state,
            'sitemap_url' => $row->sitemap_url,
            'last_crawl_time' => $row->last_crawl_time?->toIso8601String(),
            'user_canonical' => $row->user_canonical,
            'google_canonical' => $row->google_canonical,
            'inspected_at' => $row->inspected_at?->toIso8601String(),
            'last_changed_at' => $row->last_changed_at?->toIso8601String(),
            'consecutive_failures' => (int) $row->consecutive_failures,
        ];
    }

    public function upsert(array $row): void
    {
        GscCoverageState::query()->updateOrCreate(
            ['url' => $row['url']],
            [
                'source' => $row['source'],
                'console_reason' => $row['console_reason'],
                'verdict' => $row['verdict'],
                'coverage_state' => $row['coverage_state'],
                'robots_txt_state' => $row['robots_txt_state'],
                'indexing_state' => $row['indexing_state'],
                'page_fetch_state' => $row['page_fetch_state'],
                'sitemap_url' => $row['sitemap_url'],
                'last_crawl_time' => $row['last_crawl_time'],
                'user_canonical' => $row['user_canonical'],
                'google_canonical' => $row['google_canonical'],
                'inspected_at' => $row['inspected_at'],
                'last_changed_at' => $row['last_changed_at'],
                'consecutive_failures' => $row['consecutive_failures'],
            ]
        );
    }

    /**
     * Best-effort: never let a bookkeeping write fail the sweep. Kept
     * defensive even though this app's gsc_coverage_state_history table is
     * present today (its migration is guarded and has run here) — see the
     * porting report for what the original command actually did on this
     * write.
     */
    public function recordHistory(array $row): void
    {
        try {
            GscCoverageStateHistory::query()->create([
                'url' => $row['url'],
                'verdict' => $row['verdict'],
                'coverage_state' => $row['coverage_state'],
                'page_fetch_state' => $row['page_fetch_state'],
                'observed_at' => $row['observed_at'],
            ]);
        } catch (\Throwable) {
            // Never let history bookkeeping fail the sweep.
        }
    }

    public function replaceRichResultIssues(string $url, array $issues): void
    {
        GscRichResultIssue::query()->where('url', $url)->delete();

        if ($issues === []) {
            return;
        }

        $rows = [];
        $now = Carbon::now();

        foreach ($issues as $issue) {
            $rows[] = [
                'url' => $url,
                'rich_result_type' => $issue['rich_result_type'],
                'issue_severity' => $issue['issue_severity'],
                'issue_type' => $issue['issue_type'],
                'issue_message' => $issue['issue_message'],
                'verdict' => $issue['verdict'],
                'inspected_at' => Carbon::parse($issue['inspected_at']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        GscRichResultIssue::query()->insert($rows);
    }

    public function verdictCoverageTotals(): array
    {
        return GscCoverageState::query()
            ->selectRaw('verdict, coverage_state, count(*) as n')
            ->groupBy('verdict', 'coverage_state')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($t) => ['verdict' => $t->verdict, 'coverage_state' => $t->coverage_state, 'n' => (int) $t->n])
            ->all();
    }
}
