<?php

namespace App\Console\Commands;

use App\Support\SiteIcons;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Build a site's icon set from its mark.
 *
 * One source image — an SVG or a large square PNG, the mark the client
 * supplied — becomes every file the head and the manifest link:
 * the 96px favicon Google wants, the 180px touch icon, the 192/512 install
 * icons, a multi-size .ico for Bing and old browsers, and the SVG itself
 * when the source is one. ImageMagick does the rasterising.
 */
class IconsBuild extends Command
{
    protected $signature = 'icons:build
        {site : Site slug the set belongs to}
        {--from= : Source image (SVG or square PNG, ideally 512px+); default: the set\'s own favicon.svg}
        {--background= : Flatten onto this colour instead of keeping transparency (e.g. #ffffff)}';

    protected $description = 'Rasterise a site\'s favicon, touch and install icons from its mark into public/icons/{slug}/.';

    public function handle(): int
    {
        // The slug alone: this runs when a site is being provisioned, before
        // it has a row, and needs no database at all.
        $site = (string) $this->argument('site');
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $site)) {
            $this->error('Site slug must be lowercase letters, digits and dashes: "'.$site.'".');

            return self::FAILURE;
        }

        $dir = dirname(SiteIcons::path('favicon.svg', $site));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $source = (string) ($this->option('from') ?: SiteIcons::path('favicon.svg', $site));
        if (! is_file($source)) {
            $this->error("Source image not found: {$source}");

            return self::FAILURE;
        }

        $convert = trim((string) shell_exec('command -v magick || command -v convert')) ?: null;
        if ($convert === null) {
            $this->error('ImageMagick (convert/magick) is not installed.');

            return self::FAILURE;
        }

        $background = (string) ($this->option('background') ?: 'none');
        $isSvg = str_ends_with(strtolower($source), '.svg');

        if ($isSvg && realpath($source) !== realpath(SiteIcons::path('favicon.svg', $site))) {
            copy($source, SiteIcons::path('favicon.svg', $site));
            $this->line('  favicon.svg copied from source');
        } elseif (! $isSvg && ! is_file(SiteIcons::path('favicon.svg', $site))) {
            $this->warn('  favicon.svg: no SVG source — browsers will use the PNGs (fine), but supply an SVG mark when there is one.');
        }

        // Every raster size from the source in one pass each; 'none' keeps alpha.
        $targets = [
            'favicon-96x96.png' => 96,
            'apple-touch-icon.png' => 180,
            'android-chrome-192x192.png' => 192,
            'android-chrome-512x512.png' => 512,
        ];
        foreach ($targets as $file => $px) {
            $this->magick([$convert, '-background', $background, '-density', '384', $source, '-resize', "{$px}x{$px}", '-gravity', 'center', '-extent', "{$px}x{$px}", ...($background === 'none' ? [] : ['-flatten']), SiteIcons::path($file, $site)]);
            $this->line("  {$file}");
        }

        // The .ico carries 16/32/48 frames, each downsampled from the source.
        $this->magick([$convert, '-background', $background, '-density', '384', $source, '-define', 'icon:auto-resize=48,32,16', SiteIcons::path('favicon.ico', $site)]);
        $this->line('  favicon.ico (16, 32, 48)');

        $missing = SiteIcons::missing($site);
        if ($missing !== []) {
            $this->warn('  still missing: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->info("Icon set for {$site} written to public/".SiteIcons::dir($site).'/');

        return self::SUCCESS;
    }

    /** @param  list<string>  $command */
    private function magick(array $command): void
    {
        $process = new Process($command, base_path(), null, null, 120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'ImageMagick failed: '.implode(' ', $command));
        }
    }
}
