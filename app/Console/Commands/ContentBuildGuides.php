<?php

namespace App\Console\Commands;

use App\Models\LandingPage;
use App\Models\Project;
use App\Models\Site;
use App\Services\Seo\GuidePageContentGenerator;
use App\Support\SiteConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Writes the site's guide pages — /remodeling/{slug}, LandingPage template
 * "guide" — as DRAFTS, one per cluster of config/sites/{site}/content-guides.php,
 * from the site's own facts (GuideFactSheet) through the model
 * (GuidePageContentGenerator). Publishing stays a person's click in the
 * hub's Landing Pages screen.
 *
 *   php artisan content:build-guides --dry-run                 # what would be built, no model call
 *   php artisan content:build-guides                           # every cluster whose slug does not exist yet
 *   php artisan content:build-guides --only=bathroom-remodel-permit,kitchen-remodel-permit
 *   php artisan content:build-guides --only=remodel-services --force   # rewrite that DRAFT
 *
 * Idempotent: a slug that already exists is skipped (so the same run builds
 * production's own drafts later), unless --force names a draft. A page is
 * only ever saved when the model answered and the draft passed the
 * generator's checks; with no model, or a draft that fails twice, the slug
 * is reported and the command exits non-zero — nothing hand-written stands in.
 */
class ContentBuildGuides extends Command
{
    protected $signature = 'content:build-guides
        {--only= : Comma-separated slugs to build (default: every cluster in content-guides.clusters)}
        {--dry-run : Show what would be built — proof, hero, existing pages — without calling the model or writing}
        {--force : Rebuild a slug that already exists as a draft (a published page is never touched)}
        {--delay=4 : Seconds to pause between model calls}';

    protected $description = "Write the site's guide pages (/remodeling/{slug}) as drafts from its own facts — idempotent, skips slugs that exist";

    public function handle(GuidePageContentGenerator $generator): int
    {
        $site = Site::current();
        $clusters = collect((array) SiteConfig::forSite($site, 'content-guides.clusters', []))
            ->filter(fn ($cluster) => is_array($cluster) && filled($cluster['slug'] ?? null))
            ->values();

        if ($clusters->isEmpty()) {
            $this->error("No guide clusters for site \"{$site->slug}\" — add config/sites/{$site->slug}/content-guides.php.");

            return self::FAILURE;
        }

        $only = collect(explode(',', (string) $this->option('only')))->map('trim')->filter()->values();
        if ($only->isNotEmpty()) {
            $unknown = $only->diff($clusters->pluck('slug'));
            if ($unknown->isNotEmpty()) {
                $this->error('Unknown slug(s) in --only: '.$unknown->implode(', ').'. Known: '.$clusters->pluck('slug')->implode(', '));

                return self::FAILURE;
            }
            $clusters = $clusters->filter(fn (array $cluster) => $only->contains($cluster['slug']))->values();
        }

        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $delay = max(0, (int) $this->option('delay'));

        if (! $dry && empty(config('services.google.gemini_api_key'))) {
            $this->error('Google Gemini API key not configured (GOOGLE_GEMINI_API_KEY) — the guides are written by the model, never by hand. Nothing was built.');

            return self::FAILURE;
        }

        $this->info(sprintf('%d guide cluster(s) · site %s · %s', $clusters->count(), $site->slug, $dry ? 'DRY-RUN (no model calls, nothing written)' : 'WRITE (drafts)'));

        $built = 0;
        $skipped = 0;
        $failed = 0;
        $calls = 0;

        foreach ($clusters as $cluster) {
            $slug = (string) $cluster['slug'];
            $volume = array_sum((array) ($cluster['queries'] ?? []));
            $existing = LandingPage::where('slug', $slug)->first();

            if ($existing !== null && ! ($force && $existing->status === LandingPage::STATUS_DRAFT)) {
                $this->line(sprintf('  skip   /remodeling/%s — exists (%s)', $slug, $existing->status));
                $skipped++;

                continue;
            }

            $projects = $this->proofProjects($cluster);
            $hero = GuidePageContentGenerator::heroImage($projects);

            $this->line(sprintf(
                '  %-6s /remodeling/%s — "%s" · %s searches/mo · %s · proof: %s%s',
                $dry ? 'would' : 'build',
                $slug,
                $cluster['head_query'] ?? '',
                number_format($volume),
                $cluster['service'] ?? '',
                $projects->isEmpty() ? 'none' : $projects->map(fn (Project $p) => '"'.$p->title.'" ('.($p->location ?: 'no town').')')->implode(', '),
                $hero === null ? ' · no hero photo' : ''
            ));

            if ($projects->isEmpty()) {
                $this->error(sprintf('  FAIL   /remodeling/%s — no published project of type %s to back it; a guide is never built without proof.', $slug, implode('/', (array) ($cluster['proof_types'] ?? []))));
                $failed++;

                continue;
            }

            if ($dry) {
                continue;
            }

            if ($calls > 0 && $delay > 0) {
                sleep($delay);
            }
            $calls++;

            $attributes = $generator->build($cluster, $projects);
            if ($attributes === null) {
                $this->error(sprintf('  FAIL   /remodeling/%s — %s', $slug, $generator->getLastError() ?: 'the generator returned nothing'));
                $failed++;

                continue;
            }

            if ($existing !== null) {
                $existing->update($attributes);
                $page = $existing->fresh();
            } else {
                $page = LandingPage::create($attributes);
            }

            $built++;
            $this->info(sprintf(
                '  saved  /remodeling/%s — draft, %d words, %d proof project(s), "%s"',
                $page->slug,
                $generator->wordCount($attributes),
                count($attributes['proof_project_ids']),
                $page->h1
            ));
        }

        $this->newLine();
        $this->info(sprintf('%s: %d built, %d skipped (exist), %d failed.', $dry ? 'Dry run' : 'Done', $built, $skipped, $failed));
        if ($built > 0) {
            $this->line('Drafts are reviewed and published in the hub\'s Landing Pages screen; a draft 404s for the public until then.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Proof for a guide: published projects of the cluster's types, in the
     * order the types are listed, featured and most recent first, up to
     * LandingPage::GUIDE_PROOF_LIMIT. `proof_match` lifts the projects whose
     * title or description mention a word (the condo page's one condo) to
     * the front without excluding the rest.
     *
     * @param  array<string,mixed>  $cluster
     * @return Collection<int,Project>
     */
    private function proofProjects(array $cluster): Collection
    {
        $projects = collect();

        foreach ((array) ($cluster['proof_types'] ?? []) as $type) {
            $projects = $projects->concat(
                Project::where('is_published', true)
                    ->ofType((string) $type)
                    ->with(['images', 'areas.images'])
                    ->orderByDesc('is_featured')
                    ->orderByDesc('completed_at')
                    ->orderBy('sort_order')
                    ->get()
            );
        }

        $projects = $projects->unique('id')->values();

        $match = trim((string) ($cluster['proof_match'] ?? ''));
        if ($match !== '') {
            $projects = $projects
                ->sortByDesc(fn (Project $project) => stripos($project->title.' '.$project->description, $match) !== false ? 1 : 0)
                ->values();
        }

        return new Collection($projects->take(LandingPage::GUIDE_PROOF_LIMIT)->values()->all());
    }
}
