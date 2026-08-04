<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * A page may advertise at most ONE rated entity.
 *
 * Google shows a review star only when a page has one unambiguous rated item.
 * Area pages were emitting six aggregateRatings — a LocalBusiness claiming 3
 * reviews beside five Product nodes claiming 32, 17, 10, 70 and 70, every one
 * rated exactly "5", with @ids pointing at other URLs. Google has no primary
 * entity to attach a star to and discards all of them, so the markup cost
 * ~54KB a page and bought nothing. Worse, the contradiction reads as
 * manufactured to anyone reviewing it.
 *
 * The four competitors that outrank this site on these queries carry LESS
 * markup — several have no rating at all — so this was never the gap.
 */
class SchemaSingleRatingTest extends TestCase
{
    /** @return array<int, array<string, mixed>> every JSON-LD node on the page */
    private function nodes(string $uri): array
    {
        $html = $this->get($uri)->assertOk()->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $out = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded['@graph'] ?? [$decoded] as $node) {
                if (is_array($node)) {
                    $out[] = $node;
                }
            }
        }

        return $out;
    }

    private function ratedCount(string $uri): int
    {
        return count(array_filter($this->nodes($uri), fn ($n) => ! empty($n['aggregateRating'])));
    }

    /**
     * Built, not borrowed: the test database carries no areas, and a test
     * that quietly skips when the seed lacks a row proves nothing.
     */
    private function area(): AreaServed
    {
        return AreaServed::firstOrCreate(
            ['slug' => 'schema-test-city'],
            ['city' => 'Schema Test City'],
        );
    }

    public function test_an_area_page_advertises_one_rating_at_most(): void
    {
        $this->assertLessThanOrEqual(1, $this->ratedCount('/areas-served/' . $this->area()->slug));
    }

    public function test_the_homepage_advertises_one_rating_at_most(): void
    {
        $this->assertLessThanOrEqual(1, $this->ratedCount('/'));
    }

    public function test_a_service_page_advertises_one_rating_at_most(): void
    {
        $this->assertLessThanOrEqual(1, $this->ratedCount('/services/kitchen-remodeling'));
    }

    public function test_no_two_rated_nodes_disagree_about_the_review_count(): void
    {
        $counts = [];
        foreach ($this->nodes('/areas-served/' . $this->area()->slug) as $node) {
            if ($rating = $node['aggregateRating'] ?? null) {
                $counts[] = $rating['reviewCount'] ?? $rating['ratingCount'] ?? null;
            }
        }

        $this->assertLessThanOrEqual(
            1,
            count(array_unique(array_filter($counts))),
            'two rated nodes claim different review counts for the same business',
        );
    }

    public function test_no_node_claims_an_address_in_a_town_we_do_not_occupy(): void
    {
        // GS is a pure service-area business — Google's Places record returns
        // no address and no coordinates at all. The per-city LocalBusiness
        // node used to set addressLocality to the page's city and a local
        // postal code, publishing a street presence in each of 66 towns.
        // areaServed conveys where the work happens; address must stay the one
        // real place the business operates from.
        $area = $this->area();
        $hq = (string) config('brand.city');

        foreach ($this->nodes('/areas-served/' . $area->slug) as $node) {
            $address = $node['address'] ?? null;
            if (! is_array($address) || ! isset($address['addressLocality'])) {
                continue;
            }

            $this->assertSame(
                $hq,
                $address['addressLocality'],
                sprintf('%s claims an address in %s', $node['@type'] ?? '?', $address['addressLocality']),
            );
        }
    }
}
