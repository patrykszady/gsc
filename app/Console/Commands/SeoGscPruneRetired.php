<?php

namespace App\Console\Commands;

use App\Models\GscCoverageState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Delete gsc_coverage_states rows whose URL is no longer in the sitemap.
 *
 * The inspection sweep only re-inspects sitemap URLs, so retired rows (removed
 * towns, old page variants) can never refresh — they linger forever as phantom
 * "problems" and permanently-stale entries, polluting the /admin/gsc-errors
 * dashboard and the autopilot's coverage-based synthesizers. If a URL returns
 * to the sitemap, the next sweep recreates its row automatically.
 */
class SeoGscPruneRetired extends Command
{
    protected $signature = 'seo:gsc-prune-retired {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Remove GSC coverage rows for URLs that are no longer in the sitemap.';

    public function handle(): int
    {
        $path = public_path('sitemap.xml');
        if (! is_file($path)) {
            $this->error('sitemap.xml not found — refusing to prune against an empty URL set.');

            return self::FAILURE;
        }

        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml || ! isset($xml->url) || count($xml->url) === 0) {
            $this->error('sitemap.xml unreadable or empty — refusing to prune.');

            return self::FAILURE;
        }

        $inSitemap = [];
        foreach ($xml->url as $u) {
            $inSitemap[(string) $u->loc] = true;
        }

        $dry = (bool) $this->option('dry-run');
        $deleted = 0;

        GscCoverageState::query()
            ->select(['id', 'url'])
            ->chunkById(500, function ($rows) use ($inSitemap, $dry, &$deleted): void {
                $ids = $rows->filter(fn ($r) => ! isset($inSitemap[(string) $r->url]))->pluck('id');
                if ($ids->isEmpty()) {
                    return;
                }
                if ($dry) {
                    $deleted += $ids->count();

                    return;
                }
                $deleted += GscCoverageState::query()->whereIn('id', $ids)->delete();
            });

        $this->info(($dry ? '[DRY RUN] Would prune ' : 'Pruned ') . $deleted . ' retired coverage row(s); sitemap has ' . count($inSitemap) . ' URLs.');

        if (! $dry && $deleted > 0) {
            Log::info('seo:gsc-prune-retired removed retired coverage rows', [
                'deleted' => $deleted,
                'sitemap_urls' => count($inSitemap),
            ]);
        }

        return self::SUCCESS;
    }
}
