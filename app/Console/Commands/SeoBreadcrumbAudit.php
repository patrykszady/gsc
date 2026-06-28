<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SeoBreadcrumbAudit extends Command
{
    protected $signature = 'seo:breadcrumb-audit
        {--sitemap= : Sitemap URL (defaults to APP_URL/sitemap.xml)}
        {--limit=300 : Max URLs to crawl from sitemap}
        {--markdown : Write reports/breadcrumb-audit.md}';

    protected $description = 'Crawl sitemap pages and report missing BreadcrumbList structured data.';

    public function handle(): int
    {
        $base = rtrim((string) config('app.url'), '/');
        $sitemapUrl = (string) ($this->option('sitemap') ?: $base . '/sitemap.xml');
        $limit = max(1, (int) $this->option('limit'));

        $sitemapResp = Http::timeout(20)->get($sitemapUrl);
        if (! $sitemapResp->ok()) {
            $this->error('Sitemap fetch failed: HTTP ' . $sitemapResp->status());
            return self::FAILURE;
        }

        preg_match_all('/<loc>(.*?)<\/loc>/i', $sitemapResp->body(), $matches);
        $urls = collect($matches[1] ?? [])
            ->map(fn ($u) => trim((string) html_entity_decode($u)))
            ->filter()
            ->filter(fn ($u) => $this->isHtmlLikeUrl($u))
            ->unique()
            ->take($limit)
            ->values();

        if ($urls->isEmpty()) {
            $this->error('No crawlable URLs found in sitemap.');
            return self::FAILURE;
        }

        $this->info('Auditing breadcrumb schema on ' . $urls->count() . ' URLs...');

        $missing = [];
        $errors = [];
        $bar = $this->output->createProgressBar($urls->count());
        $bar->start();

        foreach ($urls as $url) {
            $resp = Http::timeout(20)->get($url);
            if (! $resp->ok()) {
                $errors[] = [$url, 'HTTP ' . $resp->status()];
                $bar->advance();
                continue;
            }

            $body = (string) $resp->body();
            if (! $this->hasBreadcrumbList($body)) {
                $missing[] = $url;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $missingPct = round((count($missing) / max(1, $urls->count())) * 100, 1);
        $this->table(
            ['Metric', 'Value'],
            [
                ['URLs crawled', (string) $urls->count()],
                ['Missing BreadcrumbList', count($missing) . " ({$missingPct}%)"],
                ['Fetch errors', (string) count($errors)],
            ]
        );

        if (! empty($missing)) {
            $this->warn('Missing BreadcrumbList (first 30):');
            foreach (array_slice($missing, 0, 30) as $u) {
                $this->line(' - ' . $u);
            }
        } else {
            $this->info('All crawled URLs include BreadcrumbList schema.');
        }

        if (! empty($errors)) {
            $this->warn('Fetch errors (first 20):');
            foreach (array_slice($errors, 0, 20) as [$url, $err]) {
                $this->line(" - {$err} :: {$url}");
            }
        }

        if ($this->option('markdown')) {
            $lines = [];
            $lines[] = '# Breadcrumb audit';
            $lines[] = '';
            $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
            $lines[] = '';
            $lines[] = '- URLs crawled: **' . $urls->count() . '**';
            $lines[] = '- Missing BreadcrumbList: **' . count($missing) . '**';
            $lines[] = '- Fetch errors: **' . count($errors) . '**';
            $lines[] = '';
            $lines[] = '## Missing BreadcrumbList (first 100)';
            $lines[] = '';
            foreach (array_slice($missing, 0, 100) as $u) {
                $lines[] = '- ' . $u;
            }
            $lines[] = '';
            $lines[] = '## Fetch errors (first 50)';
            $lines[] = '';
            foreach (array_slice($errors, 0, 50) as [$url, $err]) {
                $lines[] = '- ' . $err . ' :: ' . $url;
            }

            Storage::disk('local')->put('reports/breadcrumb-audit.md', implode("\n", $lines));
            $this->info('Wrote reports/breadcrumb-audit.md');
        }

        return self::SUCCESS;
    }

    protected function hasBreadcrumbList(string $html): bool
    {
        return str_contains($html, '"@type":"BreadcrumbList"')
            || str_contains($html, '"@type": "BreadcrumbList"')
            || str_contains($html, "'@type':'BreadcrumbList'")
            || str_contains($html, "'@type': 'BreadcrumbList'");
    }

    protected function isHtmlLikeUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }

        return ! preg_match('/\.(xml|txt|json|png|jpe?g|gif|webp|svg|ico|pdf)$/i', $path);
    }
}
