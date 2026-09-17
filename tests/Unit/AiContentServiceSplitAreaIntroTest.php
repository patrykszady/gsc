<?php

namespace Tests\Unit;

use App\Models\AreaServed;
use App\Services\AiContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiContentService::splitAreaIntro, Gemini faked through Http::fake(). The
 * split must be the same copy in a new order: a reply that loses or invents
 * a large share of it is refused — but a short intro may grow by the joining
 * words three parts need (Barrington Hills, 139 → 181 words, 2026-09-17).
 */
class AiContentServiceSplitAreaIntroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.gemini_api_key' => 'test-key']);
    }

    /**
     * Http::fake() stubs stack and the first registered wins, so a test
     * registers every reply it will consume, in order, once.
     */
    private function fakeReplies(array ...$replies): void
    {
        $seq = Http::sequence();
        foreach ($replies as $parts) {
            $seq->push(['candidates' => [['content' => ['parts' => [['text' => json_encode($parts)]]]]]], 200);
        }
        Http::fake(['generativelanguage.googleapis.com/*' => $seq]);
    }

    private function words(int $n, string $word = 'word'): string
    {
        return trim(str_repeat("{$word} ", $n)).'.';
    }

    public function test_a_short_intro_may_grow_by_the_joining_words_but_not_double(): void
    {
        $area = AreaServed::create(['city' => 'Barrington Hills', 'slug' => 'barrington-hills', 'local_intro' => $this->words(139, 'hill')]);

        $this->fakeReplies(
            ['lead' => $this->words(40), 'history' => $this->words(94), 'potential' => $this->words(92)],   // folds 186 on 139: two words over the ceiling
            ['lead' => $this->words(40), 'history' => $this->words(100), 'potential' => $this->words(100)], // folds 200 on 139: refused
            ['lead' => $this->words(80), 'history' => $this->words(70), 'potential' => $this->words(70)],   // lead too long
            ['lead' => $this->words(40), 'history' => $this->words(200), 'potential' => $this->words(220)], // folds 420 on 400
            ['lead' => $this->words(40), 'history' => $this->words(260), 'potential' => $this->words(260)], // folds 520 on 400
        );
        $ai = app(AiContentService::class);

        // The lead may repeat a fold's sentence, so only the folds are measured.
        // On 139 words the ceiling is max(139 × 1.25, 139 + 45) = 184: 186 is
        // refused here, 184 is accepted in the next test.
        $this->assertNull($ai->splitAreaIntro($area));
        $this->assertStringContainsString('changed the length too much (139 → 186 words)', $ai->getLastError());

        $this->assertNull($ai->splitAreaIntro($area));
        $this->assertStringContainsString('(139 → 200 words)', $ai->getLastError());

        $this->assertNull($ai->splitAreaIntro($area));
        $this->assertSame('Intro split lead too long (80 words)', $ai->getLastError());

        // A long intro keeps the quarter rule: 400 → 420 is fine, 400 → 520 is not.
        $long = AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine', 'local_intro' => $this->words(400, 'plain')]);
        $split = $ai->splitAreaIntro($long);
        $this->assertNotNull($split);
        $this->assertSame(['lead', 'history', 'potential'], array_keys($split));
        $this->assertNull($ai->splitAreaIntro($long));
        $this->assertStringContainsString('(400 → 520 words)', $ai->getLastError());
    }

    public function test_a_short_intro_may_grow_by_up_to_45_words_in_the_folds(): void
    {
        $area = AreaServed::create(['city' => 'Barrington Hills', 'slug' => 'barrington-hills', 'local_intro' => $this->words(139, 'hill')]);
        $this->fakeReplies(['lead' => $this->words(31), 'history' => $this->words(94), 'potential' => $this->words(90)]); // folds 184 = 139 + 45
        $split = app(AiContentService::class)->splitAreaIntro($area);
        $this->assertNotNull($split);
        $this->assertSame(31, str_word_count($split['lead']));
    }

    public function test_a_stub_fold_or_a_missing_key_is_refused(): void
    {
        $area = AreaServed::create(['city' => 'Wheeling', 'slug' => 'wheeling', 'local_intro' => $this->words(200, 'w')]);

        $this->fakeReplies(
            ['lead' => $this->words(30), 'history' => $this->words(10), 'potential' => $this->words(160)],
            ['lead' => $this->words(30), 'history' => $this->words(90)],
        );
        $ai = app(AiContentService::class);

        $this->assertNull($ai->splitAreaIntro($area));
        $this->assertSame('Intro split left one fold nearly empty', $ai->getLastError());

        $this->assertNull($ai->splitAreaIntro($area));
        $this->assertStringContainsString('missing "potential"', $ai->getLastError());
    }
}
