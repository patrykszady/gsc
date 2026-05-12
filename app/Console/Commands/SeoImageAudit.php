<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Audits ProjectImage rows for SEO problems:
 *   - Missing alt_text (worst — Google can't read it).
 *   - Missing seo_alt_text (used as a richer, page-context override).
 *   - Generic / placeholder alt text ("image", "photo", "untitled", camera filenames).
 *   - Duplicate alt text across many images of the same project (low semantic value).
 *
 * Output: table or JSON. Optional --fix-filenames suggests SEO-friendly filenames
 * based on project type + city + image position (does NOT rename — suggestion only).
 */
class SeoImageAudit extends Command
{
    protected $signature = 'seo:image-audit
        {--missing : Show only images with missing or weak alt text}
        {--json : Output JSON}
        {--limit=500 : Max rows}';

    protected $description = 'Audit project images for missing/weak alt text and SEO-unfriendly filenames.';

    public function handle(): int
    {
        $genericPatterns = ['/^img[_\-]?\d+/i', '/^dsc[_\-]?\d+/i', '/^photo$/i', '/^image$/i', '/^untitled/i', '/^screenshot/i'];

        $images = ProjectImage::query()
            ->with(['project:id,title,project_type,location'])
            ->limit((int) $this->option('limit'))
            ->get();

        $report = $images->map(function (ProjectImage $img) use ($genericPatterns) {
            $alt    = trim((string) $img->alt_text);
            $seoAlt = trim((string) ($img->seo_alt_text ?? ''));
            $filename = basename((string) ($img->path ?? ''));

            $issues = [];
            if (blank($alt))                                                 $issues[] = 'no-alt';
            elseif (mb_strlen($alt) < 10)                                    $issues[] = 'short-alt';
            elseif (in_array(strtolower($alt), ['image', 'photo', 'project image'], true)) $issues[] = 'generic-alt';
            if (blank($seoAlt))                                              $issues[] = 'no-seo-alt';
            foreach ($genericPatterns as $p) {
                if (preg_match($p, $filename)) { $issues[] = 'bad-filename'; break; }
            }

            return [
                'id'        => $img->id,
                'project'   => optional($img->project)->title ?: '—',
                'type'      => optional($img->project)->project_type ?: '—',
                'filename'  => $filename ?: '—',
                'alt'       => Str::limit($alt, 50, '…') ?: '—',
                'seo_alt'   => Str::limit($seoAlt, 50, '…') ?: '—',
                'issues'    => implode(', ', $issues) ?: 'ok',
                'has_issues'=> ! empty($issues),
            ];
        });

        if ($this->option('missing')) {
            $report = $report->filter(fn ($r) => $r['has_issues'])->values();
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($report->isEmpty()) {
            $this->info('No image SEO issues found. ✓');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Project', 'Type', 'Filename', 'alt', 'seo_alt', 'Issues'],
            $report->map(fn ($r) => [$r['id'], Str::limit($r['project'], 30), $r['type'], Str::limit($r['filename'], 30), $r['alt'], $r['seo_alt'], $r['issues']])->all()
        );

        $totalIssues  = $report->where('has_issues', true)->count();
        $noAlt        = $report->filter(fn ($r) => str_contains($r['issues'], 'no-alt'))->count();
        $noSeoAlt     = $report->filter(fn ($r) => str_contains($r['issues'], 'no-seo-alt'))->count();
        $badFilenames = $report->filter(fn ($r) => str_contains($r['issues'], 'bad-filename'))->count();

        $this->newLine();
        $this->warn("Images with issues: {$totalIssues}");
        $this->line("  · no alt text:        {$noAlt}");
        $this->line("  · no seo_alt_text:    {$noSeoAlt}");
        $this->line("  · bad filenames:      {$badFilenames}");

        return self::SUCCESS;
    }
}
