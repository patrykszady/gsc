<?php

namespace Tests\Unit\Reviews;

use Hive\Platform\Reviews\ReviewText;
use PHPUnit\Framework\TestCase;

/** The shared text rules every site's review importers agree on. */
class ReviewTextTest extends TestCase
{
    public function test_a_scraped_angi_review_becomes_what_the_site_stores(): void
    {
        $review = ReviewText::normalizeAngiReview([
            'reviewer_name' => ' Bonnie R. ',
            'review_description' => "Jen is the greatest.\r\nI am in love with my new kitchen.",
            'review_date_raw' => 'January 2015',
            'star_rating' => '5',
        ]);

        $this->assertSame('Bonnie R.', $review['reviewer_name']);
        $this->assertSame("Jen is the greatest.\r\nI am in love with my new kitchen.", $review['review_description']);
        $this->assertSame('2015-01-01', $review['review_date']->toDateString());
        $this->assertSame(5, $review['star_rating']);
    }

    public function test_the_old_pages_iso_dates_still_parse(): void
    {
        $review = ReviewText::normalizeAngiReview(['reviewer_name' => 'Teresa M.', 'review_description' => 'Great remodel.', 'review_date_raw' => '2022-12-21T22:57:16']);

        $this->assertSame('2022-12-21', $review['review_date']->toDateString());
    }

    public function test_a_rating_with_no_write_up_is_not_a_review(): void
    {
        $this->assertNull(ReviewText::normalizeAngiReview(['reviewer_name' => 'don K.', 'review_description' => 'unknown', 'star_rating' => 5]));
        $this->assertNull(ReviewText::normalizeAngiReview(['reviewer_name' => 'don K.', 'review_description' => 'UNKNOWN', 'star_rating' => 5]));
        $this->assertNull(ReviewText::normalizeAngiReview(['reviewer_name' => 'don K.', 'review_description' => '', 'star_rating' => 5]));
        $this->assertNull(ReviewText::normalizeAngiReview(['reviewer_name' => '', 'review_description' => 'Great.', 'star_rating' => 5]));
    }

    public function test_a_bad_date_or_rating_is_dropped_not_the_review(): void
    {
        $review = ReviewText::normalizeAngiReview(['reviewer_name' => 'A. B.', 'review_description' => 'Fine work.', 'review_date_raw' => 'sometime', 'star_rating' => 9]);

        $this->assertNull($review['review_date']);
        $this->assertNull($review['star_rating']);
    }

    public function test_the_same_review_compares_equal_across_sites(): void
    {
        // Entities, curly quotes, punctuation and spacing differ between sites.
        $this->assertSame(ReviewText::comparable('Couldn&#39;t be happier — great work!'), ReviewText::comparable("couldn’t be happier, great   work"));
        $this->assertSame('couldnt be happier great work', ReviewText::comparable('Couldn&amp;#39;t be happier — great work!'));
        $this->assertSame('', ReviewText::comparable(null));
    }

    public function test_entities_decode_until_they_settle(): void
    {
        $this->assertSame("couldn't & wouldn't", ReviewText::decodeEntities('couldn&amp;#39;t &amp;amp; wouldn&#x27;t'));
    }

    public function test_the_payload_key_is_one_per_distinct_review(): void
    {
        $a = ReviewText::normalizeAngiReview(['reviewer_name' => 'Bonnie R.', 'review_description' => 'Jen is the greatest!', 'review_date_raw' => 'January 2015']);
        $b = ReviewText::normalizeAngiReview(['reviewer_name' => 'bonnie r.', 'review_description' => 'Jen is the greatest.', 'review_date_raw' => '2015-01-01']);
        $c = ReviewText::normalizeAngiReview(['reviewer_name' => 'Bonnie R.', 'review_description' => 'Jen is the greatest!', 'review_date_raw' => null]);

        $this->assertSame(ReviewText::payloadKey($a), ReviewText::payloadKey($b));
        $this->assertNotSame(ReviewText::payloadKey($a), ReviewText::payloadKey($c));
    }

    public function test_business_names_compare_by_letters_and_digits_only(): void
    {
        $this->assertSame('jpetersondesign', ReviewText::normalizeName('J. Peterson Design'));
        $this->assertSame('gsconstructionremodeling', ReviewText::normalizeName('GS Construction & Remodeling'));
    }
}
