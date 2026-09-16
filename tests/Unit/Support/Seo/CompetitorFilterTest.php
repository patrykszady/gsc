<?php

namespace Tests\Unit\Support\Seo;

use App\Support\Seo\CompetitorFilter;
use Tests\TestCase;

/**
 * The one place every SEO intel source decides "is this a competitor" —
 * see App\Support\Seo\CompetitorFilter's own docblock for the three-check
 * order this locks in.
 */
class CompetitorFilterTest extends TestCase
{
    private const KNOWN_AGGREGATORS = [
        'houzz.com', 'yelp.com', 'angi.com', 'bbb.org', 'facebook.com', 'mapquest.com',
        'homeadvisor.com', 'thumbtack.com', 'homeguide.com', 'nextdoor.com', 'buildzoom.com',
        'thebluebook.com', 'procore.com',
    ];

    public function test_is_aggregator_true_for_directories_and_their_www_and_subdomain_forms(): void
    {
        foreach (self::KNOWN_AGGREGATORS as $host) {
            $this->assertTrue(CompetitorFilter::isAggregator($host), "{$host} should be an aggregator");
            $this->assertTrue(CompetitorFilter::isAggregator('www.'.$host), "www.{$host} should be an aggregator");
            $this->assertTrue(CompetitorFilter::isAggregator('reviews.'.$host), "a subdomain of {$host} should be an aggregator");
        }
    }

    public function test_is_aggregator_false_for_real_local_competitors_and_our_own_domain(): void
    {
        foreach (['airoom.com', '123remodeling.com', 'masterskitchenbath.com', 'gs.construction'] as $host) {
            $this->assertFalse(CompetitorFilter::isAggregator($host), "{$host} should not be an aggregator");
        }
    }

    public function test_is_aggregator_is_suffix_safe_not_a_naive_substring_match(): void
    {
        // The old SeoDiscoverCompetitors list had a bare-word 'angi' exclusion
        // that would substring-match any host merely containing those four
        // letters. The shared config list drops it in favor of 'angi.com',
        // so a real remodeler whose name happens to contain "angi" must pass.
        $this->assertFalse(CompetitorFilter::isAggregator('changingspacesremodeling.com'));
    }

    public function test_is_known_local_matches_a_configured_website_host_exactly_and_as_a_subdomain(): void
    {
        config(['competitors.competitors' => [
            ['slug' => 'acme', 'name' => 'Acme Remodeling', 'website' => 'https://acmeremodeling.com/'],
        ]]);

        $this->assertTrue(CompetitorFilter::isKnownLocal('acmeremodeling.com'));
        $this->assertTrue(CompetitorFilter::isKnownLocal('www.acmeremodeling.com'));
    }

    public function test_is_known_local_false_for_an_unrelated_host_and_a_spoofing_attempt(): void
    {
        config(['competitors.competitors' => [
            ['slug' => 'acme', 'name' => 'Acme Remodeling', 'website' => 'https://acmeremodeling.com/'],
        ]]);

        $this->assertFalse(CompetitorFilter::isKnownLocal('otherremodeling.com'));
        // Suffix/exact matching, not substring: a spoofed host that merely
        // contains the known domain must not pass.
        $this->assertFalse(CompetitorFilter::isKnownLocal('acmeremodeling.com.evil.example'));
    }

    public function test_is_giant_uses_the_db_derived_thresholds(): void
    {
        // airoom-like real local competitor (largest observed local company).
        $this->assertFalse(CompetitorFilter::isGiant(950.0, 7896.0));
        // thebluebook-like aggregator (smallest observed non-local subject).
        $this->assertTrue(CompetitorFilter::isGiant(173349.0, 225996.0));
        // procore-like B2B SaaS platform.
        $this->assertTrue(CompetitorFilter::isGiant(319437.0, 2036642.0));
        // Missing metrics never count as giant — can't judge, don't drop.
        $this->assertFalse(CompetitorFilter::isGiant(null, null));
    }

    public function test_is_competitor_the_exclusion_list_wins_over_metrics(): void
    {
        // On the exclusion list AND with metrics small enough to pass
        // isGiant() — the list still wins; a directory is never a competitor.
        $this->assertFalse(CompetitorFilter::isCompetitor('houzz.com', 100, 500));
    }

    public function test_is_competitor_known_local_overrides_giant_metrics(): void
    {
        config(['competitors.competitors' => [
            ['slug' => 'acme', 'name' => 'Acme Remodeling', 'website' => 'https://acmeremodeling.com/'],
        ]]);

        // A curated competitor is never dropped by a metrics fluke, even one
        // that would otherwise read as a giant.
        $this->assertTrue(CompetitorFilter::isCompetitor('acmeremodeling.com', 500000, 900000));
    }

    public function test_is_competitor_true_for_an_ordinary_real_competitor(): void
    {
        $this->assertTrue(CompetitorFilter::isCompetitor('brandnewrival.com', 500, 2000));
        $this->assertTrue(CompetitorFilter::isCompetitor('brandnewrival.com'), 'no metrics available is never a reason to drop a non-excluded host');
    }

    public function test_keep_normalizes_dedupes_and_filters_a_plain_host_list(): void
    {
        $kept = CompetitorFilter::keep(['www.brandnewrival.com', 'brandnewrival.com', 'HOUZZ.com', 'RealLocalCo.com']);

        $this->assertSame(['brandnewrival.com', 'reallocalco.com'], $kept);
    }
}
