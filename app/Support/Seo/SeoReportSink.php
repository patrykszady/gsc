<?php

namespace App\Support\Seo;

use App\Support\SeoStorage;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Seo\Visibility\Contracts\ReportSink;

/**
 * Where the kit's seo:competitor-brand-track --markdown drops its report:
 * the same reports/competitor-brand.md path (and the same tenant-scoped
 * SeoStorage::path() every other generated artefact goes through) the
 * site's own retired App\Console\Commands\SeoCompetitorBrandTrack wrote to
 * directly, so the report library still finds it at the same key.
 */
final class SeoReportSink implements ReportSink
{
    public function write(string $relativePath, string $markdown): void
    {
        Storage::disk('local')->put(SeoStorage::path($relativePath), $markdown);
    }
}
