<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Put the project's town in its URL.
 *
 * "/projects/first-floor-remodel-2" tells a search engine nothing and tells a
 * homeowner in Palatine less. The numeric suffix is pure collision-avoidance;
 * the town is the word people actually search alongside the work.
 *
 * Dry by default. Renaming moves a project's photo pages with it, so this
 * prints the full mapping and waits to be told to apply it.
 */
class LocalizeProjectSlugs extends Command
{
    protected $signature = 'projects:localize-slugs
        {--apply : Write the new slugs (otherwise prints the mapping and changes nothing)}';

    protected $description = 'Append the project town to project slugs that lack it, recording redirects.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];
        $photoTotal = 0;

        foreach (Project::with('images')->orderBy('id')->get() as $project) {
            $city = $project->citySlug();
            if (! $city) {
                $rows[] = ['skip', $project->slug, '—', 'no location'];
                continue;
            }

            // Already localised — including the case where only the town is
            // present without the state, which is good enough to leave alone.
            $town = Str::beforeLast($city, '-');
            if (str_contains($project->slug, $town)) {
                continue;
            }

            // Strip the numeric collision suffix: the town replaces it with
            // something meaningful, and "-2-palatine-il" reads as a mistake.
            $base = preg_replace('/-\d+$/', '', $project->slug);
            $new = $this->unique($base . '-' . $city, $project->id);

            $photoTotal += $project->images->count();
            $rows[] = ['rename', $project->slug, $new, $project->images->count() . ' photo URLs'];

            if ($apply) {
                $project->slug = $new;
                $project->save();   // booted() records the old slug for the 301
            }
        }

        $renames = collect($rows)->where(0, 'rename');

        if ($renames->isEmpty()) {
            $this->info('Every project slug already carries its town.');

            return self::SUCCESS;
        }

        $this->table(['', 'From', 'To', 'Also moves'], $rows);
        $this->line(sprintf(
            '  %d project%s · %d photo page%s',
            $renames->count(), $renames->count() === 1 ? '' : 's',
            $photoTotal, $photoTotal === 1 ? '' : 's',
        ));

        if (! $apply) {
            $this->newLine();
            $this->warn('Dry run — nothing written. Re-run with --apply to commit, then:');
            $this->line('  php artisan sitemap:generate');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Applied. Old URLs now 301 to the new ones.');
        $this->line('Next: php artisan sitemap:generate');

        return self::SUCCESS;
    }

    /** Never collide with a live slug or with one still redirecting. */
    private function unique(string $slug, int $ignoreId): string
    {
        $base = $slug;
        $n = 1;

        while (
            Project::where('slug', $slug)->where('id', '!=', $ignoreId)->exists()
            || \App\Models\ProjectSlugHistory::where('slug', $slug)->where('project_id', '!=', $ignoreId)->exists()
        ) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }
}
