<?php

namespace Tests\Unit;

use App\Support\TownCopy;
use PHPUnit\Framework\TestCase;

class TownCopyTest extends TestCase
{
    public function test_sentences_split_on_terminal_punctuation_but_not_on_abbreviations(): void
    {
        $this->assertSame(
            ['The village was platted in 1855.', 'Homes near St. Charles Rd. date from the 1920s!', 'Is that old?', '"Yes," said the U.S. census.'],
            TownCopy::sentences('The village was platted in 1855. Homes near St. Charles Rd. date from the 1920s! Is that old? "Yes," said the U.S. census.'),
        );
        $this->assertSame([], TownCopy::sentences("  \n "));
    }

    public function test_the_lead_takes_whole_sentences_up_to_the_word_budget_and_the_rest_keeps_its_paragraphs(): void
    {
        $s1 = 'Palatine maps its growth rings with clarity, reflecting a century and a half of expansion across the village.'; // 17 words
        $s2 = 'The core was platted in 1855 around the Metra station and still keeps some of its earliest architecture today.'; // 19 words
        $s3 = 'The postwar boom built most of it.';
        $p2 = 'Later developments add more variety.';

        ['lead' => $lead, 'rest' => $rest] = TownCopy::leadAndRest("{$s1} {$s2} {$s3}\n\n{$p2}");
        $this->assertSame("{$s1} {$s2}", $lead, 'two sentences reach the 30-word minimum; the third stays behind');
        $this->assertSame("{$s3}\n\n{$p2}", $rest);

        // One long first sentence over the maximum still leads on its own.
        $long = str_repeat('word ', 70).'end.';
        ['lead' => $lead, 'rest' => $rest] = TownCopy::leadAndRest("{$long} Short second.");
        $this->assertSame(trim($long), $lead);
        $this->assertSame('Short second.', $rest);

        $this->assertSame(['lead' => '', 'rest' => ''], TownCopy::leadAndRest(null));
    }
}
