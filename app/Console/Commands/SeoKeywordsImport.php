<?php

namespace App\Console\Commands;

use App\Services\Seo\SeoAutopilotService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Import a hand-built keyword list into seo_keywords (a spreadsheet, a
 * client's list, another tool's export). One keyword per line, or
 * CSV with optional columns: keyword,volume,difficulty,wyn. Volumes missing
 * here are filled by the next seo:keyword-research run.
 */
class SeoKeywordsImport extends Command
{
    protected $signature = 'seo:keywords-import {file : Path to a .txt/.csv keyword list} {--source=import : Source tag stored with each keyword}';

    protected $description = 'Import a hand-built keyword list into seo_keywords';

    public function handle(SeoAutopilotService $engine): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $siteId = \App\Models\Site::current()?->id;
        $source = (string) $this->option('source');
        $n = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $cols = array_map('trim', str_getcsv($line));
            $kw = mb_strtolower((string) ($cols[0] ?? ''));
            if ($kw === '' || $kw === 'keyword') {
                continue;
            }
            $existing = Tenancy::table('seo_keywords')->where('site_id', $siteId)->where('keyword', $kw)->first();
            $sources = $existing && $existing->sources ? (array) json_decode($existing->sources, true) : [];
            $sources = array_values(array_unique(array_merge($sources, [$source])));
            $class = $engine->classify($kw);
            $volume = is_numeric($cols[1] ?? null) ? (int) $cols[1] : ($existing->volume ?? null);
            $difficulty = is_numeric($cols[2] ?? null) ? (int) $cols[2] : ($existing->difficulty ?? null);
            Tenancy::table('seo_keywords')->updateOrInsert(
                ['site_id' => $siteId, 'keyword' => mb_substr($kw, 0, 191)],
                [
                    'volume' => $volume,
                    'difficulty' => $difficulty,
                    'service' => $class[0] ?? ($existing->service ?? null),
                    'city' => $class[1] ?? ($existing->city ?? null),
                    'modifier' => $class[2] ?? ($existing->modifier ?? null),
                    'sources' => json_encode($sources),
                    'opportunity' => $existing->opportunity ?? ($volume ? $volume * 1.0 : 0),
                    'updated_at' => now(),
                    'created_at' => $existing->created_at ?? now(),
                ]
            );
            $n++;
        }

        Cache::forget(Tenancy::cacheKey('seo_reports_keywords_v1'));
        Cache::forget(Tenancy::cacheKey('seo.area.service_volume'));
        $this->info("Imported {$n} keyword(s) from {$path} (source: {$source}). Volumes fill on the next seo:keyword-research run.");

        return self::SUCCESS;
    }
}
