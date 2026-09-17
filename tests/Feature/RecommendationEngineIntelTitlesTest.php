<?php

namespace Tests\Feature;

use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\RecommendationEngine;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/** Stands in for LabsSource (owned by another in-flight branch) just to exercise its label. */
class FakeLabsSourceForRecTitles extends IntelSource
{
    public function family(): string
    {
        return 'fake_labs';
    }

    public function label(): string
    {
        return 'DataForSEO Labs — competitor keyword gaps';
    }

    public function estimateCost(): float
    {
        return 0.0;
    }

    public function collect(): array
    {
        return [];
    }

    public function findings(): array
    {
        return [];
    }

    public function report(): array
    {
        return ['tiles' => [], 'tables' => []];
    }
}

/** Stands in for BusinessDataSource's OWN label (its findings are the real thing, tested separately). */
class FakeBusinessDataSourceForRecTitles extends IntelSource
{
    public function family(): string
    {
        return 'fake_business_data';
    }

    public function label(): string
    {
        return 'Business Data — GBP profile & local listings';
    }

    public function estimateCost(): float
    {
        return 0.0;
    }

    public function collect(): array
    {
        return [];
    }

    public function findings(): array
    {
        return [];
    }

    public function report(): array
    {
        return ['tiles' => [], 'tables' => []];
    }
}

/**
 * C1-3: RecommendationEngine::intelRecs() used to build its title by
 * concatenating the family's label onto the raw finding title —
 * "DataForSEO Labs — competitor keyword gaps: Page lost ranking keywords".
 * The family label now travels in a separate 'source' field; 't' (and the
 * critical action-item strings) must never carry it as a prefix.
 */
class RecommendationEngineIntelTitlesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.dataforseo.login' => 'u',
            'services.dataforseo.password' => 'p',
            'seo-intel.sources' => [FakeLabsSourceForRecTitles::class, FakeBusinessDataSourceForRecTitles::class],
        ]);
    }

    /** @return array{0: list<string>, 1: list<array<string, mixed>>} */
    private function intelRecs(): array
    {
        $engine = app(RecommendationEngine::class);
        $method = new ReflectionMethod($engine, 'intelRecs');
        $method->setAccessible(true);

        return $method->invoke($engine);
    }

    public function test_intel_recommendation_and_action_item_titles_never_carry_a_vendor_or_family_prefix(): void
    {
        $today = now()->toDateString();
        DB::table('seo_intel_findings')->insert([
            [
                'site_id' => null, 'fingerprint' => 'fp-labs-1', 'family' => 'fake_labs', 'code' => 'fake_labs.lost_keywords',
                'severity' => 'warn', 'title' => 'Page lost ranking keywords', 'detail' => 'Mundelein service page.',
                'first_seen_on' => $today, 'last_seen_on' => $today, 'last_run_id' => 'r1', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'site_id' => null, 'fingerprint' => 'fp-bd-1', 'family' => 'fake_business_data', 'code' => 'fake_business_data.unanswered_reviews',
                'severity' => 'critical', 'title' => '16 review(s) awaiting an owner response', 'detail' => '',
                'first_seen_on' => $today, 'last_seen_on' => $today, 'last_run_id' => 'r1', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        [$urgent, $recs] = $this->intelRecs();

        $this->assertNotEmpty($urgent);
        $this->assertStringContainsString('16 review(s) awaiting an owner response', $urgent[0]);

        $rec = collect($recs)->firstWhere('t', 'Page lost ranking keywords');
        $this->assertNotNull($rec, 'the warn finding must produce a recommendation with its raw, unprefixed title');
        $this->assertSame('DataForSEO Labs — competitor keyword gaps', $rec['source'], 'the family label moves to its own field');
        $this->assertSame('now', $rec['p']);

        $titles = array_merge($urgent, array_column($recs, 't'));
        foreach ($titles as $title) {
            foreach (['DataForSEO', 'GBP', 'Labs', 'Business Data', 'On-Page'] as $needle) {
                $this->assertStringNotContainsString($needle, $title, "recommendation title \"{$title}\" must not carry a vendor/family prefix");
            }
        }
    }

    public function test_returns_no_recommendations_when_there_are_no_open_findings(): void
    {
        [$urgent, $recs] = $this->intelRecs();

        $this->assertSame([], $urgent);
        $this->assertSame([], $recs);
    }

    /**
     * The central admin shows recommendation titles and bodies to operators
     * verbatim (it stopped rewriting vendor language once this engine went
     * plain-spoken), so the copy itself must never name pipeline files or
     * data vendors — those belong in the 'source' field, which the admin
     * renders only inside a Details accordion.
     */
    public function test_recommendation_copy_never_names_pipeline_files_or_vendors(): void
    {
        $source = file_get_contents(app_path('Services/Seo/RecommendationEngine.php'));
        preg_match_all("/'(?:t|d)'\\s*=>\\s*(.+)$/m", $source, $m);
        $copy = implode("\n", $m[1]);

        foreach (['llms.txt', 'llms-full', 'Search Console', 'GSC', 'DataForSEO', 'Clarity', 'IndexNow', 'SERP'] as $raw) {
            $this->assertStringNotContainsString($raw, $copy, "Recommendation copy names '{$raw}' — move it to the source field.");
        }
    }
}
