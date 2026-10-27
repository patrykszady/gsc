<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Testimonial;
use Tests\TestCase;

/**
 * The area home page used to render two review blocks back to back:
 * <x-include town-review-quotes> ("What homeowners near {City} say", up to 3
 * real quotes from this town or its neighbours) followed unconditionally by
 * <livewire:testimonials-section> ("Your Neighbours in {City} Love Us", a
 * generic carousel). When the partial had real quotes, the generic carousel
 * just repeated them under a second heading. It now renders only when the
 * partial found none — see AreaServed::testimonialsWithNeighbours().
 */
class AreaHomeReviewCarouselTest extends TestCase
{
    public function test_the_generic_carousel_is_hidden_when_the_town_has_real_review_quotes(): void
    {
        AreaServed::create(['city' => 'Schaumburg', 'slug' => 'schaumburg']);
        Testimonial::create([
            'reviewer_name' => 'Kathy M.',
            'project_location' => 'Schaumburg, IL',
            'project_type' => 'kitchen',
            'review_description' => 'Wonderful kitchen remodel, highly recommend GS Construction.',
            'review_date' => '2026-05-01',
            'star_rating' => 5,
        ]);

        $page = $this->get('/areas-served/schaumburg')->assertOk();

        $page->assertSee('What Schaumburg homeowners say');
        $page->assertDontSee('Your Neighbours in Schaumburg Love Us');
    }

    public function test_the_generic_carousel_still_renders_when_the_town_has_no_review_quotes_at_all(): void
    {
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine']);

        $page = $this->get('/areas-served/palatine')->assertOk();

        $page->assertDontSee('What Palatine homeowners say');
        $page->assertDontSee('What homeowners near Palatine say');
        $page->assertSee('Your Neighbours in Palatine Love Us');
    }
}
