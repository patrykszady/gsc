<?php

namespace Tests\Feature\Seo;

use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Faq\Bank\FaqKeys;
use Tests\TestCase;

/**
 * Where the FAQ bank (kit 0.24.0) is published on this site: /geo/answers.json
 * (ConfigFaqCatalog → FaqBankCatalog::merge, hand-written first, every
 * bank answer carrying the page it came from) and llms-full.txt's
 * "Questions and answers" section (resources/views/vendor/geo/
 * llms-full-txt.blade.php, grouped by page, capped at 2,000).
 */
class FaqBankFeedsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** @param  list<array{0: string, 1: string}>  $pairs */
    private function bank(string $url, string $kind, array $pairs): void
    {
        app(FaqBankStore::class)->replacePage($url, $kind, sha1($url), array_map(
            fn (array $pair): array => ['q' => $pair[0], 'a' => $pair[1], 'q_key' => FaqKeys::question($pair[0], []), 'a_key' => FaqKeys::answer($pair[1], [])],
            $pairs,
        ));
    }

    public function test_geo_answers_json_lists_the_hand_written_answers_first_then_the_bank_with_the_page_each_came_from(): void
    {
        $this->bank('https://gs.construction/services/kitchen-remodeling', 'service', [
            ['How long are we on site for a kitchen?', 'Most of our kitchens take 8 to 12 weeks on site.'],
            // The hand-written list asks exactly this (config/geo-answers.php):
            // the bank's version is never published.
            ['How long does a kitchen remodel take?', 'A bank answer to a question the hand-written list already answers.'],
        ]);
        $this->bank('https://gs.construction/areas-served/palatine', 'town', [
            ['Do you pull permits in Palatine?', 'Yes — we pull and manage the Palatine permit ourselves.'],
        ]);

        $items = $this->getJson('/geo/answers.json')->assertOk()->json('mainEntity');
        $handWritten = array_values((array) config('geo-answers.answers'));
        $count = count($handWritten);

        $this->assertContains('How long does a kitchen remodel take?', array_column($handWritten, 'q'), 'the collision this test relies on');
        $this->assertCount($count + 2, $items);
        foreach ($handWritten as $i => $answer) {
            $this->assertSame($answer['q'], $items[$i]['name'], "hand-written answer {$i} keeps its place");
            $this->assertSame($answer['a'], $items[$i]['acceptedAnswer']['text']);
            $this->assertSame($answer['topics'] ?? [], $items[$i]['topics']);
            $this->assertArrayNotHasKey('url', $items[$i], 'a hand-written answer has no source page');
        }

        $this->assertSame('How long are we on site for a kitchen?', $items[$count]['name']);
        $this->assertSame('https://gs.construction/services/kitchen-remodeling', $items[$count]['url']);
        $this->assertSame(['service'], $items[$count]['topics']);
        $this->assertSame('Most of our kitchens take 8 to 12 weeks on site.', $items[$count]['acceptedAnswer']['text']);

        $this->assertSame('Do you pull permits in Palatine?', $items[$count + 1]['name']);
        $this->assertSame('https://gs.construction/areas-served/palatine', $items[$count + 1]['url']);
        $this->assertSame(['town'], $items[$count + 1]['topics']);

        $kitchen = array_values(array_filter($items, fn (array $item): bool => $item['name'] === 'How long does a kitchen remodel take?'));
        $this->assertCount(1, $kitchen, 'one answer per question');
        $this->assertArrayNotHasKey('url', $kitchen[0], 'and it is the hand-written one');
        $this->assertSame('FAQPage', $this->getJson('/geo/answers.json')->json('@type'));
        $this->assertSame(['English', 'Polish'], $this->getJson('/geo/answers.json')->json('publisher.knowsLanguage'), 'the publisher extras are untouched');
    }

    public function test_geo_answers_json_is_the_hand_written_list_alone_while_the_bank_is_empty(): void
    {
        $items = $this->getJson('/geo/answers.json')->assertOk()->json('mainEntity');

        $this->assertCount(count((array) config('geo-answers.answers')), $items);
        $this->assertSame([], array_filter($items, fn (array $item): bool => isset($item['url'])));
    }

    public function test_llms_full_lists_the_bank_grouped_by_page_after_everything_else(): void
    {
        $this->bank('https://gs.construction/services/kitchen-remodeling', 'service', [
            ['How long does a kitchen remodel take?', 'Most of our kitchens take 8 to 12 weeks on site.'],
            ['Do you handle kitchen & bath permits?', 'Yes — we pull and manage the permit, and we handle plumbing & electrical ourselves.'],
        ]);
        $this->bank('https://gs.construction/areas-served/palatine', 'town', [
            ['Do you pull permits in Palatine?', 'Yes — we pull and manage the Palatine permit ourselves.'],
        ]);

        $llms = $this->get('/llms-full.txt')->assertOk()->getContent();

        $this->assertStringContainsString("## Metadata\n", $llms);
        $section = substr($llms, (int) strpos($llms, '## Questions and answers'));
        $this->assertGreaterThan(strpos($llms, '## Metadata'), strpos($llms, '## Questions and answers'), 'the bank is the last section');
        $this->assertStringContainsString('/geo/answers.json', $section);

        $this->assertSame(
            "### https://gs.construction/services/kitchen-remodeling\n"
            ."Q: How long does a kitchen remodel take?\n"
            ."A: Most of our kitchens take 8 to 12 weeks on site.\n"
            ."Q: Do you handle kitchen & bath permits?\n"
            ."A: Yes — we pull and manage the permit, and we handle plumbing & electrical ourselves.\n"
            ."\n"
            ."### https://gs.construction/areas-served/palatine\n"
            ."Q: Do you pull permits in Palatine?\n"
            ."A: Yes — we pull and manage the Palatine permit ourselves.\n",
            substr($section, (int) strpos($section, '### https://')),
            'one sub-heading per page, each Q and A under it, printed as text (no &amp;)'
        );
    }

    public function test_llms_full_has_no_questions_section_while_the_bank_is_empty(): void
    {
        $llms = $this->get('/llms-full.txt')->assertOk()->getContent();

        $this->assertStringContainsString('## Frequently asked questions', $llms, 'the hand-written section is untouched');
        $this->assertStringNotContainsString('## Questions and answers', $llms);
        $lines = explode("\n", trim($llms));
        $this->assertStringStartsWith('- Generated At: ', end($lines), 'nothing follows the metadata while the bank is empty');
    }

    public function test_llms_full_caps_the_bank_at_two_thousand_answers(): void
    {
        $siteId = Site::where('slug', 'gsc')->firstOrFail()->id;
        $url = 'https://gs.construction/services/kitchen-remodeling';
        $rows = [];
        for ($i = 0; $i < 2010; $i++) {
            $rows[] = [
                'site_id' => $siteId, 'url_hash' => sha1($url), 'source_url' => $url, 'kind' => 'service',
                'question' => "Question number {$i}?", 'answer' => "Answer number {$i}.", 'q_key' => "question number {$i}", 'a_key' => "answer number {$i}",
                'position' => $i, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('geo_faq_bank')->insert($chunk);
        }

        $llms = $this->get('/llms-full.txt')->assertOk()->getContent();

        $this->assertSame(2000, substr_count($llms, "\nQ: Question number "));
        $this->assertStringContainsString("\nQ: Question number 1999?\n", $llms);
        $this->assertStringNotContainsString('Question number 2000?', $llms);
    }
}
