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
}
