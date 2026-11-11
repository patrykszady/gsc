<?php

namespace Tests\Feature\Seo;

use App\Models\AreaServed;
use App\Models\Project;
use App\Support\Seo\CrawlFiles;
use App\Support\Seo\FaqBank\GscFaqBankSource;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use SsSystems\Platform\Seo\MapPack\MapPackStore;
use Tests\TestCase;

/**
 * App\Support\Seo\FaqBank\GscFaqBankSource — what this site hands the
 * kit's FAQ bank builder (kit 0.24.0): its sitemap URL set labelled by
 * path and ordered by kind, the served towns, and the names an answer
 * must never carry. The sitemap is written by hand here the way
 * BingCoverageCommandTest writes it, plus one run of the real
 * sitemap:generate over seeded rows.
 */
class GscFaqBankSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param  list<string>  $urls */
    private function writeSitemap(array $urls): void
    {
        $path = CrawlFiles::sitemapPath();
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, '<?xml version="1.0"?><urlset>'
            .implode('', array_map(fn (string $u): string => "<url><loc>{$u}</loc></url>", $urls))
            .'</urlset>');
    }

    private function source(): GscFaqBankSource
    {
        return app(GscFaqBankSource::class);
    }

    public function test_pages_are_labelled_by_path_and_leave_out_the_faq_the_reviews_and_the_photo_pages(): void
    {
        $this->writeSitemap([
            'https://gs.construction/',
            'https://gs.construction/about',
            'https://gs.construction/faq',
            'https://gs.construction/reviews',
            'https://gs.construction/reviews/jane-doe-palatine',
            'https://gs.construction/services',
            'https://gs.construction/services/kitchen-remodeling',
            'https://gs.construction/remodeling/small-bathroom-remodel',
            'https://gs.construction/costs',
            'https://gs.construction/costs/kitchen-remodel-cost',
            'https://gs.construction/permits/palatine',
            'https://gs.construction/projects',
            'https://gs.construction/projects/kitchens',
            'https://gs.construction/projects/palatine-kitchen',
            'https://gs.construction/projects/palatine-kitchen/photos/island-after',
            'https://gs.construction/areas-served',
            'https://gs.construction/areas-served/palatine',
            'https://gs.construction/areas-served/palatine/services/kitchen-remodeling',
            'https://gs.construction/areas-served/palatine/lead-pipe-replacement',
            'https://gs.construction/blog',
            'https://gs.construction/blog/the-palatine-kitchen-story',
            'https://gs.construction/compare/airoom',
            'https://gs.construction/service-area/60067',
        ]);

        $byUrl = array_column($this->source()->pages(), 'kind', 'url');

        $this->assertSame([
            'https://gs.construction/services/kitchen-remodeling' => 'service',
            'https://gs.construction/remodeling/small-bathroom-remodel' => 'guide',
            'https://gs.construction/costs/kitchen-remodel-cost' => 'cost',
            'https://gs.construction/permits/palatine' => 'permit',
            'https://gs.construction/' => 'page',
            'https://gs.construction/about' => 'page',
            'https://gs.construction/services' => 'page',
            'https://gs.construction/costs' => 'page',
            'https://gs.construction/projects' => 'page',
            'https://gs.construction/projects/kitchens' => 'page',
            'https://gs.construction/areas-served' => 'page',
            'https://gs.construction/blog' => 'page',
            'https://gs.construction/compare/airoom' => 'page',
            'https://gs.construction/service-area/60067' => 'page',
            'https://gs.construction/projects/palatine-kitchen' => 'project',
            'https://gs.construction/areas-served/palatine' => 'town',
            'https://gs.construction/areas-served/palatine/services/kitchen-remodeling' => 'town_service',
            'https://gs.construction/areas-served/palatine/lead-pipe-replacement' => 'town_service',
            'https://gs.construction/blog/the-palatine-kitchen-story' => 'blog',
        ], $byUrl, 'every kept URL, in kind order, the sitemap order kept within a kind');

        foreach ([
            'https://gs.construction/faq',
            'https://gs.construction/reviews',
            'https://gs.construction/reviews/jane-doe-palatine',
            'https://gs.construction/projects/palatine-kitchen/photos/island-after',
        ] as $leftOut) {
            $this->assertArrayNotHasKey($leftOut, $byUrl, "{$leftOut} is not mined");
        }
    }

    public function test_kinds_come_in_the_documented_order_whatever_order_the_sitemap_lists_them(): void
    {
        $this->writeSitemap([
            'https://gs.construction/blog/story',
            'https://gs.construction/areas-served/palatine/services/kitchen-remodeling',
            'https://gs.construction/areas-served/palatine',
            'https://gs.construction/projects/palatine-kitchen',
            'https://gs.construction/about',
            'https://gs.construction/permits/palatine',
            'https://gs.construction/costs/kitchen-remodel-cost',
            'https://gs.construction/remodeling/small-bathroom-remodel',
            'https://gs.construction/services/bathroom-remodeling',
            'https://gs.construction/services/kitchen-remodeling',
        ]);

        $pages = $this->source()->pages();

        $this->assertSame(GscFaqBankSource::KIND_ORDER, array_values(array_unique(array_column($pages, 'kind'))));
        $this->assertSame(
            ['https://gs.construction/services/bathroom-remodeling', 'https://gs.construction/services/kitchen-remodeling'],
            array_column(array_values(array_filter($pages, fn (array $p): bool => $p['kind'] === 'service')), 'url'),
            'within a kind the sitemap order is kept'
        );
    }

    public function test_pages_come_from_the_real_sitemap_of_seeded_rows(): void
    {
        // A town is in the sitemap once it has copy of its own (AreaSeoPolicy).
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine', 'intro' => 'Kitchens and bathrooms across Palatine since 2015.']);
        Project::create(['title' => 'Palatine Kitchen', 'slug' => 'palatine-kitchen', 'project_type' => 'kitchen', 'location' => 'Palatine, IL', 'is_published' => true, 'completed_at' => now()]);

        $this->artisan('sitemap:generate', ['--url' => 'https://gs.construction'])->assertSuccessful();
        $this->assertStringContainsString('<loc>https://gs.construction/faq</loc>', (string) file_get_contents(CrawlFiles::sitemapPath()), 'the sitemap itself lists /faq; leaving it out is the source\'s doing');

        $pages = $this->source()->pages();
        $byUrl = array_column($pages, 'kind', 'url');

        $this->assertSame('service', $pages[0]['kind'], 'service pages come first');
        $this->assertSame('service', $byUrl['https://gs.construction/services/kitchen-remodeling']);
        $this->assertSame('town', $byUrl['https://gs.construction/areas-served/palatine']);
        $this->assertSame('project', $byUrl['https://gs.construction/projects/palatine-kitchen']);
        $this->assertSame('page', $byUrl['https://gs.construction/about']);
        $this->assertContains('cost', $byUrl, 'the config-driven /costs/{slug} guides are in the sitemap');
        $this->assertContains('permit', $byUrl, 'the config-driven /permits/{slug} guides are in the sitemap');
        $this->assertArrayNotHasKey('https://gs.construction/faq', $byUrl);
        $this->assertSame([], array_filter(array_keys($byUrl), fn (string $url): bool => str_contains($url, '/photos/')));
    }

    public function test_no_sitemap_means_no_pages(): void
    {
        @unlink(CrawlFiles::sitemapPath());

        $this->assertSame([], $this->source()->pages());
    }

    public function test_text_for_is_null_so_every_page_is_rendered_in_process(): void
    {
        $this->assertNull($this->source()->textFor('https://gs.construction/about'));
        $this->assertSame('US English', $this->source()->language());
        $this->assertSame('GS Construction & Remodeling', $this->source()->businessName());
    }

    public function test_towns_are_this_sites_areas_served_in_name_order(): void
    {
        AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine']);
        AreaServed::create(['city' => 'Lake Zurich', 'slug' => 'lake-zurich']);

        $this->assertSame(['Lake Zurich', 'Palatine'], $this->source()->towns());
    }

    public function test_banned_terms_are_the_compare_companies_their_hosts_and_the_map_pack_leaders_never_our_own_name(): void
    {
        config(['competitors.competitors' => [
            ['slug' => 'airoom', 'name' => 'Airoom', 'website' => 'https://www.airoom.com'],
            ['slug' => 'ohi-remodeling', 'name' => 'OHi (Our Home Improvement)', 'website' => 'https://ohi.com/'],
            ['slug' => 'patrick-a-finn', 'name' => 'Patrick A. Finn, Ltd.'],
        ]]);
        app(MapPackStore::class)->recordCompetitors('scan-1', 'kitchen remodeling palatine', now(), [
            ['place_id' => 'p1', 'name' => 'Normandy Remodeling', 'url' => 'https://normandyremodeling.com', 'rating' => 4.9, 'reviews' => 120, 'claimed' => true, 'categories' => null, 'pack_points' => 3, 'seen_points' => 5, 'best_rank' => 1],
            ['place_id' => 'p2', 'name' => 'GS Construction & Remodeling', 'url' => 'https://gs.construction', 'rating' => 5.0, 'reviews' => 40, 'claimed' => true, 'categories' => null, 'pack_points' => 2, 'seen_points' => 5, 'best_rank' => 2],
            ['place_id' => 'p3', 'name' => 'Never In The Pack', 'url' => null, 'rating' => null, 'reviews' => null, 'claimed' => null, 'categories' => null, 'pack_points' => 0, 'seen_points' => 1, 'best_rank' => null],
        ]);

        $terms = $this->source()->bannedTerms();

        foreach (['Airoom', 'airoom.com', 'OHi (Our Home Improvement)', 'OHi', 'Our Home Improvement', 'ohi.com', 'Patrick A. Finn, Ltd.', 'Patrick A. Finn', 'Normandy Remodeling'] as $term) {
            $this->assertContains($term, $terms, "{$term} is banned");
        }
        $this->assertNotContains('GS Construction & Remodeling', $terms, 'our own listing in the pack never bans our own name');
        $this->assertNotContains('Never In The Pack', $terms, 'a business that never made the 3-pack is not a leader');
        $this->assertSame($terms, array_values(array_unique($terms)));
    }
}
