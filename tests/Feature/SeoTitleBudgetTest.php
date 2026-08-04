<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use App\Services\Seo\TitleMetaGenerator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The title budget, and the two places it used to leak.
 *
 * TitleMetaGenerator does NOT emit the final <title>: SEOBuilder appends
 * " | {brand}" afterwards. The generator once assumed it owned the whole
 * 60-character budget, so every area page shipped 67 characters and
 * /services/bathroom-remodeling shipped 76 — against a cutoff near 60. What
 * Google truncated was the brand.
 */
class SeoTitleBudgetTest extends TestCase
{
    private function finalTitle(string $core): string
    {
        $brand = (string) config('brand.name');

        return $brand === '' ? $core : $core . ' | ' . $brand;
    }

    public function test_an_area_title_fits_once_the_brand_is_appended(): void
    {
        $generator = app(TitleMetaGenerator::class);

        foreach (['Lake Bluff', 'Kenilworth', 'Schaumburg', 'Highland Park', 'Wilmette', 'Arlington Heights'] as $city) {
            $title = $generator->forArea(new AreaServed(['city' => $city]))['title'];

            $this->assertLessThanOrEqual(
                60,
                mb_strlen($this->finalTitle($title)),
                "{$city} overflows once the brand suffix is added",
            );
        }
    }

    public function test_the_budget_follows_the_tenant_brand(): void
    {
        // brand.name is overlaid per tenant, so the budget cannot be a
        // hardcoded number — a longer brand must shrink the core.
        $generator = app(TitleMetaGenerator::class);
        $area = new AreaServed(['city' => 'Schaumburg']);

        Config::set('brand.name', 'GS Construction');
        $short = $generator->forArea($area)['title'];

        Config::set('brand.name', 'A Considerably Longer Tenant Brand Name');
        $long = $generator->forArea($area)['title'];

        $this->assertLessThanOrEqual(mb_strlen($short), mb_strlen($long));
    }

    public function test_the_area_title_contains_the_phrase_people_search(): void
    {
        // "lake bluff home remodeling" carries 857 impressions/month. The old
        // title said only "Lake Bluff Remodeling" — the query never appeared,
        // and Google's own rewrite inserted the missing words.
        $title = app(TitleMetaGenerator::class)->forArea(new AreaServed(['city' => 'Lake Bluff']))['title'];

        $this->assertStringContainsStringIgnoringCase('Lake Bluff Home Remodeling', $title);
    }

    public function test_titles_carry_no_repeated_promotional_boilerplate(): void
    {
        $generator = app(TitleMetaGenerator::class);

        foreach (['Evanston', 'Glenview', 'Winnetka'] as $city) {
            $title = $generator->forArea(new AreaServed(['city' => $city]))['title'];

            $this->assertStringNotContainsString('5★', $title);
            $this->assertStringNotContainsString('Free Estimates', $title);
        }
    }

    public function test_descriptions_are_complete_sentences(): void
    {
        // fitDesc() truncates the tail, so the CTA has to lead. Previously
        // "5-star rated. Free estimate." sat last and was amputated on all 40
        // area pages, every one ending in a bare ellipsis.
        $generator = app(TitleMetaGenerator::class);

        foreach (['Lake Bluff', 'Highland Park', 'Arlington Heights'] as $city) {
            $area = new AreaServed(['city' => $city]);

            foreach ([null, 'kitchen-remodeling', 'bathroom-remodeling'] as $service) {
                $desc = $generator->forArea($area, $service)['description'];

                $this->assertStringEndsNotWith('…', $desc, "{$city}/{$service} truncates");
                $this->assertLessThanOrEqual(158, mb_strlen($desc));
                $this->assertStringContainsStringIgnoringCase('free estimate', $desc);
            }
        }
    }

    public function test_a_project_description_names_its_town(): void
    {
        // Project carries `location`, never `city`. Reading the missing
        // attribute made every project page say "in the Chicago suburbs",
        // discarding the only page-unique local signal these pages have.
        $project = new Project(['title' => 'Whole Home Renovation', 'location' => 'Wilmette, IL']);

        $desc = app(TitleMetaGenerator::class)->forProject($project)['description'];

        $this->assertStringContainsString('Wilmette', $desc);
        $this->assertStringNotContainsString('the Chicago suburbs', $desc);
    }

    public function test_a_project_title_does_not_carry_the_brand_twice(): void
    {
        $project = new Project(['title' => 'Kitchen Remodel', 'location' => 'Palatine, IL']);

        $title = app(TitleMetaGenerator::class)->forProject($project)['title'];

        $this->assertStringNotContainsString('GS Construction', $title, 'SEOBuilder owns the brand suffix');
        $this->assertLessThanOrEqual(60, mb_strlen($this->finalTitle($title)));
    }
}
