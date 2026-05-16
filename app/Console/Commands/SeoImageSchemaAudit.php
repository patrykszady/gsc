<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class SeoImageSchemaAudit extends Command
{
    protected $signature = 'seo:image-schema-audit
        {--sitemap= : Sitemap URL (defaults to APP_URL/sitemap.xml)}
        {--limit=300 : Max URLs to crawl from sitemap}
        {--json : Output JSON report}
        {--only-errors : Print only pages with missing contentUrl issues}';

    protected $description = 'Audit JSON-LD ImageObject entries and report missing/empty contentUrl fields.';

    public function handle(): int
    {
        $base = rtrim((string) (config('app.url') ?: 'https://gs.construction'), '/');
        $sitemapUrl = (string) ($this->option('sitemap') ?: $base . '/sitemap.xml');

        $this->info("Reading sitemap: {$sitemapUrl}");

        $sitemapResponse = Http::timeout(20)->get($sitemapUrl);
        if (! $sitemapResponse->ok()) {
            $this->error('Sitemap fetch failed: HTTP ' . $sitemapResponse->status());
            return self::FAILURE;
        }

        $urls = collect((new Crawler($sitemapResponse->body()))->filter('loc')->each(fn ($n) => trim($n->text())))
            ->filter(fn ($u) => Str::startsWith($u, $base))
            ->unique()
            ->take((int) $this->option('limit'))
            ->values();

        if ($urls->isEmpty()) {
            $this->error('No URLs found in sitemap.');
            return self::FAILURE;
        }

        $this->info("Auditing {$urls->count()} pages...");
        $bar = $this->output->createProgressBar($urls->count());

        $report = [];

        foreach ($urls as $url) {
            $bar->advance();

            try {
                $response = Http::timeout(20)
                    ->withHeaders(['User-Agent' => 'GSC-ImageSchemaAudit/1.0'])
                    ->get($url);
            } catch (\Throwable $e) {
                $report[] = [
                    'url' => $url,
                    'status' => 'fetch-error',
                    'issues' => [[
                        'type' => 'fetch-error',
                        'message' => $e->getMessage(),
                    ]],
                ];
                continue;
            }

            if (! $response->ok()) {
                $report[] = [
                    'url' => $url,
                    'status' => 'http-' . $response->status(),
                    'issues' => [[
                        'type' => 'fetch-error',
                        'message' => 'HTTP ' . $response->status(),
                    ]],
                ];
                continue;
            }

            $crawler = new Crawler($response->body());
            $scripts = $crawler->filter('script[type="application/ld+json"]')->each(
                fn ($n) => trim($n->text())
            );

            $issues = [];
            $imageObjectCount = 0;

            foreach ($scripts as $scriptIndex => $scriptText) {
                if ($scriptText === '') {
                    continue;
                }

                $decoded = json_decode($scriptText, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $imageObjects = [];
                $this->collectImageObjects($decoded, $imageObjects);

                foreach ($imageObjects as $objIndex => $imageObject) {
                    $imageObjectCount++;
                    $contentUrl = $imageObject['contentUrl'] ?? null;

                    if (! is_string($contentUrl) || trim($contentUrl) === '') {
                        $issues[] = [
                            'type' => 'missing-contentUrl',
                            'script_index' => $scriptIndex,
                            'image_object_index' => $objIndex,
                            'name' => $imageObject['name'] ?? null,
                            'url' => $imageObject['url'] ?? null,
                        ];
                    }
                }
            }

            $report[] = [
                'url' => $url,
                'status' => 'ok',
                'image_objects' => $imageObjectCount,
                'issues' => $issues,
            ];
        }

        $bar->finish();
        $this->newLine(2);

        $rows = collect($report);
        $errors = $rows->filter(fn ($r) => ! empty($r['issues']));

        if ($this->option('json')) {
            $payload = $this->option('only-errors') ? $errors->values()->all() : $rows->values()->all();
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $tableRows = ($this->option('only-errors') ? $errors : $rows)
                ->map(function ($r) {
                    return [
                        $r['url'],
                        $r['status'],
                        (int) ($r['image_objects'] ?? 0),
                        count($r['issues'] ?? []),
                    ];
                })
                ->all();

            $this->table(['URL', 'Status', 'ImageObjects', 'Issues'], $tableRows);

            if ($errors->isNotEmpty()) {
                $this->warn('Pages with missing contentUrl issues: ' . $errors->count());
            } else {
                $this->info('No missing contentUrl issues found.');
            }
        }

        return $errors->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param mixed $node
     * @param array<int,array<string,mixed>> $out
     */
    private function collectImageObjects(mixed $node, array &$out): void
    {
        if (! is_array($node)) {
            return;
        }

        $type = $node['@type'] ?? null;
        if ($this->isImageObjectType($type)) {
            $out[] = $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectImageObjects($value, $out);
            }
        }
    }

    /**
     * @param mixed $type
     */
    private function isImageObjectType(mixed $type): bool
    {
        if (is_string($type)) {
            return trim($type) === 'ImageObject';
        }

        if (is_array($type)) {
            foreach ($type as $entry) {
                if (is_string($entry) && trim($entry) === 'ImageObject') {
                    return true;
                }
            }
        }

        return false;
    }
}
