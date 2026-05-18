<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\Project;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Audit which published projects will get GPS EXIF on their GBP photo uploads.
 *
 * A project gets geotagged when its `location` field matches an AreaServed
 * row (by name or slug) that has non-null latitude/longitude. Projects that
 * don't match are uploaded to GBP without GPS — this command lists them so
 * you can either add the missing AreaServed row or correct the project's
 * location string.
 *
 *   php artisan gbp:geotag-audit              # show only orphans
 *   php artisan gbp:geotag-audit --all        # show all projects
 */
class GbpGeotagAudit extends Command
{
    protected $signature = 'gbp:geotag-audit
        {--all : Show all projects, not just orphans}
        {--published : Limit to published projects (default: true)}';

    protected $description = 'List projects whose location does not resolve to AreaServed coordinates for GBP photo geotagging.';

    public function handle(GoogleBusinessProfileService $gbp): int
    {
        $query = Project::query()->whereNotNull('location');
        if ($this->option('published') !== false) {
            $query->where('is_published', true);
        }

        $projects = $query->orderBy('location')->get(['id', 'title', 'location']);

        if ($projects->isEmpty()) {
            $this->info('No projects with a location set.');
            return self::SUCCESS;
        }

        // Pre-load areas served keyed by both city and slug for fast lookup.
        $areas = AreaServed::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['city', 'slug', 'latitude', 'longitude']);
        $byCity = $areas->keyBy(fn ($a) => Str::lower($a->city));
        $bySlug = $areas->keyBy('slug');

        $rows = [];
        $matched = 0;
        $orphans = 0;

        foreach ($projects as $p) {
            $city = $gbp->normalizeCity($p->location);
            $key = Str::lower($city);
            $slug = Str::slug($city);
            $area = $byCity->get($key) ?? $bySlug->get($slug);

            if ($area) {
                $matched++;
                if ($this->option('all')) {
                    $rows[] = [
                        $p->id,
                        Str::limit($p->title, 40),
                        $p->location,
                        '✓ ' . $area->city,
                        sprintf('%.4f, %.4f', $area->latitude, $area->longitude),
                    ];
                }
            } else {
                $orphans++;
                $rows[] = [
                    $p->id,
                    Str::limit($p->title, 40),
                    $p->location,
                    '<fg=red>✗ no area match</>',
                    '—',
                ];
            }
        }

        if (! empty($rows)) {
            $this->table(['ID', 'Title', 'Project Location', 'AreaServed', 'Coords'], $rows);
        }

        $this->newLine();
        $this->info("Matched (will geotag): {$matched}");
        if ($orphans > 0) {
            $this->warn("Orphans (no GPS): {$orphans}");
            $this->newLine();
            $this->line('Fix options for each orphan:');
            $this->line('  1. Edit the project to use an existing area-served name');
            $this->line('  2. Add the missing city/town to areas_served with lat/lng');
            return self::FAILURE;
        }

        $this->info('All projects resolve to an AreaServed row — GBP photos will be geotagged.');
        return self::SUCCESS;
    }
}
