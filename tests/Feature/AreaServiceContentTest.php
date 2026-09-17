<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\AreaServiceContent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A town's five service pages used to share the town's whole local block,
 * so its kitchen and bathroom pages were 70-80% the same prose. Each pair
 * now carries copy written for that service in that town, and the town
 * About page no longer repeats the company story from /about.
 */
class AreaServiceContentTest extends TestCase
{
    private const LOCAL_INTRO = 'Western Springs grew in distinct rings around its Metra stop. The Old Town core keeps its 1920s foursquares. Field Park filled in with post-war ranches and split-levels. Timber Trails brought newer construction on larger lots.';

    private function town(): AreaServed
    {
        return AreaServed::create([
            'city' => 'Western Springs', 'slug' => 'western-springs', 'state' => 'IL', 'latitude' => 41.8096, 'longitude' => -87.9007,
            'intro' => 'GS Construction remodels homes across Western Springs and the west suburbs.',
            'local_intro' => self::LOCAL_INTRO,
            'neighborhoods' => 'Old Town, Field Park, Timber Trails',
            'landmarks' => 'Tower Green, Spring Rock Park',
            'permit_notes' => 'The Village of Western Springs requires building permits for structural, electrical and plumbing work; GS Construction handles the applications.',
            'popular_projects' => 'Western Springs homeowners most often ask for open-plan first floors and primary suite additions.',
            'how_we_work' => 'For Western Springs homeowners a project runs under one contract with one project lead.',
        ]);
    }

    private function kitchenCopy(AreaServed $area): AreaServiceContent
    {
        return AreaServiceContent::create([
            'area_served_id' => $area->id, 'service' => 'kitchen-remodeling',
            'intro' => 'Kitchens in the Old Town foursquares are boxed off from the dining room by a load-bearing wall, and opening that wall is where most Western Springs kitchen remodels start.',
            'popular_requests' => 'Islands with seating and a pantry carved from the back hall are the two requests we hear most in Western Springs kitchens.',
            'permit_notes' => 'A Western Springs kitchen remodel that moves plumbing or adds circuits is reviewed by the village before work begins; we file the applications and book the inspections.',
            'faq' => [['question' => 'Can an Old Town foursquare kitchen be opened to the dining room?', 'answer' => 'Usually, with a beam sized by an engineer.']],
            'generated_at' => now(),
        ]);
    }

    public function test_a_service_page_carries_its_own_copy_and_only_a_teaser_of_the_town(): void
    {
        $area = $this->town();
        $this->kitchenCopy($area);

        $page = $this->get('/areas-served/western-springs/services/kitchen-remodeling')->assertOk();

        $page->assertSee('Kitchens in the Old Town foursquares are boxed off')
            ->assertSee('Islands with seating and a pantry')
            ->assertSee('Permits for kitchen remodeling in Western Springs')
            ->assertSee('Can an Old Town foursquare kitchen be opened to the dining room?')
            // The town's own description is a two-sentence teaser and a link, not the whole block.
            ->assertSee('Western Springs grew in distinct rings around its Metra stop.')
            ->assertDontSee('Timber Trails brought newer construction on larger lots.')
            ->assertSee('More about our work in Western Springs')
            // The templated trio of questions is gone from this page.
            ->assertDontSee('How do you scope Kitchen Remodeling projects in Western Springs?');

        // The bathroom page, with no copy of its own yet, still renders the shared block.
        $this->get('/areas-served/western-springs/services/bathroom-remodeling')->assertOk()
            ->assertSee('Timber Trails brought newer construction on larger lots.')
            ->assertSee('How do you scope Bathroom Remodeling projects in Western Springs?')
            ->assertDontSee('Kitchens in the Old Town foursquares');
    }

    public function test_a_service_page_leads_with_reviews_of_its_own_trade(): void
    {
        $this->town();
        foreach ([
            ['kitchen', 'The kitchen island they built is the heart of our house now.'],
            ['bathroom', 'Our new walk-in shower is exactly what we hoped for.'],
        ] as [$type, $text]) {
            \App\Models\Testimonial::create([
                'reviewer_name' => ucfirst($type).' Homeowner', 'project_location' => 'Western Springs, IL', 'project_type' => $type,
                'review_description' => $text, 'review_date' => now()->subMonths(2), 'star_rating' => 5, 'is_hidden' => false,
            ]);
        }

        $this->get('/areas-served/western-springs/services/kitchen-remodeling')->assertOk()
            ->assertSeeInOrder(['The kitchen island they built', 'Our new walk-in shower'])
            ->assertSee('kitchen remodeling in Western Springs is work Greg and Patryk run themselves');

        $this->get('/areas-served/western-springs/services/bathroom-remodeling')->assertOk()
            ->assertSeeInOrder(['Our new walk-in shower', 'The kitchen island they built'])
            ->assertSee('bathroom remodeling in Western Springs is work Greg and Patryk run themselves');
    }

    public function test_a_town_about_page_no_longer_repeats_the_company_story(): void
    {
        $this->town();

        $this->get('/areas-served/western-springs/about')->assertOk()
            ->assertSee('Serving Western Springs with Quality Craftsmanship')
            ->assertSee('How we work in Western Springs')
            ->assertSee('What Western Springs homeowners bring us')
            ->assertSee('Read the whole story of Greg')
            ->assertDontSee('When your career starts with custom cabinetry in NYC')
            ->assertDontSee('We never cut corners.');

        // The story itself still lives on /about.
        $this->get('/about')->assertOk()->assertSee('When your career starts with custom cabinetry in NYC');
    }

    public function test_the_generator_writes_one_pair_at_a_time_from_what_gemini_returns_and_never_restates_the_town(): void
    {
        Config::set('services.google.gemini_api_key', 'gemini-test');
        Config::set('services.google.gemini_rpm_limit', 0);
        $area = $this->town();

        $copy = json_encode([
            'intro' => 'Kitchens in the Old Town foursquares are boxed off from the dining room.',
            'popular_requests' => 'Islands with seating are the top request.',
            'permit_notes' => 'Moving plumbing or adding circuits is reviewed by the village; we file the applications.',
            'faq' => [['question' => 'Can the kitchen wall come down?', 'answer' => 'Usually, with an engineered beam.'], ['question' => 'How long does it take?', 'answer' => 'Eight to twelve weeks.'], ['question' => 'Do we need to move out?', 'answer' => 'No, we set up a temporary kitchen.']],
        ]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $copy]]]]]])]);

        $this->artisan('seo:generate-area-service-content', ['--slug' => 'western-springs', '--service' => 'kitchen-remodeling', '--dry-run' => true])
            ->assertSuccessful();
        $this->assertSame(0, AreaServiceContent::count());

        $this->artisan('seo:generate-area-service-content', ['--slug' => 'western-springs', '--service' => 'kitchen-remodeling'])
            ->expectsConfirmation('Write AI-generated copy for 1 town/service pages?', 'yes')
            ->assertSuccessful();

        $row = AreaServiceContent::sole();
        $this->assertSame('kitchen-remodeling', $row->service);
        $this->assertSame($area->id, $row->area_served_id);
        $this->assertStringStartsWith('Kitchens in the Old Town', $row->intro);
        $this->assertCount(3, $row->faqItems());
        $this->assertNotNull($row->generated_at);

        // The prompt names the trade and hands over the town's copy as context not to restate.
        Http::assertSent(function (Request $request) {
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text');

            return str_contains($prompt, 'kitchen remodeling in **Western Springs, Illinois**')
                && str_contains($prompt, 'do NOT restate')
                && str_contains($prompt, 'Field Park filled in with post-war ranches')
                && str_contains($prompt, 'load-bearing walls when a kitchen is opened up');
        });

        // Done pairs are skipped; --force writes them again.
        $this->artisan('seo:generate-area-service-content', ['--slug' => 'western-springs', '--service' => 'kitchen-remodeling'])
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
        $this->artisan('seo:generate-area-service-content', ['--slug' => 'western-springs', '--service' => 'kitchen-remodeling', '--force' => true])
            ->expectsConfirmation('Write AI-generated copy for 1 town/service pages?', 'yes')
            ->assertSuccessful();
        $this->assertSame(1, AreaServiceContent::count());
    }
}
