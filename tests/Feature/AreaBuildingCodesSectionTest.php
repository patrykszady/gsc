<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * The "Building codes in {City}" section on an area's home page — renders
 * right after the pricing guide, but only once hive.contractors has actually
 * found an answer for that town (areas:sync-building-codes). A town still
 * pending (or never asked) shows nothing rather than a placeholder.
 */
class AreaBuildingCodesSectionTest extends TestCase
{
    public function test_the_section_renders_when_codes_are_stored(): void
    {
        AreaServed::create([
            'city' => 'Schaumburg',
            'slug' => 'schaumburg',
            'building_codes' => ['International Residential Code, 2021 Edition', 'International Energy Conservation Code, 2021 Edition'],
            'building_codes_source_url' => 'https://www.schaumburg.com/building-codes',
            'building_codes_note' => 'For permits issued after 1/1/2026',
            'building_codes_checked_at' => '2026-10-27T13:00:00+00:00',
        ]);

        $page = $this->get('/areas-served/schaumburg')->assertOk();

        $page->assertSee('Building codes in Schaumburg');
        $page->assertSee('International Residential Code, 2021 Edition');
        $page->assertSee('International Energy Conservation Code, 2021 Edition');
        $page->assertSee('With local amendments.');
        $page->assertSee('For permits issued after 1/1/2026');
        $page->assertSee('checked Oct 27, 2026');
        $page->assertSee('www.schaumburg.com', false);
    }

    public function test_the_section_is_absent_when_no_codes_are_stored_yet(): void
    {
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine']);

        $page = $this->get('/areas-served/palatine')->assertOk();

        $page->assertDontSee('Building codes in Palatine');
    }
}
