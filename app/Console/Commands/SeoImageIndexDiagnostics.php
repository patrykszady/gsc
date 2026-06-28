<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SeoImageIndexDiagnostics extends Command
{
    protected $signature = 'seo:image-index-diagnostics
        {--main= : Main sitemap path (default public/sitemap.xml)}
        {--image= : Image sitemap path (default public/image-sitemap.xml)}
        {--markdown : Write reports/image-index-diagnostics.md}';

    protected $description = 'Diagnose image-indexing coverage and sitemap quality (counts, hosts, duplicates, thumbnail usage).';

    public function handle(): int
    {
        $mainPath = (string) ($this->option('main') ?: public_path('sitemap.xml'));
        $imagePath = (string) ($this->option('image') ?: public_path('image-sitemap.xml'));

        $mainXml = $this->loadXml($mainPath);
        if (! $mainXml) {
            $this->error("Unable to read main sitemap: {$mainPath}");
            return self::FAILURE;
        }

        $imageXml = $this->loadXml($imagePath);
        if (! $imageXml) {
            $this->error("Unable to read image sitemap: {$imagePath}");
            return self::FAILURE;
        }

        [$mainUrls, $mainImageEntries, $mainImageLocs] = $this->extractImageStats($mainXml);
        [$imageUrls, $imageImageEntries, $imageImageLocs] = $this->extractImageStats($imageXml);

        $uniqueMainLocs = array_values(array_unique($mainImageLocs));
        $uniqueImageLocs = array_values(array_unique($imageImageLocs));

        $thumbCount = count(array_filter($uniqueImageLocs, fn (string $u) => str_contains($u, '/thumbs/')));
        $externalCount = count(array_filter($uniqueImageLocs, fn (string $u) => ! str_contains($u, parse_url(config('app.url'), PHP_URL_HOST) ?: 'gs.construction')));

        $hostCounts = [];
        foreach ($uniqueImageLocs as $loc) {
            $host = parse_url($loc, PHP_URL_HOST) ?: 'unknown';
            $hostCounts[$host] = ($hostCounts[$host] ?? 0) + 1;
        }
        arsort($hostCounts);

        $missingInImageSitemap = array_values(array_diff($uniqueMainLocs, $uniqueImageLocs));

        $this->info('Image Index Diagnostics');
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Main sitemap URLs', (string) $mainUrls],
                ['Main sitemap image entries', (string) $mainImageEntries],
                ['Image sitemap URLs', (string) $imageUrls],
                ['Image sitemap image entries', (string) $imageImageEntries],
                ['Unique image URLs', (string) count($uniqueImageLocs)],
                ['Thumbnail URLs (unique)', (string) $thumbCount],
                ['External-hosted image URLs (unique)', (string) $externalCount],
                ['Missing from image-sitemap (vs main)', (string) count($missingInImageSitemap)],
            ]
        );

        $hostRows = [];
        foreach (array_slice($hostCounts, 0, 8, true) as $host => $count) {
            $hostRows[] = [$host, $count];
        }
        $this->newLine();
        $this->table(['Host', 'Unique images'], $hostRows);

        if ($thumbCount > 0) {
            $this->warn('Thumbnail URLs are present in image-sitemap; prefer canonical originals.');
        }
        if (count($missingInImageSitemap) > 0) {
            $this->warn('Some main-sitemap image URLs are missing in image-sitemap.');
        }

        if ($this->option('markdown')) {
            $lines = [];
            $lines[] = '# Image index diagnostics';
            $lines[] = '';
            $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
            $lines[] = '';
            $lines[] = '- Main sitemap URLs: **' . $mainUrls . '**';
            $lines[] = '- Main sitemap image entries: **' . $mainImageEntries . '**';
            $lines[] = '- Image sitemap URLs: **' . $imageUrls . '**';
            $lines[] = '- Image sitemap image entries: **' . $imageImageEntries . '**';
            $lines[] = '- Unique image URLs: **' . count($uniqueImageLocs) . '**';
            $lines[] = '- Thumbnail URLs (unique): **' . $thumbCount . '**';
            $lines[] = '- External-hosted image URLs (unique): **' . $externalCount . '**';
            $lines[] = '- Missing from image-sitemap vs main: **' . count($missingInImageSitemap) . '**';
            $lines[] = '';
            $lines[] = '## Top hosts';
            $lines[] = '';
            foreach ($hostRows as [$host, $count]) {
                $lines[] = "- {$host}: {$count}";
            }
            $lines[] = '';
            $lines[] = '## Missing image URLs (first 50)';
            $lines[] = '';
            foreach (array_slice($missingInImageSitemap, 0, 50) as $u) {
                $lines[] = "- {$u}";
            }

            Storage::disk('local')->put('reports/image-index-diagnostics.md', implode("\n", $lines));
            $this->info('Wrote reports/image-index-diagnostics.md');
        }

        return self::SUCCESS;
    }

    protected function loadXml(string $path): ?\SimpleXMLElement
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return @simplexml_load_string($raw) ?: null;
    }

    /**
     * @return array{0:int,1:int,2:array<int,string>}
     */
    protected function extractImageStats(\SimpleXMLElement $xml): array
    {
        $imageNs = 'http://www.google.com/schemas/sitemap-image/1.1';
        $urlCount = 0;
        $imageEntryCount = 0;
        $locs = [];

        foreach ($xml->url ?? [] as $urlNode) {
            $urlCount++;
            $images = $urlNode->children($imageNs)->image ?? [];
            foreach ($images as $img) {
                $loc = trim((string) ($img->loc ?? ''));
                if ($loc === '') {
                    continue;
                }
                $imageEntryCount++;
                $locs[] = $loc;
            }
        }

        return [$urlCount, $imageEntryCount, $locs];
    }
}
