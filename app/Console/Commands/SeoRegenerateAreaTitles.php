<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\SeoPathOverride;
use App\Services\Seo\TitleMetaGenerator;
use Illuminate\Console\Command;

/**
 * Rewrite the stored per-path title/description overrides from the generator.
 *
 * SEOBuilder applies a SeoPathOverride ahead of anything the generator would
 * produce, so improving TitleMetaGenerator changes nothing on its own — the
 * 43 rows the autopilot wrote earlier keep winning. This re-runs the current
 * generator over every area path and stores the result.
 *
 * Why the old rows had to go: all 43 carried
 * `{City} Remodeling | 5★ Rated · Free Estimates`, identical but for the city.
 * With the brand suffix appended downstream that shipped 67 characters against
 * a ~60-character cutoff, the repetition invited Google to discard the title
 * and write its own, and the phrase people actually search — "{city} home
 * remodeling" — never appeared in it.
 */
class SeoRegenerateAreaTitles extends Command
{
    protected $signature = 'seo:regenerate-area-titles
        {--apply : Write the new titles (otherwise prints the diff and changes nothing)}';

    protected $description = 'Regenerate stored title/meta overrides for area pages from the current generator.';

    public function handle(TitleMetaGenerator $generator): int
    {
        $apply = (bool) $this->option('apply');
        $brand = (string) config('brand.name');
        $suffix = $brand === '' ? '' : ' | ' . $brand;

        $rows = [];
        $overLimit = 0;

        // Overrides for towns that were removed from the curated area list
        // still win over the generator on a path that no longer resolves.
        $orphans = SeoPathOverride::query()
            ->where('path', 'like', 'areas-served/%')
            ->get()
            ->filter(function (SeoPathOverride $o) {
                $slug = explode('/', $o->path)[1] ?? '';

                return $slug !== '' && ! AreaServed::where('slug', $slug)->exists();
            });

        if ($orphans->isNotEmpty()) {
            $this->line(sprintf('  %d override(s) point at removed areas:', $orphans->count()));
            foreach ($orphans as $o) {
                $this->line('    ' . $o->path);
            }
            if ($apply) {
                SeoPathOverride::whereIn('id', $orphans->pluck('id'))->delete();
                $this->line('    → deleted');
            }
            $this->newLine();
        }

        foreach (AreaServed::orderBy('city')->get() as $area) {
            // The area landing page AND its per-service children — the
            // children carried the same boilerplate and were missed when only
            // the landing pages were regenerated.
            foreach ([null, ...array_keys(TitleMetaGenerator::SERVICES)] as $service) {
                $childPath = SeoPathOverride::normalizePath(
                    '/areas-served/' . $area->slug . ($service ? '/services/' . $service : ''),
                );

                if ($service !== null && ! SeoPathOverride::where('path', $childPath)->exists()) {
                    continue;   // only refresh children that already have an override
                }

                $freshChild = $generator->forArea($area, $service);
                if ($apply) {
                    SeoPathOverride::updateOrCreate(
                        ['path' => $childPath],
                        [
                            'title' => $freshChild['title'],
                            'description' => $freshChild['description'],
                            'source' => 'generator',
                        ],
                    );
                }
            }

            $path = SeoPathOverride::normalizePath('/areas-served/' . $area->slug);
            $existing = SeoPathOverride::where('path', $path)->first();

            $fresh = $generator->forArea($area);
            $finalTitle = $fresh['title'] . $suffix;
            $len = mb_strlen($finalTitle);
            if ($len > 60) {
                $overLimit++;
            }

            $wasLen = $existing?->title ? mb_strlen($existing->title . $suffix) : 0;

            $rows[] = [
                $area->slug,
                $wasLen ?: '—',
                $len,
                \Illuminate\Support\Str::limit($finalTitle, 52, ''),
            ];

            if ($apply) {
                SeoPathOverride::updateOrCreate(
                    ['path' => $path],
                    [
                        'title' => $fresh['title'],
                        'description' => $fresh['description'],
                        'source' => 'generator',
                    ],
                );
            }
        }

        $this->table(['area', 'was', 'now', 'final title (with brand)'], array_slice($rows, 0, 12));
        if (count($rows) > 12) {
            $this->line('  … ' . (count($rows) - 12) . ' more');
        }

        $this->newLine();
        $this->line(sprintf('  %d area pages · %d still over 60 chars', count($rows), $overLimit));

        if (! $apply) {
            $this->warn('  Dry run — nothing written. Re-run with --apply.');
        } else {
            $this->info('  Applied. Run `php artisan sitemap:generate` so lastmod reflects the change.');
        }

        return self::SUCCESS;
    }
}
