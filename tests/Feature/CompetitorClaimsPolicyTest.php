<?php

namespace Tests\Feature;

use App\Livewire\CompareCompetitorPage;
use ReflectionClass;
use Tests\TestCase;

/**
 * Publishing rules for /compare/*, set by counsel after the 2026-08
 * cease-and-desist from a named competitor.
 *
 * The pages previously filled the competitor's column with general industry
 * cautions — "some firms subcontract the trades and add a labor markup on top",
 * "larger firms often hand you to a different coordinator at each phase". Read
 * in the abstract those are hedged; rendered in a column headed with a named
 * company they read as assertions about that company, which is what drew the
 * letter.
 *
 * These tests encode what counsel asked for, so it cannot quietly come back:
 *  - the flagged rows carry a claim ONLY where a verbatim citation backs it;
 *  - the section is framed as our advantage, not as a verdict on them;
 *  - each page's paragraph is about GS Construction, not a description of them.
 */
class CompetitorClaimsPolicyTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function competitors(): array
    {
        return (array) config('competitors.competitors', []);
    }

    /** @return array<int, array<string, string>> */
    private function criteriaFor(array $competitor): array
    {
        $page = new CompareCompetitorPage;
        $method = (new ReflectionClass($page))->getMethod('buildCriteria');
        $method->setAccessible(true);

        return $method->invoke($page, $competitor);
    }

    public function test_flagged_rows_never_assert_anything_without_a_citation(): void
    {
        $gated = (array) config('competitors.requires_citation', []);
        $this->assertNotEmpty($gated, 'the citation policy disappeared from config');

        foreach ($this->competitors() as $competitor) {
            foreach ($this->criteriaFor($competitor) as $row) {
                if (! in_array($row['key'], $gated, true)) {
                    continue;
                }

                if (! empty($row['them_source'])) {
                    continue;   // backed by a verbatim quote — allowed
                }

                $this->assertStringStartsWith(
                    'Varies',
                    $row['them'],
                    sprintf(
                        '%s: "%s" states something about a named company with no citation',
                        $competitor['slug'] ?? '?',
                        $row['label'],
                    ),
                );
            }
        }
    }

    public function test_no_page_carries_the_retired_industry_cautions(): void
    {
        // The exact phrasings counsel objected to.
        $banned = [
            'Some firms subcontract',
            'Larger firms often',
            'Many firms steer',
            'Ask whether you get a real-time',
            'Ask who actually performs',
        ];

        foreach ($this->competitors() as $competitor) {
            $rendered = implode(' ', array_column($this->criteriaFor($competitor), 'them'))
                . ' ' . (string) ($competitor['comparison_note'] ?? '');

            foreach ($banned as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $rendered,
                    sprintf('%s reintroduced: "%s"', $competitor['slug'] ?? '?', $phrase),
                );
            }
        }
    }

    public function test_public_reviews_points_at_their_reviews_rather_than_characterising_them(): void
    {
        $row = collect((array) config('competitors.criteria', []))
            ->firstWhere('key', 'public_reviews');

        $this->assertSame(
            'Varies — we advise reviewing the company\'s public reviews.',
            $row['them_default'] ?? null,
        );
    }

    public function test_every_comparison_note_is_about_us(): void
    {
        foreach ($this->competitors() as $competitor) {
            $note = trim((string) ($competitor['comparison_note'] ?? ''));
            if ($note === '') {
                continue;
            }

            $firstSentence = preg_split('/(?<=\.)\s+/', $note)[0];

            $this->assertTrue(
                str_contains($firstSentence, 'GS Construction')
                    || str_starts_with($firstSentence, 'The most reliable'),
                sprintf(
                    '%s opens by describing the competitor: "%s"',
                    $competitor['slug'] ?? '?',
                    $firstSentence,
                ),
            );
        }
    }

    public function test_the_section_is_framed_as_our_advantage(): void
    {
        $html = $this->get('/compare/4ever-remodeling')->assertOk()->getContent();

        $this->assertStringContainsString('The GS Construction Advantage', $html);
        $this->assertStringNotContainsString('How GS Construction compares to', $html);
    }

    public function test_the_criteria_section_presents_only_us(): void
    {
        $html = $this->get('/compare/4ever-remodeling')->assertOk()->getContent();

        // The criteria now render FAQ-style (dt/dd under a single brand
        // heading), not as a comparison grid. A <thead> reappearing here is the
        // competitor column trying to come back.
        $this->assertStringNotContainsString('<thead', $html, 'a comparison table has reappeared');
        $this->assertStringContainsString('Pricing transparency', $html, 'the criteria rows went missing');
        $this->assertStringContainsString('GS Construction &amp; Remodeling', $html);
    }

    /**
     * The pages must not advertise a comparison they no longer contain.
     *
     * The intro promised "a factual side-by-side so you can compare options"
     * and the meta description promised one "on service area, project types,
     * communication and reviews" — written when the table still had a column
     * for the other company. Left in place they oversell the page to a reader
     * and describe us reporting on a competitor we no longer report on.
     */
    public function test_no_compare_page_promises_a_side_by_side(): void
    {
        foreach (['/compare', '/compare/4ever-remodeling', '/how-to-choose-a-remodeling-contractor'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();

            foreach (['factual side-by-side', 'factual, side-by-side'] as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $html,
                    "{$uri} still promises a side-by-side comparison",
                );
            }
        }
    }

    /**
     * Removing the column from the table was not enough on its own.
     *
     * Livewire serialises public properties into the wire:snapshot embedded in
     * the page, so every `them` value and every source quote and URL was still
     * shipped to the browser and readable in view-source with nothing rendering
     * it. Not published is not the same as not displayed.
     */
    public function test_no_statement_about_a_competitor_is_published_anywhere_in_the_page(): void
    {
        foreach ($this->competitors() as $competitor) {
            $slug = (string) ($competitor['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $claims = array_values((array) ($competitor['them'] ?? []));
            $quotes = array_column((array) ($competitor['them_sources'] ?? []), 'quote');
            if ($claims === [] && $quotes === []) {
                continue;
            }

            $html = $this->get("/compare/{$slug}")->assertOk()->getContent();

            foreach (array_merge($claims, $quotes) as $statement) {
                $statement = trim((string) $statement);
                if ($statement === '') {
                    continue;
                }

                $this->assertStringNotContainsString(
                    // Compare on a distinctive head of the string: the full text
                    // may be HTML-escaped in the snapshot.
                    mb_substr($statement, 0, 40),
                    $html,
                    sprintf('%s still publishes a statement about the competitor', $slug),
                );
            }
        }
    }

    /**
     * These 26 pages are a large share of the site's crawlable surface and used
     * to link almost nowhere but /contact. Every criterion now carries an
     * internal link, so a row losing one is a silent loss of internal linking.
     */
    public function test_every_criterion_links_somewhere_useful(): void
    {
        $html = $this->get('/compare/4ever-remodeling')->assertOk()->getContent();

        $criteria = (array) config('competitors.criteria', []);
        $this->assertNotEmpty($criteria);

        foreach ($criteria as $row) {
            $href = $row['link']['href'] ?? null;
            $text = $row['link']['text'] ?? null;

            $this->assertNotNull($href, ($row['key'] ?? '?').' has no internal link');
            $this->assertStringContainsString('href="'.$href.'"', $html);
            // Anchor text has to name the destination — "learn more" carries no
            // weight for the page it points at.
            $this->assertStringContainsString(e($text), $html);
            $this->assertNotSame('Learn more', $text);
        }
    }
}
