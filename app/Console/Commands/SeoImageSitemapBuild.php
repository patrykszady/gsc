<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Extract every `<image:image>` block from the main sitemap into a dedicated `image-sitemap.xml`.
 *
 * Why: GSC's Sitemaps panel reports submission stats *per sitemap file*. Submitting a dedicated
 * image sitemap gives us independent tracking of "Submitted images" vs "Indexed images" without
 * those numbers being buried inside the 1,300+ URL parent sitemap. Google Image search uses both
 * the inline entries (still present in `sitemap.xml`) and the dedicated file equally, so this is
 * additive — we do not remove image blocks from the main sitemap.
 *
 * Also writes a short audit summary to `reports/image-sitemap-audit.md`.
 */
class SeoImageSitemapBuild extends Command
{
    protected $signature = 'seo:image-sitemap-build
        {--src= : Source sitemap path (default public/sitemap.xml)}
        {--out= : Destination path (default public/image-sitemap.xml)}
        {--markdown : Write reports/image-sitemap-audit.md}';

    protected $description = 'Build a dedicated image-sitemap.xml from the main sitemap for GSC image-search tracking.';

    public function handle(): int
    {
        $src = (string) ($this->option('src') ?: public_path('sitemap.xml'));
        $out = (string) ($this->option('out') ?: public_path('image-sitemap.xml'));

        if (! is_file($src)) {
            $this->error("Source sitemap not found: {$src}");
            return self::FAILURE;
        }

        $xml = @simplexml_load_string((string) file_get_contents($src));
        if (! $xml) {
            $this->error('Failed to parse source sitemap.');
            return self::FAILURE;
        }

        $imageNs = 'http://www.google.com/schemas/sitemap-image/1.1';
        $urlsWithImages = 0;
        $urlsWithoutImages = [];
        $totalImages = 0;
        $entries = [];

        foreach ($xml->url ?? [] as $u) {
            $loc = (string) $u->loc;
            if ($loc === '') continue;
            $images = $u->children($imageNs)->image ?? null;
            $count = $images ? count($images) : 0;
            if ($count === 0) {
                $urlsWithoutImages[] = $loc;
                continue;
            }
            $urlsWithImages++;
            $totalImages += $count;

            $imageBlocks = [];
            foreach ($images as $img) {
                $iLoc = (string) $img->loc;
                $iTitle = (string) ($img->title ?? '');
                $iCaption = (string) ($img->caption ?? '');
                if ($iLoc === '') continue;
                $block = '    <image:image>' . "\n";
                $block .= '      <image:loc>' . htmlspecialchars($iLoc, ENT_XML1) . '</image:loc>' . "\n";
                if ($iTitle !== '')   $block .= '      <image:title>'   . htmlspecialchars($iTitle,   ENT_XML1) . '</image:title>'   . "\n";
                if ($iCaption !== '') $block .= '      <image:caption>' . htmlspecialchars($iCaption, ENT_XML1) . '</image:caption>' . "\n";
                $block .= '    </image:image>';
                $imageBlocks[] = $block;
            }
            $entries[] = "  <url>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n"
                . implode("\n", $imageBlocks) . "\n  </url>";
        }

        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
            . '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n"
            . implode("\n", $entries) . "\n"
            . '</urlset>' . "\n";

        file_put_contents($out, $body);
        $this->info(sprintf(
            'Wrote %s (%d URLs, %d images, %d URLs without images).',
            $out, $urlsWithImages, $totalImages, count($urlsWithoutImages)
        ));

        if ($this->option('markdown')) {
            $lines = [];
            $lines[] = '# Image sitemap audit';
            $lines[] = '';
            $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
            $lines[] = '';
            $lines[] = "- URLs with image entries: **{$urlsWithImages}**";
            $lines[] = '- Total `<image:image>` entries: **' . $totalImages . '**';
            $lines[] = '- URLs missing images: **' . count($urlsWithoutImages) . '**';
            $lines[] = '';
            $lines[] = '## URLs without any image entry (first 50)';
            $lines[] = '';
            foreach (array_slice($urlsWithoutImages, 0, 50) as $u) $lines[] = "- {$u}";
            $lines[] = '';
            $lines[] = '## Next steps';
            $lines[] = '';
            $lines[] = '1. Submit `image-sitemap.xml` in Search Console → Sitemaps for dedicated image-discovery tracking.';
            $lines[] = '2. For URLs missing images, ensure their page emits a meaningful hero image and the sitemap generator picks it up.';
            Storage::disk('local')->put('reports/image-sitemap-audit.md', implode("\n", $lines));
            $this->info('Wrote reports/image-sitemap-audit.md');
        }

        return self::SUCCESS;
    }
}
