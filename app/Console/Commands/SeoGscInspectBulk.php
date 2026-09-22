<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Seo\Inspection\UrlInspectionSweep;

/**
 * Thin wrapper over the kit's UrlInspectionSweep (SsSystems\Platform\Seo\
 * Inspection\UrlInspectionSweep — ported verbatim from this command's own
 * former handle()/offSitemapUrls()/prioritize()/persist()/
 * persistRichResults()/writeReport(); see that class's docblock and
 * docs/INSPECTION-SWEEP.md in the kit's source repo). This class only maps
 * its own --options into the sweep's options array, prints the lines it
 * hands back, and writes the markdown report when asked — the same shape
 * the ten SEO report commands were reduced to earlier.
 *
 * The sweep is built from four small adapters this site provides —
 * App\Support\Seo\Inspection\{SearchConsoleUrlInspector, FileSitemapSource,
 * EloquentCoverageStore, TrackedPathsFromModel} — plus a per-tenant
 * UrlInspectionQuota instance, all bound in AppServiceProvider.
 */
class SeoGscInspectBulk extends Command
{
    protected $signature = 'seo:gsc-inspect-bulk
        {--limit=0 : Maximum URLs to inspect this run (0 = all sitemap URLs)}
        {--sitemap= : Path to sitemap XML (default: the generated sitemap for this site)}
        {--strategy=stale : URL selection: stale|random|all}
        {--include=sitemap,coverage,tracked : Pools to sweep: sitemap, coverage (rows the sitemap no longer carries), tracked (paths Googlebot 404s on)}
        {--urls=* : Inspect these URLs instead of the sitemap (a Console export, tracked 404s)}
        {--source=sitemap : What the rows are: sitemap|console|tracked}
        {--reason= : The Console reason the URLs were exported under, kept on each row}
        {--site= : GSC site URL override}
        {--markdown : Write reports/gsc-inspect-bulk.md}';

    protected $description = 'Bulk-run GSC URL Inspection against the sitemap and persist coverage state.';

    public function handle(UrlInspectionSweep $sweep): int
    {
        $result = $sweep->run([
            'limit' => (int) $this->option('limit'),
            'sitemap' => $this->option('sitemap'),
            'strategy' => $this->option('strategy'),
            'include' => $this->option('include'),
            'urls' => $this->option('urls'),
            'source' => $this->option('source'),
            'reason' => $this->option('reason'),
            'site' => $this->option('site'),
        ]);

        foreach ($result->lines as $line) {
            $this->line($line);
        }

        if ($this->option('markdown')) {
            Storage::disk('local')->put('reports/gsc-inspect-bulk.md', $result->markdown);
            $this->info('Wrote reports/gsc-inspect-bulk.md');
        }

        // The original command's only FAILURE exit is an empty pool; a spent
        // allowance and a mid-run 429 both exit SUCCESS, same as it did.
        // SweepResult carries no status field (see its own docblock), so the
        // wrapper reads `lines` for this one case.
        if ($result->lines === ['Loaded 0 sitemap URLs.', 'Nothing to inspect.']
            || $result->lines === ['Nothing to inspect.']
            || in_array(UrlInspectionSweep::NOT_CONNECTED_LINE, $result->lines, true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
