<?php

namespace Tests\Feature\Console;

use App\Models\BlogPost;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectBeforeAfter;
use App\Models\ProjectCollaborator;
use App\Models\ProjectImage;
use App\Models\ProjectSlugHistory;
use App\Models\ProjectTimelapse;
use App\Models\Testimonial;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan projects:merge` — folds one real job's separate project rows
 * into areas of one target. Adopted from jpeterson-design.com via
 * ss-platform-kit 0.16.0 (SsSystems\Platform\Projects\ProjectMerger); see
 * that kit's docs/PROJECT-AREAS.md for gs.construction's own shape —
 * `project_slug_history` (not jpeterson's `project_slug_redirects`), whose
 * `repointRedirects()` actually moves rows (App\Support\Projects\
 * GscSlugRedirectRecorder), since every history row here references the
 * project it belongs to by a cascading foreign key.
 */
class ProjectsMergeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function image(Project $project, string $name, array $extra = []): ProjectImage
    {
        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => "{$name}.jpg", 'original_filename' => "{$name}.jpg",
            'path' => "projects/{$project->id}/{$name}.jpg",
            'mime_type' => 'image/jpeg', 'size' => 1, 'sort_order' => 1,
        ] + $extra);
    }

    /** @return array{target: Project, kitchen: Project, mudroom: Project} */
    private function mountProspectProjects(): array
    {
        $target = Project::create(['title' => 'Mount Prospect Home Remodel', 'slug' => 'mount-prospect-home-remodel', 'project_type' => 'home-remodel', 'location' => 'Mount Prospect, IL', 'is_published' => true, 'description' => 'Whole-home write-up.']);
        $kitchen = Project::create(['title' => 'Mount Prospect Kitchen Transformation', 'slug' => 'mount-prospect-kitchen', 'project_type' => 'kitchen', 'location' => 'Mount Prospect, IL', 'is_published' => true, 'description' => 'Kitchen write-up.']);
        $mudroom = Project::create(['title' => 'Mount Prospect Mudroom Refresh', 'slug' => 'mount-prospect-mudroom', 'project_type' => 'mudroom', 'location' => 'Mount Prospect, IL', 'is_published' => true, 'description' => 'Mudroom write-up.']);

        return ['target' => $target, 'kitchen' => $kitchen, 'mudroom' => $mudroom];
    }

    public function test_dry_run_prints_the_plan_and_changes_nothing(): void
    {
        ['target' => $target, 'kitchen' => $kitchen, 'mudroom' => $mudroom] = $this->mountProspectProjects();

        $this->artisan('projects:merge', [
            'target' => $target->slug,
            'sources' => [$kitchen->slug, $mudroom->slug],
            '--type' => 'home-remodel',
            '--title' => 'Mount Prospect Full-Home Design',
            '--dry' => true,
        ])
            ->expectsOutputToContain('Dry run — nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(0, ProjectArea::count(), 'a dry run creates nothing');
        $this->assertSame(0, ProjectSlugHistory::count());
        $this->assertNotNull(Project::find($kitchen->id));
        $this->assertNotNull(Project::find($mudroom->id));
        $this->assertSame('home-remodel', $target->fresh()->project_type, 'the target is untouched');
    }

    public function test_merge_folds_target_and_sources_into_areas_and_moves_everything_they_own(): void
    {
        ['target' => $target, 'kitchen' => $kitchen, 'mudroom' => $mudroom] = $this->mountProspectProjects();

        $targetImage = $this->image($target, 'home');
        $kitchenImage = $this->image($kitchen, 'kit', ['is_cover' => true]);
        $mudroomImage = $this->image($mudroom, 'mud');

        $review = Testimonial::create(['reviewer_name' => 'A. Homeowner', 'project_location' => 'Mount Prospect, IL', 'review_description' => 'Loved the new kitchen.', 'review_date' => '2025-09-01', 'star_rating' => 5, 'is_hidden' => false]);
        $kitchen->testimonials()->attach($review->id);

        $collaborator = ProjectCollaborator::create(['project_id' => $kitchen->id, 'role' => 'other', 'name' => 'Acme Cabinetry', 'sort_order' => 0]);
        $beforeAfter = ProjectBeforeAfter::create(['project_id' => $kitchen->id, 'before_path' => '', 'after_path' => '', 'sort_order' => 0]);
        $timelapse = ProjectTimelapse::create(['project_id' => $kitchen->id, 'title' => 'Demo to finish', 'sort_order' => 0]);
        $blog = BlogPost::create(['project_id' => $kitchen->id, 'title' => 'The Mount Prospect Kitchen Story']);

        $this->artisan('projects:merge', [
            'target' => $target->slug,
            'sources' => [$kitchen->slug, $mudroom->slug],
            '--type' => 'home-remodel',
            '--title' => 'Mount Prospect Full-Home Design',
        ])->assertSuccessful();

        $target = $target->fresh(['areas.images']);
        $this->assertSame('home-remodel', $target->project_type);
        $this->assertSame('Mount Prospect Full-Home Design', $target->title);
        $this->assertSame('mount-prospect-home-remodel', $target->slug, 'the target keeps its own slug/page');
        $this->assertCount(3, $target->areas, 'the target itself plus the two sources');

        $homeArea = $target->areas->firstWhere('project_type', 'home-remodel');
        $kitchenArea = $target->areas->firstWhere('project_type', 'kitchen');
        $mudroomArea = $target->areas->firstWhere('project_type', 'mudroom');
        $this->assertNotNull($homeArea, 'the target folds its OWN pre-merge facts into an area too');
        $this->assertNull($homeArea->title);
        $this->assertSame('Whole-home write-up.', $homeArea->description);
        $this->assertSame('Kitchen write-up.', $kitchenArea->description);
        $this->assertSame('Mudroom write-up.', $mudroomArea->description);

        // Images moved in place — never re-created (their ids are what
        // Google/Yelp upload records reference).
        $this->assertSame($target->id, $targetImage->fresh()->project_id);
        $this->assertSame($homeArea->id, $targetImage->fresh()->project_area_id);
        $this->assertSame($target->id, $kitchenImage->fresh()->project_id);
        $this->assertSame($kitchenArea->id, $kitchenImage->fresh()->project_area_id);
        $this->assertSame($target->id, $mudroomImage->fresh()->project_id);
        $this->assertSame($mudroomArea->id, $mudroomImage->fresh()->project_area_id);

        // Everything else a source owned moved to the target.
        $this->assertTrue($target->testimonials()->whereKey($review->id)->exists(), 'the testimonials pivot moved');
        $this->assertSame($target->id, $collaborator->fresh()->project_id);
        $this->assertSame($target->id, $beforeAfter->fresh()->project_id);
        $this->assertSame($target->id, $timelapse->fresh()->project_id);
        $this->assertSame($target->id, $blog->fresh()->project_id, "the target had no blog post of its own, so the source's moved");

        // Sources are gone; each slug 301s to the target at its own area.
        $this->assertNull(Project::find($kitchen->id));
        $this->assertNull(Project::find($mudroom->id));

        $kitchenRedirect = ProjectSlugHistory::where('slug', $kitchen->slug)->first();
        $this->assertNotNull($kitchenRedirect);
        $this->assertSame($target->id, $kitchenRedirect->project_id);
        $this->assertSame('area-kitchen', $kitchenRedirect->anchor);
        $this->assertSame($kitchen->id, $kitchenRedirect->source_project_id);

        $this->get('/projects/'.$kitchen->slug)
            ->assertStatus(301)
            ->assertRedirect($target->url().'#area-kitchen');

        // The merged-away project's numeric id now answers "moved to"
        // through the admin API, and 301s through the public numeric route,
        // instead of either dead-ending.
        config(['services.admin_api.token' => 'test-admin-api-token']);
        $this->getJson("/api/admin/v1/projects/{$kitchen->id}", ['Authorization' => 'Bearer test-admin-api-token'])
            ->assertStatus(409)
            ->assertJsonPath('moved_to.id', $target->id)
            ->assertJsonPath('moved_to.anchor', 'area-kitchen');

        $this->get('/projects/'.$kitchen->id)
            ->assertStatus(301)
            ->assertRedirect($target->url().'#area-kitchen');
    }

    public function test_an_older_slug_the_source_already_carried_survives_the_merge_under_the_target(): void
    {
        ['target' => $target, 'kitchen' => $kitchen, 'mudroom' => $mudroom] = $this->mountProspectProjects();

        // The kitchen project was itself renamed once before the merge —
        // its own history table already has a row with no anchor.
        $kitchen->update(['slug' => 'mount-prospect-kitchen-remodel-2026']);
        $this->assertSame(1, ProjectSlugHistory::where('slug', 'mount-prospect-kitchen')->count());

        $this->artisan('projects:merge', [
            'target' => $target->slug,
            'sources' => [$kitchen->id, $mudroom->id],
        ])->assertSuccessful();

        $target->refresh();
        $kitchenArea = $target->areas()->where('project_type', 'kitchen')->sole();

        // repointRedirects(): the OLDER address survives, re-pointed at the
        // target — it would otherwise cascade away the moment the merged
        // source row is deleted (see GscSlugRedirectRecorder's docblock).
        $older = ProjectSlugHistory::where('slug', 'mount-prospect-kitchen')->first();
        $this->assertNotNull($older, 'the pre-merge rename history was not lost');
        $this->assertSame($target->id, $older->project_id);

        $this->get('/projects/mount-prospect-kitchen')
            ->assertStatus(301)
            ->assertRedirect($target->url());

        // The merge's own (newer) slug keeps its anchor; the re-pointed
        // older one has none of its own (it never did).
        $newest = ProjectSlugHistory::where('slug', 'mount-prospect-kitchen-remodel-2026')->first();
        $this->assertSame('area-kitchen', $newest->anchor);
        $this->assertNull($older->anchor);
        $this->assertSame($kitchenArea->anchor(), $newest->anchor);
    }

    public function test_the_targets_description_moves_into_its_own_area_and_a_new_slug_keeps_the_old_address(): void
    {
        ['target' => $target, 'kitchen' => $kitchen, 'mudroom' => $mudroom] = $this->mountProspectProjects();
        $oldSlug = $target->slug;
        $oldDescription = $target->description;

        $this->artisan('projects:merge', [
            'target' => $target->id, 'sources' => [$kitchen->id, $mudroom->id],
            '--title' => 'Mount Prospect, IL: Full-Home Remodel', '--slug' => 'mount-prospect-il-full-home-remodel',
        ])->assertSuccessful();

        $target->refresh();
        $this->assertNull($target->description);
        $this->assertSame($oldDescription, $target->areas()->orderBy('sort_order')->first()->description);
        $this->assertSame('mount-prospect-il-full-home-remodel', $target->slug);

        $selfAnchor = $target->areas()->orderBy('sort_order')->first()->anchor();
        $this->get('/projects/'.$oldSlug)->assertRedirect('/projects/mount-prospect-il-full-home-remodel#'.$selfAnchor)->assertStatus(301);
        $this->get('/projects/mount-prospect-il-full-home-remodel')->assertOk();
    }

    public function test_a_slug_another_project_uses_is_refused(): void
    {
        ['target' => $target, 'kitchen' => $kitchen, 'mudroom' => $mudroom] = $this->mountProspectProjects();

        $this->artisan('projects:merge', [
            'target' => $target->id, 'sources' => [$kitchen->id], '--slug' => $mudroom->slug,
        ])->assertFailed();

        $this->assertTrue($kitchen->fresh() !== null);
    }

    public function test_rerunning_the_same_merge_is_a_no_op(): void
    {
        ['target' => $target, 'kitchen' => $kitchen, 'mudroom' => $mudroom] = $this->mountProspectProjects();

        $this->artisan('projects:merge', [
            'target' => $target->slug,
            'sources' => [$kitchen->slug, $mudroom->slug],
            '--type' => 'home-remodel',
            '--title' => 'Mount Prospect Full-Home Design',
        ])->assertSuccessful();

        $target = $target->fresh();
        $areaIdsBefore = $target->areas()->pluck('id')->sort()->values()->all();
        $updatedAtBefore = $target->updated_at;

        $this->artisan('projects:merge', [
            'target' => $target->slug,
            'sources' => [$kitchen->slug, $mudroom->slug],
            '--type' => 'home-remodel',
            '--title' => 'Mount Prospect Full-Home Design',
        ])
            ->expectsOutputToContain('already merged with every given source')
            ->assertSuccessful();

        $target->refresh();
        $this->assertSame($areaIdsBefore, $target->areas()->pluck('id')->sort()->values()->all(), 'no duplicate areas');
        $this->assertTrue($updatedAtBefore->equalTo($target->updated_at), 'no spurious write on an idempotent re-run');
        $this->assertSame(1, ProjectSlugHistory::where('slug', $kitchen->slug)->count());
    }

    /**
     * ProjectObserver regenerates the sitemap + queues IndexNow on every
     * Project save — runQuietly() (App\Console\Commands\ProjectsMerge)
     * suppresses that for the whole merge and the command does it once
     * itself at the end instead. This only proves the merge still
     * completes with the observer wrapped in Model::withoutEvents(); the
     * sitemap file itself is covered by the sitemap generator's own tests.
     */
    public function test_the_merge_completes_with_project_observer_side_effects_suppressed(): void
    {
        ['target' => $target, 'kitchen' => $kitchen, 'mudroom' => $mudroom] = $this->mountProspectProjects();

        $this->artisan('projects:merge', [
            'target' => $target->slug,
            'sources' => [$kitchen->slug, $mudroom->slug],
        ])->assertSuccessful();

        $this->assertSame(3, $target->fresh()->areas()->count());
    }
}
