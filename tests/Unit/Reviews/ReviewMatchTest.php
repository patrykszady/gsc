<?php

namespace Tests\Unit\Reviews;

use SsSystems\Platform\Reviews\ReviewMatch;
use PHPUnit\Framework\TestCase;

/**
 * The shared rule for "is this review on one platform the same review on
 * another?" and for what the merged review becomes — decided once in the
 * kit so ss.systems' duplicate sweep and every site agree on a pair.
 */
class ReviewMatchTest extends TestCase
{
    private function brunkaHouzz(array $overrides = []): array
    {
        return array_merge([
            'platform' => 'houzz',
            'reviewer_name' => 'Barbara Brunka',
            'review_description' => 'They were professional, prompt, and did excellent work on our kitchen renovation project from start to finish. We would definitely hire them again for future work.',
            'review_date' => '2025-01-02',
            'star_rating' => 5,
        ], $overrides);
    }

    private function brunkaGoogle(array $overrides = []): array
    {
        return array_merge([
            'platform' => 'google',
            'reviewer_name' => 'B Brunka',
            'review_description' => 'They were professional, prompt, and did excellent work on our kitchen renovation job from start to finish. We would definitely hire them again for future projects.',
            'review_date' => '2025-01-02',
            'star_rating' => 5,
        ], $overrides);
    }

    public function test_the_real_shaped_brunka_pair_is_certain(): void
    {
        $this->assertSame(ReviewMatch::CERTAIN, ReviewMatch::compare($this->brunkaHouzz(), $this->brunkaGoogle()));
        $this->assertTrue(ReviewMatch::isSameReview($this->brunkaHouzz(), $this->brunkaGoogle()));
    }

    public function test_two_rows_on_the_same_platform_are_never_the_same_review(): void
    {
        $a = $this->brunkaHouzz();
        $b = $this->brunkaGoogle(['platform' => 'houzz']); // same platform as $a, everything else still matches

        $this->assertSame(ReviewMatch::NO_MATCH, ReviewMatch::compare($a, $b));
    }

    public function test_different_surnames_reject_even_with_high_text_overlap(): void
    {
        // Two different customers, same week, generic short reviews that share
        // almost every word — the name gate must run before text is compared.
        $a = ['platform' => 'houzz', 'reviewer_name' => 'Great Smith', 'review_description' => 'Great job, on time, highly recommend this company to everyone we know.', 'review_date' => '2025-03-01', 'star_rating' => 5];
        $b = ['platform' => 'google', 'reviewer_name' => 'Awesome Jones', 'review_description' => 'Great job, on time, highly recommend this company to all our friends.', 'review_date' => '2025-03-01', 'star_rating' => 5];

        $this->assertSame(ReviewMatch::NO_MATCH, ReviewMatch::compare($a, $b));
    }

    public function test_reviews_eight_months_apart_reject_regardless_of_text(): void
    {
        $a = $this->brunkaHouzz(['review_date' => '2025-01-02']);
        $b = $this->brunkaGoogle(['review_date' => '2025-09-05']);

        $this->assertSame(ReviewMatch::NO_MATCH, ReviewMatch::compare($a, $b));
    }

    public function test_a_real_star_rating_disagreement_rejects(): void
    {
        $a = $this->brunkaHouzz();
        $b = $this->brunkaGoogle(['star_rating' => 2]);

        $this->assertSame(ReviewMatch::NO_MATCH, ReviewMatch::compare($a, $b));
    }

    public function test_a_moderately_reworded_pair_is_only_likely(): void
    {
        $a = ['platform' => 'houzz', 'reviewer_name' => 'Barbara Brunka', 'review_description' => 'The crew showed up on time every day and worked hard to finish our bathroom remodel ahead of schedule.', 'review_date' => '2025-04-01', 'star_rating' => 5];
        $b = ['platform' => 'google', 'reviewer_name' => 'B Brunka', 'review_description' => 'The team arrived on time each day and worked hard to finish our bathroom renovation ahead of schedule.', 'review_date' => '2025-04-02', 'star_rating' => 5];

        $this->assertSame(ReviewMatch::LIKELY, ReviewMatch::compare($a, $b));
        $this->assertTrue(ReviewMatch::isSameReview($a, $b));
    }

    public function test_a_pair_under_the_word_floor_never_matches_on_text_alone(): void
    {
        $a = $this->brunkaHouzz(['review_description' => 'Great job, highly recommend them.']);
        $b = $this->brunkaGoogle(['review_description' => 'Great job highly recommend them again']);

        $this->assertSame(ReviewMatch::NO_MATCH, ReviewMatch::compare($a, $b));
    }

    public function test_names_compatible_barbara_b_matches_barbara_brunka(): void
    {
        $this->assertTrue(ReviewMatch::namesCompatible('Barbara B.', 'Barbara Brunka'));
    }

    public function test_names_compatible_two_initials_in_a_row_never_match(): void
    {
        // "B B." carries no name in full on either side, so it matches nobody.
        $this->assertFalse(ReviewMatch::namesCompatible('B B.', 'Barbara Brunka'));
    }

    public function test_names_compatible_a_given_name_alone_has_no_surname_to_verify(): void
    {
        $this->assertFalse(ReviewMatch::namesCompatible('Barbara', 'Barbara Brunka'));
    }

    public function test_names_compatible_different_households_never_match(): void
    {
        $this->assertFalse(ReviewMatch::namesCompatible('Linda M.', 'Todd & Kristen M.'));
    }

    public function test_plan_takes_the_fuller_name_the_longer_text_and_fills_empty_fields(): void
    {
        $keep = [
            'reviewer_name' => 'B Brunka',
            'review_description' => 'Great work overall.',
            'star_rating' => 5,
            'review_date' => '2025-01-02',
            'project_location' => null,
            'project_type' => 'Kitchen Remodel',
            'review_urls' => [
                ['platform' => 'houzz', 'url' => 'https://houzz.com/r/1', 'external_id' => null],
            ],
        ];
        $lose = [
            'reviewer_name' => 'Barbara Brunka',
            'review_description' => 'They did great work overall and we are extremely happy with the finished kitchen.',
            'star_rating' => 5,
            'review_date' => '2025-01-02',
            'project_location' => 'Chicago, IL',
            'project_type' => null,
            'review_urls' => [
                ['platform' => 'google', 'url' => 'https://google.com/r/2', 'external_id' => 'g-123'],
            ],
        ];

        $plan = ReviewMatch::plan($keep, $lose);

        $this->assertSame('Barbara Brunka', $plan['reviewer_name']); // the fuller name, no initial
        $this->assertSame($lose['review_description'], $plan['review_description']); // the longer text
        $this->assertSame('Chicago, IL', $plan['project_location']); // filled from the other side
        $this->assertSame('Kitchen Remodel', $plan['project_type']); // survivor's own value kept, not overwritten
    }

    public function test_plan_only_adds_platform_links_the_survivor_does_not_already_have(): void
    {
        $keep = [
            'reviewer_name' => 'B Brunka',
            'review_description' => 'Great work overall.',
            'review_urls' => [
                ['platform' => 'houzz', 'url' => 'https://houzz.com/r/1', 'external_id' => null],
            ],
        ];
        $lose = [
            'reviewer_name' => 'Barbara Brunka',
            'review_description' => 'They did great work overall and we are extremely happy with the finished kitchen.',
            'review_urls' => [
                ['platform' => 'google', 'url' => 'https://google.com/r/2', 'external_id' => 'g-123'],
                // A duplicate houzz link on the losing side: the survivor already
                // has that platform, so this one is dropped, not added again.
                ['platform' => 'houzz', 'url' => 'https://houzz.com/r/1-dup', 'external_id' => null],
            ],
        ];

        $plan = ReviewMatch::plan($keep, $lose);

        $this->assertCount(1, $plan['add_review_urls']);
        $this->assertSame('google', $plan['add_review_urls'][0]['platform']);
        // The platform's own review id rides along so the next Google sync still
        // recognises this review as already held and never recreates it.
        $this->assertSame('g-123', $plan['add_review_urls'][0]['external_id']);
    }
}
