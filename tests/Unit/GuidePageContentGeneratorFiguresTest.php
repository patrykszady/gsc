<?php

namespace Tests\Unit;

use App\Services\AiContentService;
use App\Services\Seo\GuidePageContentGenerator;
use Tests\TestCase;

/**
 * The figure guard behind content:build-guides: every dollar amount and
 * duration in a draft must appear in the fact sheet, so the normalisation
 * has to make "$35K", "$35,000" and "$35,000+" one figure, and "8–12
 * weeks", "8-12 weeks" and "8 to 12 weeks" one duration.
 */
class GuidePageContentGeneratorFiguresTest extends TestCase
{
    private function generator(): GuidePageContentGenerator
    {
        return new GuidePageContentGenerator(new AiContentService);
    }

    public function test_dollar_amounts_normalise_across_commas_k_and_plus(): void
    {
        $figures = $this->generator()->figures('Kitchens run $35,000–$80,000+, custom $100K+; moving a sink adds $1,500–$4,000; additions are $200–$400 per sq ft.');

        foreach (['$35000', '$80000', '$100000', '$1500', '$4000', '$200', '$400'] as $expected) {
            $this->assertContains($expected, $figures);
        }
        $this->assertSame($figures, $this->generator()->figures('$35K and $80k and $100,000 and $1.5K-$4K and $200-$400'), 'the same figures however they are written');
    }

    public function test_durations_normalise_dashes_the_word_to_and_plurals_and_keep_range_endpoints(): void
    {
        $figures = $this->generator()->figures('Most take 8–12 weeks; custom cabinets 14+ weeks; review 10 business days; requests 24-48 hours in advance.');

        foreach (['8-12 week', '8 week', '12 week', '14 week', '10 business day', '24-48 hour', '24 hour', '48 hour'] as $expected) {
            $this->assertContains($expected, $figures);
        }
        $this->assertContains('8-12 week', $this->generator()->figures('eight to twelve is 8 to 12 weeks'));
        $this->assertContains('8-12 week', $this->generator()->figures('8-12 Weeks'));
    }

    public function test_numbers_without_a_unit_or_dollar_sign_are_not_figures(): void
    {
        $this->assertSame([], $this->generator()->figures('Founded in 2015, 5-star rated, a 2-sink vanity, 30–40% of the budget, 2018 International Codes.'));
    }

    public function test_word_count_spans_intro_sections_and_faq(): void
    {
        $count = $this->generator()->wordCount([
            'intro' => 'one two three',
            'sections' => [['heading' => 'four', 'body' => "five six\n\n- seven"]],
            'faq' => [['q' => 'eight?', 'a' => 'nine ten']],
        ]);

        $this->assertSame(11, $count);
    }
}
