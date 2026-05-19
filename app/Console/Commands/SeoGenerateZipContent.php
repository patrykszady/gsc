<?php

namespace App\Console\Commands;

use App\Services\AiContentService;
use App\Services\ZipCodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SeoGenerateZipContent extends Command
{
    protected $signature = 'seo:generate-zip-content
        {--zip= : Restrict to one ZIP code}
        {--limit=0 : Max ZIP rows to process (0 = all)}
        {--dry-run : Generate but do not save}
        {--force : Overwrite existing ZIP content}';

    protected $description = 'Generate unique ZIP landing page content via Gemini for /service-area/{zip} pages';

    public function handle(ZipCodeService $zips, AiContentService $ai): int
    {
        $zipMap = $zips->getZipMap();
        if (empty($zipMap)) {
            $this->error('No ZIP map rows found (project-zipcodes.csv or published projects missing).');
            return self::FAILURE;
        }

        $targetZip = trim((string) $this->option('zip'));
        if ($targetZip !== '') {
            $targetZip = preg_replace('/\D/', '', $targetZip);
            $zipMap = array_filter($zipMap, fn ($_, $k) => (string) $k === $targetZip, ARRAY_FILTER_USE_BOTH);
            if (empty($zipMap)) {
                $this->error("ZIP {$targetZip} not found in current ZIP map.");
                return self::FAILURE;
            }
        }

        $path = 'seo/zip-content.json';
        $existing = [];
        if (Storage::disk('local')->exists($path)) {
            $existing = json_decode((string) Storage::disk('local')->get($path), true);
            if (! is_array($existing)) {
                $existing = [];
            }
        }

        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $queue = [];
        foreach ($zipMap as $zip => $info) {
            $zip = (string) $zip;
            if (! $force && isset($existing[$zip])) {
                continue;
            }
            $queue[$zip] = $info;
        }

        if ($limit > 0) {
            $queue = array_slice($queue, 0, $limit, true);
        }

        if (empty($queue)) {
            $this->info('Nothing to generate. Use --force to regenerate existing ZIP content.');
            return self::SUCCESS;
        }

        $this->info('Generating content for ' . count($queue) . ' ZIP page(s)...');
        $updated = $existing;
        $ok = 0;
        $fail = 0;

        foreach ($queue as $zip => $info) {
            $city = (string) ($info['city'] ?? '');
            $this->line("- {$zip} ({$city})");

            $content = $ai->generateZipContent(
                zip: (string) $zip,
                city: $city,
                areaSlug: $info['area_slug'] ?? null,
            );

            if ($content === null) {
                $fail++;
                $this->warn('  failed: ' . ($ai->getLastError() ?: 'unknown'));
                continue;
            }

            $updated[(string) $zip] = [
                'city' => $city,
                'intro' => $content['intro'],
                'local_context' => $content['local_context'],
                'landmarks' => $content['landmarks'],
                'permit_notes' => $content['permit_notes'],
                'updated_at' => now()->toIso8601String(),
            ];

            $ok++;
            $this->line('  ok');
        }

        if (! $dry) {
            ksort($updated);
            Storage::disk('local')->put($path, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Saved: storage/app/private/' . $path);
        } else {
            $this->comment('Dry-run mode: not saved.');
        }

        $this->info("Done. ok={$ok} fail={$fail}");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
