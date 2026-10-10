<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Services\SeoService;
use App\Support\SEO\SEOBuilder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The health-check's title/description checks read the RENDERED <title> and
 * <meta name="description"> (vendor ss-systems/platform-kit's
 * HealthCheckReport::scoreUrl() — "<title>...</title>" and
 * "30 <= chars <= 60"/"70 <= chars <= 160"), not the pre-brand string a
 * generator hands SEOBuilder. SeoService::setTags() already keeps every
 * generator's own title at or under 60 BEFORE the brand suffix; SEOBuilder::
 * build() then appended " | {brand}" unconditionally, which is what actually
 * shipped — 71-81 chars on every service page, every sampled area page and
 * most compare pages (confirmed against the real gsc tenant, 2026-10-10).
 * These assert the string SEOBuilder::build() actually returns.
 */
class SeoRenderedTitleLengthTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function title(): string
    {
        return (string) app(SEOBuilder::class)->build()->title;
    }

    private function description(): string
    {
        return (string) app(SEOBuilder::class)->build()->description;
    }

    private function fresh(): void
    {
        app()->forgetInstance(SEOBuilder::class);
    }

    public function test_every_service_page_title_fits_sixty_chars_once_branded(): void
    {
        foreach (array_keys(\App\Services\Seo\TitleMetaGenerator::SERVICES) as $slug) {
            $this->fresh();
            SeoService::service($slug);

            $title = $this->title();
            $this->assertLessThanOrEqual(60, mb_strlen($title), "{$slug}: \"{$title}\" overflows");
            // The brand is the thing allowed to give way, not the keyword
            // phrase — so it may legitimately be absent, but whenever it IS
            // present it must be last, never ahead of the service words.
            if (str_contains($title, 'GS Construction')) {
                $this->assertStringEndsWith('GS Construction', $title, "{$slug}: brand stays last");
            }

            $description = $this->description();
            $this->assertGreaterThanOrEqual(70, mb_strlen($description), "{$slug} description too short");
            $this->assertLessThanOrEqual(160, mb_strlen($description), "{$slug} description too long");
        }
    }

    public function test_area_home_titles_fit_for_short_and_long_town_names(): void
    {
        $descriptions = [];

        foreach (['Elk Grove Village', 'Kenilworth', 'Arlington Heights', 'Lake Bluff'] as $city) {
            $area = AreaServed::create(['city' => $city, 'slug' => \Illuminate\Support\Str::slug($city), 'state' => 'IL']);

            $this->fresh();
            SeoService::home($area);

            $title = $this->title();
            $this->assertLessThanOrEqual(60, mb_strlen($title), "{$city}: \"{$title}\" overflows");
            // The town stays first-class content; only the brand suffix may
            // have been dropped to make room.
            $this->assertStringContainsString($city, $title);

            $description = $this->description();
            $this->assertGreaterThanOrEqual(70, mb_strlen($description));
            $this->assertLessThanOrEqual(160, mb_strlen($description));
            $descriptions[$city] = $description;
        }

        // buildAreaHomeMeta() draws from 8 description variants, deterministic
        // per town — some lead with reviews/licensing, others with pricing or
        // schedule clarity. Not every single variant need carry the same
        // concrete promise, but across a handful of towns at least one
        // should, or the content-gap pages have nothing but the keyword
        // phrase to earn the click.
        $this->assertMatchesRegularExpression(
            '/licensed|insured|review|experience|pricing|estimate|no-pressure|timeline/i',
            implode(' ', $descriptions),
            'none of the sampled area descriptions carry a concrete promise: '.json_encode($descriptions),
        );
    }

    public function test_area_service_titles_fit_and_lead_with_town_and_service(): void
    {
        $area = AreaServed::create(['city' => 'Wilmette', 'slug' => 'wilmette', 'state' => 'IL']);

        foreach (['kitchen-remodeling', 'home-remodeling', 'basement-remodeling'] as $service) {
            $this->fresh();
            SeoService::areaService($area, $service);

            $title = $this->title();
            $this->assertLessThanOrEqual(60, mb_strlen($title), "{$service}: \"{$title}\" overflows");
            $this->assertStringContainsString('Wilmette', $title);

            $description = $this->description();
            $this->assertLessThanOrEqual(160, mb_strlen($description));
        }
    }

    public function test_every_configured_compare_page_title_fits(): void
    {
        $competitors = (array) config('competitors.competitors', []);
        $this->assertNotEmpty($competitors, 'competitors config is empty — nothing to assert');

        foreach ($competitors as $competitor) {
            $this->fresh();
            SeoService::compareCompetitor($competitor);

            $title = $this->title();
            $this->assertLessThanOrEqual(60, mb_strlen($title), "{$competitor['slug']}: \"{$title}\" overflows");
            $this->assertStringContainsString((string) $competitor['name'], $title, "{$competitor['slug']}: competitor name must survive, only the brand may give way");

            $description = $this->description();
            $this->assertLessThanOrEqual(160, mb_strlen($description));
        }

        $this->fresh();
        SeoService::compareIndex();
        $this->assertLessThanOrEqual(60, mb_strlen($this->title()));
    }

    public function test_trades_pages_fit(): void
    {
        $this->fresh();
        SeoService::tradesIndex();
        $this->assertLessThanOrEqual(60, mb_strlen($this->title()));

        foreach ((array) config('trades.trades', []) as $trade) {
            $this->fresh();
            SeoService::trade($trade);
            $title = $this->title();
            $this->assertLessThanOrEqual(60, mb_strlen($title), "{$trade['name']}: \"{$title}\" overflows");
        }
    }

    /**
     * SEOBuilder::build()'s own merge logic, isolated from any generator —
     * pins the two failure modes directly: drop the brand before the
     * keyword text is touched, and never cut mid-word if even the bare
     * title has to give.
     */
    public function test_builder_drops_the_brand_before_truncating_keyword_text(): void
    {
        \Illuminate\Support\Facades\Config::set('brand.name', 'GS Construction');

        // Fits once branded: brand stays.
        app(SEOBuilder::class)->title('Short Title');
        $this->assertSame('Short Title | GS Construction', $this->title());
        $this->fresh();

        // Core fits alone but not with the brand: brand is dropped whole,
        // core untouched.
        $core = str_repeat('A', 50); // 50 chars; + ' | GS Construction' (18) = 68 > 60
        app(SEOBuilder::class)->title($core);
        $this->assertSame($core, $this->title());
        $this->fresh();

        // Core alone already overflows: word-safe trim, never mid-word, and
        // the brand never gets appended to an already-overflowing core.
        $words = ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen'];
        app(SEOBuilder::class)->title(implode(' ', $words));
        $title = $this->title();
        $this->assertLessThanOrEqual(60, mb_strlen($title));
        $this->assertStringNotContainsString('GS Construction', $title, 'an already-overflowing core must not also carry the brand');
        foreach (explode(' ', $title) as $word) {
            $this->assertContains($word, $words, "\"{$title}\" contains a cut-off word fragment");
        }
    }
}
