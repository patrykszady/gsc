<?php

namespace App\Support;

/**
 * Sentence-level helpers for a town's copy: the lead a reader sees before
 * the fold, and what goes behind it.
 */
class TownCopy
{
    /**
     * Split prose into a short lead and the rest, on sentence boundaries.
     * The lead takes whole sentences until it holds at least $minWords, but
     * never more than the first sentence when that alone passes $maxWords.
     *
     * @return array{lead: string, rest: string}
     */
    public static function leadAndRest(?string $text, int $minWords = 30, int $maxWords = 60): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return ['lead' => '', 'rest' => ''];
        }

        // Keep paragraph breaks in the remainder: split paragraphs first, then
        // sentences inside the first paragraph only.
        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];
        $first = trim(array_shift($paragraphs));
        $sentences = self::sentences($first);

        $lead = [];
        $words = 0;
        while ($sentences !== []) {
            $next = $sentences[0];
            $nextWords = str_word_count($next);
            if ($lead !== [] && ($words >= $minWords || $words + $nextWords > $maxWords)) {
                break;
            }
            $lead[] = array_shift($sentences);
            $words += $nextWords;
        }

        $restFirst = trim(implode(' ', $sentences));
        $rest = trim(implode("\n\n", array_filter(array_merge([$restFirst], array_map('trim', $paragraphs)))));

        return ['lead' => trim(implode(' ', $lead)), 'rest' => $rest];
    }

    /** @return list<string> */
    public static function sentences(string $paragraph): array
    {
        $paragraph = trim(preg_replace('/\s+/', ' ', $paragraph) ?? $paragraph);
        if ($paragraph === '') {
            return [];
        }

        // A sentence ends at . ! or ? followed by a capital, a quote or a
        // bracket. A lone initial ("U.S. Census") and the street and title
        // abbreviations below do not end one.
        // The lookbehinds sit after the period, so each abbreviation carries it.
        $abbr = '(?<!\\b[A-Z]\\.)(?<!\\bSt\\.|\\bMt\\.|\\bDr\\.|\\bRd\\.|\\bMr\\.|\\bMs\\.|\\bNo\\.|\\bvs\\.|\\bFt\\.)(?<!\\bAve\\.|\\bMrs\\.|\\bLtd\\.|\\bInc\\.|\\bRte\\.)(?<!\\bBlvd\\.)';
        $parts = preg_split('/'.$abbr.'(?<=[.!?])\\s+(?=[\\p{Lu}"“(])/u', $paragraph) ?: [$paragraph];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    public static function words(?string $text): int
    {
        return str_word_count(trim((string) $text));
    }
}
