<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use App\Support\Seo\CrawlFiles;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\TextGenerator;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Faq\Contracts\FaqCatalog;
use Tests\TestCase;

/**
 * `seo:faq-bank` end to end on this site: a fake TextGenerator bound into
 * the container (the kit's seam, no HTTP behind it — the same split
 * BingCoverageCommandTest uses), run against the REAL GscFaqBankSource,
 * FaqBankStore and the in-process render of one of this site's own
 * pages. The prompt the fake receives is what proves the extractor read
 * the page's `<main>` and not the navbar or the footer.
 */
class FaqBankCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param  list<string>  $urls */
    private function writeSitemap(array $urls): void
    {
        $path = CrawlFiles::sitemapPath();
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, '<?xml version="1.0"?><urlset>'
            .implode('', array_map(fn (string $u): string => "<url><loc>{$u}</loc></url>", $urls))
            .'</urlset>');
    }

    private function bindFake(?string $answer, bool $configured = true): RecordingTextGenerator
    {
        $fake = new RecordingTextGenerator($answer, $configured);
        $this->app->instance(TextGenerator::class, $fake);

        return $fake;
    }

    public function test_it_renders_a_real_page_in_process_prompts_with_its_main_text_and_stores_the_answers(): void
    {
        $this->writeSitemap(['https://gs.construction/about']);
        $fake = $this->bindFake(json_encode(['items' => [
            ['q' => 'Who runs GS Construction?', 'a' => 'We are a father-son team, Gregory and Patryk, with over 40 years of combined experience, and one of us is on your job personally.'],
            ['q' => 'Where does GS Construction work?', 'a' => 'We have roots throughout Chicagoland, the Northwest Suburbs and the North Shore, and we treat every home as if it were our own.'],
            ['q' => 'How many projects has GS Construction completed?', 'a' => 'We have completed about 4372 projects, far more than the page says.'],
        ]]));

        $this->artisan('seo:faq-bank', ['--pause' => 0])
            ->expectsOutputToContain('FAQ bank for GS Construction & Remodeling via Fake fake-1: 1 pages written, 2 answers kept')
            ->expectsOutputToContain('Rejected: number_not_on_page 1')
            ->expectsOutputToContain('The bank holds 2 answers from 1 pages.')
            ->assertExitCode(0);

        $this->assertCount(1, $fake->prompts, 'one generation for the one page');
        $prompt = $fake->prompts[0];
        $this->assertStringContainsString('A Family Business Built on Trust', $prompt, 'the page\'s own main text reached the prompt');
        $this->assertStringContainsString('father-son duo with over 40 years of combined experience', $prompt);
        $this->assertStringNotContainsString('Open main menu', $prompt, 'the navbar is not page text');
        $this->assertStringNotContainsString('Review Profiles', $prompt, 'the footer is not page text');
        $this->assertStringContainsString('about GS Construction & Remodeling', $prompt, 'the business name from brand config');
        $this->assertStringContainsString('one page page of their website (https://gs.construction/about)', $prompt);

        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $answers = DB::table('geo_faq_bank')->orderBy('position')->get();
        $this->assertCount(2, $answers);
        $this->assertSame('Who runs GS Construction?', $answers[0]->question);
        $this->assertSame('https://gs.construction/about', $answers[0]->source_url);
        $this->assertSame('page', $answers[0]->kind);
        $this->assertSame($gsc->id, (int) $answers[0]->site_id);
        $this->assertSame('who runs gs construction', $answers[0]->q_key);

        $page = DB::table('geo_faq_bank_pages')->first();
        $this->assertSame('https://gs.construction/about', $page->url);
        $this->assertSame(2, (int) $page->answers);
        $this->assertNotNull($page->source_hash);
        $this->assertNotNull($page->generated_at);
        $this->assertNull($page->last_error);
        $this->assertSame($gsc->id, (int) $page->site_id);

        // The page did not change: the next run costs no model call.
        $this->artisan('seo:faq-bank', ['--pause' => 0])
            ->expectsOutputToContain('0 pages written, 0 answers kept, 0 duplicates dropped, 1 unchanged')
            ->assertExitCode(0);
        $this->assertCount(1, $fake->prompts);
        $this->assertSame(2, DB::table('geo_faq_bank')->count());

        // --force rebuilds it; --dry-run never writes.
        $this->artisan('seo:faq-bank', ['--pause' => 0, '--force' => true])->assertExitCode(0);
        $this->assertCount(2, $fake->prompts);
        $this->artisan('seo:faq-bank', ['--pause' => 0, '--force' => true, '--dry-run' => true])
            ->expectsOutputToContain('Dry run — nothing generated, nothing written. 1 pages would be built:')
            ->assertExitCode(0);
        $this->assertCount(2, $fake->prompts);
    }

    public function test_a_page_that_is_not_in_the_sitemap_any_more_loses_its_answers(): void
    {
        $this->writeSitemap(['https://gs.construction/about']);
        $this->bindFake(json_encode(['items' => [['q' => 'Who runs GS Construction?', 'a' => 'We are a father-son team, Gregory and Patryk, and one of us is on your job personally.']]]));
        $this->artisan('seo:faq-bank', ['--pause' => 0])->assertExitCode(0);
        $this->assertSame(1, DB::table('geo_faq_bank')->count());

        $this->writeSitemap(['https://gs.construction/how-to-choose-a-remodeling-contractor']);
        $this->bindFake(json_encode(['items' => []]));
        $this->artisan('seo:faq-bank', ['--pause' => 0])->assertExitCode(0);

        $this->assertSame(0, DB::table('geo_faq_bank')->count());
        $this->assertSame(['https://gs.construction/how-to-choose-a-remodeling-contractor'], DB::table('geo_faq_bank_pages')->pluck('url')->all());
    }

    public function test_without_a_provider_key_it_skips_plainly_and_writes_nothing(): void
    {
        $this->writeSitemap(['https://gs.construction/about']);

        $this->artisan('seo:faq-bank')
            ->expectsOutputToContain('No AI provider is configured for GS Construction & Remodeling — skipping.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('geo_faq_bank_pages')->count());
    }

    public function test_another_tenant_never_sees_this_sites_bank(): void
    {
        $this->writeSitemap(['https://gs.construction/about']);
        $this->bindFake(json_encode(['items' => [['q' => 'Who runs GS Construction?', 'a' => 'We are a father-son team, Gregory and Patryk, and one of us is on your job personally.']]]));
        $this->artisan('seo:faq-bank', ['--pause' => 0])->assertExitCode(0);
        $this->assertSame(1, app(FaqBankStore::class)->summary()['answers']);
        $this->assertContains('https://gs.construction/about', array_column(app(FaqCatalog::class)->items(), 'url'));

        $other = Site::query()->where('slug', 'jpeterson')->firstOrFail();
        $other->forceFill(['is_active' => true])->save();
        Site::forgetActive();

        Tenancy::for($other->fresh(), function (): void {
            $store = app(FaqBankStore::class);

            $this->assertSame([], $store->all());
            $this->assertSame(['answers' => 0, 'pages' => 0, 'last_generated_at' => null], $store->summary());
            $this->assertNull($store->pageState('https://gs.construction/about'));
            $this->assertSame([], $store->takenKeys('https://nowhere.test/'));
            $this->assertSame([], array_filter(app(FaqCatalog::class)->items(), fn (array $item): bool => isset($item['url'])), 'the studio\'s feed carries none of gsc\'s bank');

            // Without a sitemap of its own (another test in this process
            // may have written one) there is nothing to mine, and the run
            // forgets nothing of gsc's.
            @unlink(CrawlFiles::sitemapPath());
            $this->artisan('seo:faq-bank', ['--pause' => 0])
                ->expectsOutputToContain('The bank holds 0 answers from 0 pages.')
                ->assertExitCode(0);
        });

        $this->assertSame(1, DB::table('geo_faq_bank')->count(), 'gsc\'s row survived the other tenant\'s run');
    }
}

/**
 * The kit's TextGenerator seam, hand-faked: records every prompt, answers
 * with one fixed text (null = the provider failed).
 */
final class RecordingTextGenerator implements TextGenerator
{
    /** @var list<string> */
    public array $prompts = [];

    public function __construct(private readonly ?string $answer, private readonly bool $configured = true) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function generate(string $prompt, int $maxOutputTokens = 2000): ?string
    {
        $this->prompts[] = $prompt;

        return $this->answer;
    }

    public function lastError(): ?string
    {
        return $this->answer === null ? 'Fake fake-1 answered nothing.' : null;
    }

    public function label(): string
    {
        return 'Fake fake-1';
    }
}
