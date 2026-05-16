<?php

namespace App\Console\Commands;

use App\Models\Tracked404;
use App\Services\IndexNowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Submit persistent 404 URLs to IndexNow so search engines re-crawl and
 * deindex them. We only submit URLs hit at least N times (default 3) and
 * not already submitted in the past --resubmit-days days.
 *
 * Use this to clean up stale Google index entries after URL changes,
 * deleted projects, or scraped/spam-discovered paths.
 */
class SubmitDead404sToIndexNow extends Command
{
    protected $signature = 'seo:404-indexnow {--min-hits=3} {--limit=200} {--resubmit-days=30} {--dry-run}';

    protected $description = 'Submit persistent 404 URLs to IndexNow for deindexing';

    public function handle(IndexNowService $indexNow): int
    {
        $minHits = (int) $this->option('min-hits');
        $limit = (int) $this->option('limit');
        $resubmitDays = (int) $this->option('resubmit-days');
        $dry = (bool) $this->option('dry-run');

        $query = Tracked404::query()
            ->where('hit_count', '>=', $minHits)
            ->where(function ($q) use ($resubmitDays) {
                $q->whereNull('indexnow_submitted_at')
                  ->orWhere('indexnow_submitted_at', '<', now()->subDays($resubmitDays));
            })
            ->orderByDesc('hit_count')
            ->limit($limit);

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('No qualifying 404s to submit.');
            return self::SUCCESS;
        }

        // Filter: skip URLs that now resolve (e.g., we restored them).
        $stillDead = [];
        foreach ($rows as $row) {
            $url = url($row->path);
            try {
                $resp = Http::timeout(5)->withoutRedirecting()->head($url);
                $code = $resp->status();
            } catch (\Throwable $e) {
                $code = 0;
            }
            if ($code === 404 || $code === 410) {
                $stillDead[] = $row;
            } else {
                $this->line("Skipping {$row->path} (now returns {$code})");
            }
        }

        if (empty($stillDead)) {
            $this->info('No URLs still 404 after live recheck.');
            return self::SUCCESS;
        }

        $urls = array_map(fn ($r) => url($r->path), $stillDead);
        $this->info('Submitting ' . count($urls) . ' dead URLs to IndexNow' . ($dry ? ' (dry-run)' : '') . '...');

        if ($dry) {
            foreach ($urls as $u) {
                $this->line(' - ' . $u);
            }
            return self::SUCCESS;
        }

        $ok = $indexNow->submitBatch($urls);
        if ($ok) {
            foreach ($stillDead as $row) {
                $row->forceFill(['indexnow_submitted_at' => now()])->save();
            }
            $this->info('Submitted ' . count($urls) . ' URLs.');
            return self::SUCCESS;
        }

        $this->error('IndexNow submission failed.');
        return self::FAILURE;
    }
}
