<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A service spoke (/areas-served/{town}/services/{service}) used to open
 * with generated copy (AreaServiceContent) and nothing of the town's own
 * ahead of it. Since 2026-10-10 it leads with the town's own proof for THAT
 * trade — a matching project, a matching review, a one-line fact built from
 * data the town already has, and links onward — ABOVE the generated copy,
 * falling back honestly (never claiming a match that isn't there) when the
 * town has no project or review of its own. See partials/area-service-proof.
 */
class AreaServiceSpokeProofTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function town(string $city, string $slug, array $extra = []): AreaServed
    {
        return AreaServed::create(array_merge([
            'city' => $city, 'slug' => $slug, 'state' => 'IL',
            'local_intro' => str_repeat("{$city} copy about the town. ", 40),
            'landmarks' => "{$city} Town Hall and the old Metra depot",
        ], $extra));
    }

    public function test_a_service_spoke_leads_with_the_towns_own_matching_project_and_review_above_the_generated_copy(): void
    {
        $area = $this->town('Mapleton', 'mapleton');
        $project = Project::create([
            'title' => 'Mapleton Kitchen Remodel', 'project_type' => 'kitchen',
            'location' => 'Mapleton, IL', 'is_published' => true,
        ]);
        Testimonial::create([
            'reviewer_name' => 'Jamie Proofreader', 'project_location' => 'Mapleton, IL', 'project_type' => 'kitchen',
            'review_description' => 'Our new kitchen island is incredible and the crew was fantastic.',
            'review_date' => now()->subMonth(), 'star_rating' => 5, 'is_hidden' => false,
        ]);
        Cache::flush();

        $page = $this->get('/areas-served/mapleton/services/kitchen-remodeling')->assertOk();

        // The fact line, the matching project, the matching review and the
        // sibling-spoke/hub links all sit ahead of the generated copy's own
        // heading ("Kitchen remodeling in Mapleton, IL", from
        // partials/area-service-content).
        $page->assertSeeInOrder([
            'About Mapleton:',
            'Mapleton copy about the town.',
            'Mapleton Kitchen Remodel',
            'new kitchen island is incredible',
            'Mapleton remodeling overview',
            'Kitchen remodeling in Mapleton, IL',
        ]);
        $page->assertSee($project->url(), false);
        $page->assertSee($area->url, false);
    }

    public function test_a_service_spoke_falls_back_to_the_towns_other_project_and_other_review_when_none_match_the_trade(): void
    {
        $this->town('Mapleton', 'mapleton');
        $project = Project::create([
            'title' => 'Mapleton Bathroom Remodel', 'project_type' => 'bathroom',
            'location' => 'Mapleton, IL', 'is_published' => true,
        ]);
        Testimonial::create([
            'reviewer_name' => 'Pat Otherwork', 'project_location' => 'Mapleton, IL', 'project_type' => 'bathroom',
            'review_description' => 'Our new tile and vanity look amazing.',
            'review_date' => now()->subMonth(), 'star_rating' => 5, 'is_hidden' => false,
        ]);
        Cache::flush();

        $page = $this->get('/areas-served/mapleton/services/kitchen-remodeling')->assertOk();

        // No kitchen project or review of its own: the town's OTHER project
        // and OTHER review stand in, honestly — the heading never claims a
        // kitchen match, and the project's own "Bathroom Remodel" type tag
        // (via x-project-card's typeLabel) says what it actually is.
        $page->assertSeeInOrder([
            "Remodeling projects we've completed in Mapleton, IL",
            'Mapleton Bathroom Remodel',
            'What Mapleton homeowners say',
            'Our new tile and vanity look amazing',
            'Kitchen remodeling in Mapleton, IL',
        ]);
        $page->assertDontSee("Kitchen remodeling we've completed in Mapleton");
        $page->assertDontSee('say about our kitchen remodeling');
        $page->assertSee($project->url(), false);
    }

    public function test_a_service_spoke_falls_back_to_a_nearby_towns_matching_project_when_this_town_has_none(): void
    {
        $this->town('Mapleton', 'mapleton', ['latitude' => 42.30, 'longitude' => -88.10]);
        $this->town('Birchwood', 'birchwood', ['latitude' => 42.32, 'longitude' => -88.12]);
        $project = Project::create([
            'title' => 'Birchwood Kitchen Remodel', 'project_type' => 'kitchen',
            'location' => 'Birchwood, IL', 'is_published' => true,
        ]);
        Cache::flush();

        $page = $this->get('/areas-served/mapleton/services/kitchen-remodeling')->assertOk();

        // Mapleton has no project of its own at all, so the fallback reaches
        // its nearest neighbour's matching project — labeled with that
        // neighbour's real town, never implied to be Mapleton's own work.
        $page->assertSeeInOrder([
            "Kitchen remodeling we've completed near Mapleton, IL",
            'Birchwood Kitchen Remodel',
            'Birchwood, IL',
            'Kitchen remodeling in Mapleton, IL',
        ]);
        $page->assertSee($project->url(), false);
    }

    public function test_a_service_spoke_with_no_projects_or_reviews_anywhere_still_renders_the_generated_copy_cleanly(): void
    {
        $this->town('Mapleton', 'mapleton');
        Cache::flush();

        // No project or review anywhere (this town or any neighbour): the
        // proof block has only the fact line to show, and the page still
        // renders the generated copy cleanly with no broken/empty sections.
        $this->get('/areas-served/mapleton/services/kitchen-remodeling')->assertOk()
            ->assertSeeInOrder(['About Mapleton:', 'Mapleton copy about the town.', 'Kitchen remodeling in Mapleton, IL'])
            ->assertDontSee('What Mapleton homeowners say');
    }

    public function test_the_fact_line_prefers_the_towns_permit_rule_and_shows_one_whole_sentence(): void
    {
        $this->town('Mapleton', 'mapleton', [
            'permit_notes' => 'Mapleton requires a permit for any remodel valued over $500, pulled by the contractor. Inspections are booked through the village hall a day ahead.',
        ]);
        Cache::flush();

        // The permit rule beats the town copy, and the fact line carries only
        // its first sentence — whole, no cut mid-word, no landmark list. (The
        // page shows the full permit note further down, so the check reads
        // the proof paragraph itself rather than the whole page.)
        $page = $this->get('/areas-served/mapleton/services/kitchen-remodeling')->assertOk()
            ->assertDontSee('About Mapleton:');
        $this->assertSame(1, preg_match('~Permits in Mapleton:</span>\s*(.*?)\s*</p>~s', $page->getContent(), $fact));
        $this->assertSame('Mapleton requires a permit for any remodel valued over $500, pulled by the contractor.', html_entity_decode($fact[1]));
    }

    public function test_the_towns_matching_reviews_come_from_one_query_and_are_cached(): void
    {
        $area = $this->town('Mapleton', 'mapleton');
        foreach (range(1, 40) as $i) {
            Testimonial::create([
                'reviewer_name' => "Reviewer {$i}", 'project_location' => "Elsewhere {$i}, IL", 'project_type' => 'kitchen',
                'review_description' => 'A kitchen in another town.', 'review_date' => now()->subDays($i),
                'star_rating' => 5, 'is_hidden' => false,
            ]);
        }
        Testimonial::create([
            'reviewer_name' => 'Local Kitchen', 'project_location' => 'Mapleton, IL', 'project_type' => 'kitchen',
            'review_description' => 'Our kitchen came out great.', 'review_date' => now()->subDays(2),
            'star_rating' => 5, 'is_hidden' => false,
        ]);
        Testimonial::create([
            'reviewer_name' => 'Local Bath', 'project_location' => 'Mapleton, IL', 'project_type' => 'bathroom',
            'review_description' => 'The bathroom is lovely.', 'review_date' => now()->subDays(1),
            'star_rating' => 5, 'is_hidden' => false,
        ]);
        Cache::flush();

        // The first version of the proof block resolved every visible review's
        // town with a query of its own — 42 queries here. One is the budget.
        DB::enableQueryLog();
        $matched = $area->localTestimonialsAbout('kitchen-remodeling', 3);
        $this->assertLessThanOrEqual(1, count(DB::getQueryLog()));
        $this->assertSame(['Local Kitchen'], $matched->pluck('reviewer_name')->all());

        DB::flushQueryLog();
        $area->localTestimonialsAbout('kitchen-remodeling', 3);
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_first_sentence_keeps_abbreviations_together_and_cuts_a_long_one_at_a_word(): void
    {
        $this->assertSame(
            'Permits go through the office on N. Main St. and take two weeks.',
            AreaServed::firstSentence('Permits go through the office on N. Main St. and take two weeks. Fees vary.')
        );
        $this->assertSame('Short. Then a longer second sentence follows here.', AreaServed::firstSentence('Short. Then a longer second sentence follows here. Third.'));

        $long = str_repeat('word ', 80).'end.';
        $cut = AreaServed::firstSentence($long);
        $this->assertLessThanOrEqual(261, mb_strlen($cut));
        $this->assertStringEndsWith('word…', $cut);
    }
}
