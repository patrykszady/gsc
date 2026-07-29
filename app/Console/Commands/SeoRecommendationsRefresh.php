<?php

namespace App\Console\Commands;

use App\Services\Seo\RecommendationEngine;
use Illuminate\Console\Command;

/**
 * Regenerate the SEO report's Priority Actions + recommendations from live
 * data, performing safe self-healing along the way (stale sync dispatch,
 * retired coverage-row pruning). Scheduled daily after the autopilot run so
 * the admin report always reflects the current state, not last month's advice.
 */
class SeoRecommendationsRefresh extends Command
{
    protected $signature = 'seo:recommendations-refresh {--no-heal : Generate advice only; take no corrective actions}';

    protected $description = 'Rebuild data-driven SEO recommendations and self-heal stale pipelines.';

    public function handle(RecommendationEngine $engine): int
    {
        $result = $engine->refresh(! $this->option('no-heal'));

        foreach ($result['healed'] as $h) {
            $this->info('healed: ' . $h);
        }
        $this->info('Priority actions: ' . count($result['action_items']));
        foreach ($result['action_items'] as $item) {
            $this->line('  • ' . $item);
        }
        $this->info('Recommendations: ' . count($result['recommendations']));
        foreach ($result['recommendations'] as $rec) {
            $this->line('  • [' . $rec['p'] . '] ' . $rec['t']);
        }

        return self::SUCCESS;
    }
}
