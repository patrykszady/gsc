<?php

namespace App\Support;

use App\Models\AreaServed;

/**
 * How far a town is from the office, for the town contact pages.
 *
 * Straight-line (haversine) miles from config('brand.address') to the town's
 * centre, scaled to road miles by ROAD_FACTOR and to a drive-time band at
 * SUBURBAN_MPH. Deliberately not a routing API: the figures are "about"
 * numbers a homeowner reads once, not turn-by-turn, and they must not vary
 * between renders or cost a request per page.
 */
class OfficeTrip
{
    /** Road miles are typically 1.2–1.4× straight-line in the suburbs; 1.3 is the middle. */
    public const ROAD_FACTOR = 1.3;

    /** Average speed outside rush hour on suburban arterials and the tollway mix. */
    public const SUBURBAN_MPH = 28.0;

    /**
     * @return array{straight_miles: float, miles: int, minutes_low: int, minutes_high: int, from: string}|null
     *   null when the town or the office has no coordinates.
     */
    public static function to(AreaServed $area): ?array
    {
        $office = config('brand.address', []);
        $olat = $office['lat'] ?? null;
        $olng = $office['lng'] ?? null;
        if ($olat === null || $olng === null || $area->latitude === null || $area->longitude === null) {
            return null;
        }

        $straight = self::haversineMiles((float) $olat, (float) $olng, (float) $area->latitude, (float) $area->longitude);
        $road = $straight * self::ROAD_FACTOR;
        $minutes = $road / self::SUBURBAN_MPH * 60;

        // A band, rounded to 5 minutes, never narrower than 10 minutes wide and
        // never starting under 5: "about 5–15 minutes" for the office's own town.
        $low = max(5, (int) (floor($minutes * 0.85 / 5) * 5));
        $high = max($low + 10, (int) (ceil($minutes * 1.25 / 5) * 5));

        return [
            'straight_miles' => round($straight, 1),
            'miles' => max(1, (int) round($road)),
            'minutes_low' => $low,
            'minutes_high' => $high,
            'from' => trim(($office['city'] ?? '').', '.($office['state'] ?? ''), ', '),
        ];
    }

    public static function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 3959 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** "Mon–Sat 8:00 AM–6:00 PM" from config('brand.hours'); null when hours are not configured. */
    public static function hoursLine(): ?string
    {
        $hours = config('brand.hours', []);
        if (! is_array($hours) || $hours === []) {
            return null;
        }

        $open = array_filter($hours, fn ($h) => is_array($h) && count($h) === 2);
        if ($open === []) {
            return null;
        }

        $days = array_keys($open);
        $first = reset($open);
        $same = count(array_unique(array_map('json_encode', $open))) === 1;
        $fmt = fn (string $t) => ltrim(date('g:i A', strtotime($t)), '0');
        $span = count($days) > 1 ? $days[0].'–'.$days[count($days) - 1] : $days[0];

        return $same
            ? "{$span} {$fmt($first[0])}–{$fmt($first[1])}"
            : $span.' '.$fmt($first[0]).'–'.$fmt($first[1]).' (hours vary)';
    }
}
