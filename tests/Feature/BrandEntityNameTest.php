<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * One business name everywhere structured data and AI feeds name the
 * company: "GS Construction & Remodeling" (config('brand.display_name')),
 * never the bare "GS Construction" — the short form is ambiguous with other
 * GS Constructions nationwide. Before this, the homepage title and schema's
 * Organization/WebSite nodes already carried the full name, but /geo/
 * answers.json's publisher, llms.txt's "About"/"Why" lines, and the
 * per-town HomeAndConstructionBusiness, LocalBusiness and Product/Brand
 * nodes all still said the bare "GS Construction".
 */
class BrandEntityNameTest extends TestCase
{
    public function test_geo_answers_json_publisher_carries_the_full_name(): void
    {
        $data = $this->getJson('/geo/answers.json')->assertOk()->json();

        $this->assertSame(config('brand.display_name'), $data['publisher']['name']);
        $this->assertStringStartsWith(config('brand.display_name'), $data['name']);
    }

    public function test_llms_txt_carries_the_full_name(): void
    {
        $body = $this->get('/llms.txt')->assertOk()->getContent();
        // Rendered through Blade's {{ }}, so "&" comes out HTML-escaped even
        // in this text/plain file — true before this change too.
        $name = e(config('brand.display_name'));

        $this->assertStringContainsString('# llms.txt for '.$name, $body);
        $this->assertStringContainsString($name.' is a licensed, bonded and insured', $body);
    }

    public function test_the_homepage_organization_and_local_business_schema_use_the_full_name(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/"@type":\s*"HomeAndConstructionBusiness".{0,400}"name":\s*"'.preg_quote(config('brand.display_name'), '/').'"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/"@type":\s*"Organization".{0,400}"name":\s*"'.preg_quote(config('brand.display_name'), '/').'"/s',
            $html
        );
    }

    public function test_a_towns_home_and_construction_business_node_uses_the_full_name(): void
    {
        AreaServed::create(['city' => 'Schaumburg', 'slug' => 'schaumburg']);

        $html = $this->get('/areas-served/schaumburg')->assertOk()->getContent();

        // json_encode(..., JSON_UNESCAPED_SLASHES) (no JSON_UNESCAPED_UNICODE)
        // is what the schema component uses, so the em dash comes out as
        // "—" — build the expected fragment the same way rather than
        // hand-writing the escape.
        $expectedName = json_encode(config('brand.display_name').' — Schaumburg', JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"name": '.$expectedName, $html);
    }

    /**
     * Google shows "gs.construction" instead of the name when the home page
     * names the business two ways (2026-10-30): the header, the opening
     * summary and the services list now all carry
     * "GS Construction & Remodeling", like the markup and the title.
     */
    public function test_the_homepage_names_the_business_the_same_way_everywhere_a_reader_sees_it(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $full = e(config('brand.display_name'));

        $this->assertMatchesRegularExpression('#uppercase tracking-wide[^>]*>\s*'.preg_quote(e(config('brand.name')), '#').'\s*</span>(?:\s|<!--.*?-->)*<span[^>]*>\s*&amp; Remodeling\s*</span>#s', $html, 'the header carries the full name');
        $this->assertStringContainsString('aria-label="About '.$full.'"', $html);
        $this->assertMatchesRegularExpression('#data-speakable="business-summary">\s*'.preg_quote($full, '#').' is a family-owned#', $html);
        $this->assertStringContainsString(json_encode('Remodeling Services — '.config('brand.display_name'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $html);
        $this->assertStringNotContainsString('>GS CONSTRUCTION<', $html);
    }
}
