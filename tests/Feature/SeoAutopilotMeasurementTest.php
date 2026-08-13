<?php

namespace Tests\Feature;

use App\Models\SeoAction;
use App\Services\Seo\SeoAutopilotService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The autopilot must not learn from rounding error.
 *
 * judge() had no minimum baseline, so a page going from 0 impressions to 1
 * scored +100% and was filed as WORKED, and 1 -> 301 scored +30000%. On
 * production every one of the 59 measured "reindex" actions started from <= 1
 * impression, 11 were recorded as wins, and the dashboard headlined an average
 * of +2818% — while the median effect across all of them was exactly 0%.
 *
 * That fed learnedWeight(), which scored the category up and made the autopilot
 * propose more of the same: a loop learning from noise, and recommending real
 * work be prioritised on the strength of it.
 */
class SeoAutopilotMeasurementTest extends TestCase
{
    private function judge(float $before, float $after, string $metric = 'impressions'): string
    {
        $service = app(SeoAutopilotService::class);
        $method = new ReflectionMethod($service, 'judge');
        $method->setAccessible(true);

        // `sample` only matters for the both-zero branch; mirror the after value.
        return $method->invoke($service, $before, $after, $metric, ['impressions' => $after]);
    }

    /** @return array<string, array{float, float, string}> */
    public static function noisyBaselines(): array
    {
        return [
            'zero to one impression' => [0.0, 1.0, 'impressions'],
            'one to three hundred impressions' => [1.0, 301.0, 'impressions'],
            'zero to eight impressions' => [0.0, 8.0, 'impressions'],
            'one to three clicks' => [1.0, 3.0, 'clicks'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noisyBaselines')]
    public function test_a_near_zero_baseline_never_counts_as_a_win(float $before, float $after, string $metric): void
    {
        $this->assertSame(
            SeoAction::OUTCOME_INCONCLUSIVE,
            $this->judge($before, $after, $metric),
            sprintf('%s %s -> %s should be inconclusive, not a measured win', $metric, $before, $after),
        );
    }

    public function test_a_real_gain_on_a_real_baseline_still_counts(): void
    {
        $this->assertSame(SeoAction::OUTCOME_WORKED, $this->judge(40.0, 90.0));
        $this->assertSame(SeoAction::OUTCOME_WORKED, $this->judge(10.0, 20.0, 'clicks'));
    }

    public function test_a_real_regression_is_still_caught(): void
    {
        $this->assertSame(SeoAction::OUTCOME_REGRESSED, $this->judge(200.0, 80.0));
    }

    public function test_a_flat_result_on_a_real_baseline_is_no_effect(): void
    {
        $this->assertSame(SeoAction::OUTCOME_NO_EFFECT, $this->judge(100.0, 103.0));
    }
}
