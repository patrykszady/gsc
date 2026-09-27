<?php

namespace App\Support\Social;

use App\Services\Seo\Intel\IntelStore;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Social\Contracts\TrendsIntel;

/**
 * The kit's `trends_intel` capability (kit 0.13.0): the service whose
 * Google Trends phrase is furthest above its 12-month average, when any is
 * at least 15% up. Moved here unchanged from the old
 * `App\Services\Social\GbpPostTheme::risingService()`/`serviceFor()` (this
 * is where the phrase→service vocabulary now lives — kitchen/bathroom/
 * basement/addition/home-remodel is this site's own trades wording, and
 * the kit contract never sees it, only the resolved answer). `IntelStore`
 * reads through `Tenancy::table()`, so this is tenant-scoped for free.
 */
final class SeoIntelTrendsIntel implements TrendsIntel
{
    /** Ratio (this-4-weeks ÷ 12-month average) a phrase must clear to count as "rising". */
    private const RISING_RATIO = 1.15;

    public function risingPhrase(): ?array
    {
        if (! Schema::hasTable('seo_intel_snapshots')) {
            return null;
        }

        $best = null;
        $bestRatio = self::RISING_RATIO;
        foreach (app(IntelStore::class)->latestSet('trends', 'phrase') as $phrase => $snap) {
            $avg = (float) ($snap['metrics']['interest_avg_12m'] ?? 0);
            $now = (float) ($snap['metrics']['interest_4w_avg'] ?? 0);
            if ($avg <= 0) {
                continue;
            }
            $ratio = $now / $avg;
            $service = self::serviceFor((string) $phrase);
            if ($service !== null && $ratio >= $bestRatio) {
                $bestRatio = $ratio;
                $best = ['service' => $service, 'phrase' => (string) $phrase];
            }
        }

        return $best;
    }

    /** Map a phrase to a project type used by the projects table. */
    private static function serviceFor(string $phrase): ?string
    {
        $p = strtolower($phrase);

        return match (true) {
            str_contains($p, 'kitchen') => 'kitchen',
            str_contains($p, 'bath') => 'bathroom',
            str_contains($p, 'basement') => 'basement',
            str_contains($p, 'addition') => 'addition',
            str_contains($p, 'home') || str_contains($p, 'remodel') || str_contains($p, 'renovat') => 'home-remodel',
            default => null,
        };
    }
}
