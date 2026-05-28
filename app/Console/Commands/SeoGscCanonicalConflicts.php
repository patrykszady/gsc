<?php

namespace App\Console\Commands;

use App\Models\GscCoverageState;
use App\Services\IndexNowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Surface URLs where Google picked a different canonical than what we declared via `<link rel="canonical">`
 * or via the Search Console-reported user canonical. This is the #1 cause of the GSC error
 * "Duplicate, Google chose different canonical than user" and silently dumps pages from the index.
 *
 * Pulls every row from `gsc_coverage_states` where `user_canonical` and `google_canonical` disagree
 * (after trailing-slash + protocol normalization) and writes a remediation report.
 *
 * Pass --warm to push affected URLs back through IndexNow + cache warmer so Googlebot recrawls
 * the *declared* canonical sooner.
 */
class SeoGscCanonicalConflicts extends Command
{
    protected $signature = 'seo:gsc-canonical-conflicts
        {--warm : Resubmit conflict URLs through IndexNow + warm cache}
        {--markdown : Write reports/gsc-canonical-conflicts.md}';

    protected $description = 'Report URLs where Google chose a different canonical than what we declared.';

    public function handle(IndexNowService $indexNow): int
    {
        $rows = GscCoverageState::query()
            ->whereNotNull('google_canonical')
            ->whereNotNull('user_canonical')
            ->orderBy('url')
            ->get()
            ->filter(fn ($r) => $this->normalize($r->user_canonical) !== $this->normalize($r->google_canonical));

        $this->info(sprintf('Found %d canonical conflicts.', $rows->count()));

        $conflicts = [];
        foreach ($rows as $r) {
            $this->line(sprintf(
                "  %s\n    user:    %s\n    google:  %s",
                $r->url, $r->user_canonical, $r->google_canonical
            ));
            $conflicts[] = [
                'url' => $r->url,
                'user_canonical' => $r->user_canonical,
                'google_canonical' => $r->google_canonical,
                'verdict' => $r->verdict,
                'coverage_state' => $r->coverage_state,
                'last_changed_at' => optional($r->last_changed_at)->toDateString(),
            ];
        }

        if ($this->option('warm') && $conflicts && $indexNow->isEnabled()) {
            $urls = array_unique(array_column($conflicts, 'user_canonical'));
            $this->info('Warming + IndexNow on ' . count($urls) . ' declared canonicals.');
            foreach ($urls as $u) {
                Http::timeout(15)->withHeaders([
                    'User-Agent' => 'GSConstruction-SEO-Warmer/1.0',
                    'Cache-Control' => 'no-cache',
                ])->get($u);
            }
            $indexNow->submitBatch($urls);
        }

        if ($this->option('markdown')) {
            $this->writeReport($conflicts);
        }

        return $conflicts ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Normalize URLs for canonical comparison: lowercase host, drop trailing slash (except root),
     * collapse http→https, strip default ports and trivial query reorder.
     */
    protected function normalize(?string $url): string
    {
        if (! $url) return '';
        $p = @parse_url($url);
        if (! $p || ! isset($p['host'])) return strtolower(trim($url));
        $scheme = 'https'; // canonical comparison ignores protocol delta
        $host = strtolower($p['host']);
        $path = $p['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) $path = rtrim($path, '/');
        return $scheme . '://' . $host . $path;
    }

    /**
     * @param array<int,array<string,mixed>> $conflicts
     */
    protected function writeReport(array $conflicts): void
    {
        $lines = [];
        $lines[] = '# GSC canonical conflicts';
        $lines[] = '';
        $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
        $lines[] = '';
        $lines[] = 'Pages where Google chose a different canonical than we declared. These often vanish from search results — Google attributes ranking signals to the canonical it chose, not ours.';
        $lines[] = '';
        $lines[] = '- Conflicts: **' . count($conflicts) . '**';
        $lines[] = '';
        if (empty($conflicts)) {
            $lines[] = '_No conflicts found. Run `seo:gsc-inspect-bulk` to populate state if this looks suspicious._';
        } else {
            $lines[] = '| URL | Declared canonical | Google canonical | Verdict | Coverage |';
            $lines[] = '|---|---|---|---|---|';
            foreach ($conflicts as $c) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s |',
                    $c['url'],
                    $c['user_canonical'],
                    $c['google_canonical'],
                    $c['verdict'] ?? '?',
                    $c['coverage_state'] ?? '?'
                );
            }
            $lines[] = '';
            $lines[] = '## Remediation playbook';
            $lines[] = '';
            $lines[] = '1. Confirm the declared canonical is the one you actually want indexed.';
            $lines[] = '2. If yes: strengthen signals → ensure internal links point at the declared URL (no trailing-slash mix), submit a fresh sitemap entry, and re-run with `--warm`.';
            $lines[] = '3. If no: update `<link rel="canonical">` to match Google\'s choice and re-deploy.';
            $lines[] = '4. Re-inspect with `php artisan seo:reindex-problem-pages --urls=<the URL>`.';
        }
        Storage::disk('local')->put('reports/gsc-canonical-conflicts.md', implode("\n", $lines));
        $this->info('Wrote reports/gsc-canonical-conflicts.md');
    }
}
