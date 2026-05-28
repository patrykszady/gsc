<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesSearchConsoleApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pull every sitemap currently registered against the Search Console property and surface
 * submission status, last-downloaded timestamp, contained URL count, and warning/error counts.
 *
 * Catches the silent-failure mode where GSC stops processing the sitemap (parse error, 404,
 * stale `lastSubmitted`) and we'd never know without manually clicking into the UI.
 */
class SeoGscSitemapStatus extends Command
{
    use UsesSearchConsoleApi;

    protected $signature = 'seo:gsc-sitemap-status
        {--site= : GSC site URL override}
        {--max-age-days=10 : Warn when sitemap has not been downloaded within N days}
        {--markdown : Write reports/gsc-sitemap-status.md}';

    protected $description = 'Check sitemap submission status, errors, warnings, and freshness in Search Console.';

    public function handle(): int
    {
        $token = $this->gscAccessToken();
        if (! $token) return self::FAILURE;

        $site = $this->gscSiteUrl($this->option('site'));
        $resp = Http::withToken($token)->timeout(30)->get(
            'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/sitemaps'
        );
        if (! $resp->successful()) {
            $this->error('Sitemaps API failed: ' . $resp->body());
            return self::FAILURE;
        }

        $sitemaps = $resp->json()['sitemap'] ?? [];
        if (empty($sitemaps)) {
            $this->warn('No sitemaps registered in Search Console for ' . $site);
            $this->line('Submit one: Search Console → Sitemaps → enter "sitemap.xml"');
            return self::FAILURE;
        }

        $maxAgeDays = (int) $this->option('max-age-days');
        $stale = [];
        $errored = [];
        $rows = [];

        foreach ($sitemaps as $s) {
            $path = $s['path'] ?? '?';
            $type = $s['type'] ?? '?';
            $lastSubmitted = $s['lastSubmitted'] ?? null;
            $lastDownloaded = $s['lastDownloaded'] ?? null;
            $errors = (int) ($s['errors'] ?? 0);
            $warnings = (int) ($s['warnings'] ?? 0);
            $isPending = $s['isPending'] ?? false;
            $isSitemapsIndex = $s['isSitemapsIndex'] ?? false;
            $contents = $s['contents'] ?? [];
            $urls = 0;
            foreach ($contents as $c) {
                $urls += (int) ($c['submitted'] ?? 0);
            }

            $ageDays = $lastDownloaded ? now()->diffInDays(\Carbon\Carbon::parse($lastDownloaded), false) * -1 : null;
            $isStale = $ageDays !== null && $ageDays > $maxAgeDays;

            $rows[] = compact('path', 'type', 'lastSubmitted', 'lastDownloaded', 'ageDays',
                'errors', 'warnings', 'isPending', 'isSitemapsIndex', 'urls');

            $flag = '✓';
            if ($errors > 0) { $flag = '✗'; $errored[] = $path; }
            elseif ($warnings > 0) { $flag = '⚠'; }
            elseif ($isStale) { $flag = '⌛'; $stale[] = $path; }

            $this->line(sprintf(
                ' %s  %-60s urls=%-5d err=%d warn=%d age=%sd',
                $flag, $path, $urls, $errors, $warnings,
                $ageDays === null ? '?' : (string) (int) $ageDays
            ));
        }

        if ($errored) $this->error('Errored sitemaps: ' . implode(', ', $errored));
        if ($stale) $this->warn('Stale sitemaps (>' . $maxAgeDays . 'd): ' . implode(', ', $stale));

        if ($this->option('markdown')) {
            $this->writeReport($rows, $errored, $stale);
        }

        return ($errored || $stale) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $errored
     * @param array<int,string> $stale
     */
    protected function writeReport(array $rows, array $errored, array $stale): void
    {
        $lines = [];
        $lines[] = '# GSC Sitemap status';
        $lines[] = '';
        $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
        $lines[] = '';
        $lines[] = '| Path | Type | URLs | Errors | Warnings | Last submitted | Last downloaded | Pending |';
        $lines[] = '|---|---|---:|---:|---:|---|---|---|';
        foreach ($rows as $r) {
            $lines[] = sprintf(
                '| %s | %s | %d | %d | %d | %s | %s | %s |',
                $r['path'], $r['type'], $r['urls'], $r['errors'], $r['warnings'],
                $r['lastSubmitted'] ?? '–', $r['lastDownloaded'] ?? '–',
                $r['isPending'] ? 'yes' : 'no'
            );
        }
        if ($errored) {
            $lines[] = '';
            $lines[] = '## ⚠ Errored sitemaps';
            foreach ($errored as $p) $lines[] = "- {$p}";
        }
        if ($stale) {
            $lines[] = '';
            $lines[] = '## ⌛ Stale sitemaps';
            foreach ($stale as $p) $lines[] = "- {$p}";
            $lines[] = '';
            $lines[] = 'Re-submit in Search Console → Sitemaps if these persist.';
        }
        Storage::disk('local')->put('reports/gsc-sitemap-status.md', implode("\n", $lines));
        $this->info('Wrote reports/gsc-sitemap-status.md');
    }
}
