<?php

namespace Tests\Unit;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * Unit coverage for the area-content helpers ported from jpeterson-design's
 * App\Models\Area (2026-09-11): normaliseFaq(), sectionsMap(), showsSection().
 * No DB needed — these are pure functions of an in-memory model instance.
 */
class AreaServedContentTest extends TestCase
{
    public function test_normalise_faq_drops_blank_rows_and_trims_text(): void
    {
        $faq = AreaServed::normaliseFaq([
            ['question' => '  Do you work here?  ', 'answer' => ' Yes. '],
            ['question' => '', 'answer' => ''],
            ['question' => 'No answer', 'answer' => ''],
            ['q' => 'Short-form keys?', 'a' => 'Also supported.'],
        ]);

        $this->assertSame([
            ['question' => 'Do you work here?', 'answer' => 'Yes.'],
            ['question' => 'Short-form keys?', 'answer' => 'Also supported.'],
        ], $faq);
    }

    public function test_normalise_faq_rejects_non_array_input(): void
    {
        $this->assertSame([], AreaServed::normaliseFaq(null));
        $this->assertSame([], AreaServed::normaliseFaq('not an array'));
    }

    public function test_list_from_text_splits_and_trims_comma_separated_values(): void
    {
        $this->assertSame(
            ['Oakhurst', 'Winnona Park'],
            AreaServed::listFromText(' Oakhurst,  Winnona Park , ')
        );
        $this->assertSame([], AreaServed::listFromText(null));
    }

    public function test_sections_map_defaults_an_absent_switch_on_content(): void
    {
        $area = new AreaServed(['city' => 'Decatur', 'landmarks' => 'Heritage Park', 'sections' => null]);

        $map = $area->sectionsMap();

        $this->assertTrue($map['landmarks'], 'has content, no stored switch: defaults on');
        $this->assertFalse($map['intro'], 'no content, no stored switch: defaults off');
        $this->assertSame(array_keys(AreaServed::SECTIONS), array_keys($map), 'every known key present');
    }

    public function test_sections_map_honours_an_explicit_switch_over_content(): void
    {
        $area = new AreaServed([
            'city' => 'Decatur',
            'landmarks' => 'Heritage Park',
            'sections' => ['landmarks' => false, 'intro' => true],
        ]);

        $map = $area->sectionsMap();

        $this->assertFalse($map['landmarks'], 'explicitly switched off despite having content');
        $this->assertTrue($map['intro'], 'explicitly switched on even though empty — showsSection still hides it');
    }

    public function test_shows_section_requires_both_the_switch_and_content(): void
    {
        $area = new AreaServed([
            'city' => 'Decatur',
            'intro' => 'Welcome to Decatur.',
            'sections' => ['intro' => true, 'landmarks' => true],
        ]);

        $this->assertTrue($area->showsSection('intro'), 'switched on and has content');
        $this->assertFalse($area->showsSection('landmarks'), 'switched on but empty — nothing to show');
        $this->assertFalse($area->showsSection('bogus'), 'unknown key is never shown');
    }

    public function test_faq_items_double_as_the_faq_sections_content_check(): void
    {
        $area = new AreaServed(['city' => 'Decatur', 'faq' => [['question' => 'Q?', 'answer' => 'A.']]]);
        $this->assertTrue($area->showsSection('faq'));

        $empty = new AreaServed(['city' => 'Decatur', 'faq' => []]);
        $this->assertFalse($empty->showsSection('faq'));
    }
}
