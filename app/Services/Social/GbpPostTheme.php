<?php

namespace App\Services\Social;

use App\Models\AreaServed;
use App\Services\Seo\Intel\IntelStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The theme a week's Google Business Profile post follows: a season note,
 * a service that is rising in Google Trends (else a rotation), and one of
 * the core towns rotated week by week. The picker prefers a matching
 * project photo and the caption writer weaves the theme in, so the posts
 * read like a plan instead of a random gallery.
 */
class GbpPostTheme
{
    /** Project types as the projects table spells them. */
    public const SERVICE_ROTATION = ['kitchen', 'bathroom', 'basement', 'home-remodel', 'addition'];

    /** @return array{service_type: ?string, town: ?string, season: string, rising_phrase: ?string, note: string, week: int} */
    public function forWeek(?Carbon $when = null): array
    {
        $when = $when ?? now();
        $week = (int) $when->isoWeek;
        $season = self::season($when);
        $towns = array_values(AreaServed::coreTowns(6));
        $town = $towns !== [] ? $towns[$week % count($towns)] : null;

        [$service, $phrase] = $this->risingService();
        if ($service === null) {
            $service = self::SERVICE_ROTATION[$week % count(self::SERVICE_ROTATION)];
        }

        $note = $season['note'] . ($phrase ? " Searches for \"{$phrase}\" are rising locally right now." : '');

        return ['service_type' => $service, 'town' => $town, 'season' => $season['name'], 'rising_phrase' => $phrase, 'note' => $note, 'week' => $week];
    }

    /** Season name and the angle a remodeler leans on in it. */
    public static function season(Carbon $when): array
    {
        return match (true) {
            in_array($when->month, [12, 1, 2], true) => ['name' => 'winter', 'note' => 'Winter is indoor-project season: basements, bathrooms and kitchens finish before spring.'],
            in_array($when->month, [3, 4, 5], true) => ['name' => 'spring', 'note' => 'Spring is planning season: kitchens and additions designed now are built by summer.'],
            in_array($when->month, [6, 7, 8], true) => ['name' => 'summer', 'note' => 'Summer is building season for additions and whole-home work while the weather helps.'],
            default => ['name' => 'fall', 'note' => 'Fall projects finish before the holidays: kitchens and bathrooms ready for guests.'],
        };
    }

    /**
     * The service whose Google Trends phrase is furthest above its 12-month
     * average (from the trends intelligence), when any is at least 15% up.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function risingService(): array
    {
        if (! Schema::hasTable('seo_intel_snapshots')) {
            return [null, null];
        }
        $best = null;
        $bestRatio = 1.15;
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
                $best = [$service, (string) $phrase];
            }
        }

        return $best ?? [null, null];
    }

    /** Map a phrase to a project type used by the projects table. */
    public static function serviceFor(string $phrase): ?string
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
