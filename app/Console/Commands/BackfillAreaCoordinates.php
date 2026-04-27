<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;

/**
 * Backfills latitude/longitude on areas_served using a static map of well-known
 * Chicago-area suburb coordinates. Used by the "Nearby cities we also serve"
 * internal-linking SEO block and Service schema's areaServed GeoCircle.
 */
class BackfillAreaCoordinates extends Command
{
    protected $signature = 'areas:backfill-coordinates {--force : Overwrite existing coordinates}';
    protected $description = 'Set lat/long on areas_served from a static Chicago-suburb map';

    /** @var array<string, array{0: float, 1: float}> slug => [lat, lng] */
    private array $coords = [
        'arlington-heights'  => [42.0884, -87.9806],
        'barrington'         => [42.1539, -88.1362],
        'barrington-hills'   => [42.1583, -88.1731],
        'buffalo-grove'      => [42.1517, -87.9596],
        'chicago'            => [41.8781, -87.6298],
        'countryside'        => [41.7831, -87.8784],
        'deer-park'          => [42.1700, -88.0962],
        'deerfield'          => [42.1711, -87.8445],
        'deerpath'           => [42.1900, -87.8500],
        'des-plaines'        => [42.0334, -87.8834],
        'elgin'              => [42.0354, -88.2826],
        'elk-grove-village'  => [42.0039, -87.9706],
        'elmwood-park'       => [41.9211, -87.8112],
        'evanston'           => [42.0451, -87.6877],
        'forest-lake'        => [42.2400, -87.9925],
        'forest-park'        => [41.8717, -87.8147],
        'forest-view'        => [41.8156, -87.7831],
        'fort-hill'          => [42.3000, -88.0000],
        'fox-lake'           => [42.3953, -88.1842],
        'fox-lake-hills'     => [42.4100, -88.1700],
        'fox-point-north'    => [42.4000, -88.2000],
        'fox-river-grove'    => [42.2014, -88.2148],
        'glencoe'            => [42.1356, -87.7581],
        'glenview'           => [42.0698, -87.7878],
        'grayslake'          => [42.3445, -88.0376],
        'great-lakes'        => [42.3000, -87.8500],
        'green-oaks'         => [42.2731, -87.9420],
        'gurnee'             => [42.3697, -87.9020],
        'harwood-heights'    => [41.9659, -87.8067],
        'hawthorn-woods'     => [42.2278, -88.0570],
        'highland-park'      => [42.1817, -87.8003],
        'hoffman-estates'    => [42.0628, -88.1117],
        'hometown'           => [41.7300, -87.7314],
        'homewood'           => [41.5572, -87.6656],
        'indian-creek'       => [42.2200, -87.9800],
        'inverness'          => [42.1167, -88.0962],
        'kenilworth'         => [42.0867, -87.7173],
        'kildeer'            => [42.1814, -88.0606],
        'lake-barrington'    => [42.2050, -88.1497],
        'lake-bluff'         => [42.2792, -87.8345],
        'lake-forest'        => [42.2586, -87.8406],
        'lake-villa'         => [42.4167, -88.0739],
        'lake-zurich'        => [42.1969, -88.0934],
        'libertyville'       => [42.2831, -87.9534],
        'lincolnshire'       => [42.1900, -87.9089],
        'lincolnwood'        => [42.0061, -87.7295],
        'lindenhurst'        => [42.4172, -88.0262],
        'long-grove'         => [42.1808, -87.9956],
        'long-lake'          => [42.3700, -88.1500],
        'melrose-park'       => [41.9000, -87.8567],
        'morton-grove'       => [42.0406, -87.7826],
        'mount-prospect'     => [42.0664, -87.9373],
        'mundelein'          => [42.2631, -88.0034],
        'niles'              => [42.0294, -87.8003],
        'norridge'           => [41.9633, -87.8270],
        'north-barrington'   => [42.2058, -88.1448],
        'northbrook'         => [42.1275, -87.8290],
        'northfield'         => [42.0978, -87.7820],
        'northlake'          => [41.9114, -87.8967],
        'oak-forest'         => [41.6028, -87.7531],
        'oak-lawn'           => [41.7100, -87.7530],
        'oak-park'           => [41.8850, -87.7845],
        'orland-hills'       => [41.5900, -87.8489],
        'orland-park'        => [41.6303, -87.8539],
        'palatine'           => [42.1103, -88.0342],
        'palos-park'         => [41.6611, -87.8298],
        'park-forest'        => [41.4839, -87.6745],
        'park-ridge'         => [42.0111, -87.8406],
        'port-barrington'    => [42.2233, -88.2095],
        'prospect-heights'   => [42.0950, -87.9373],
        'river-forest'       => [41.8978, -87.8139],
        'river-grove'        => [41.9272, -87.8359],
        'riverdale'          => [41.6403, -87.6270],
        'riverside'          => [41.8350, -87.8217],
        'riverwoods'         => [42.1658, -87.8973],
        'rolling-meadows'    => [42.0844, -88.0142],
        'rosemont'           => [41.9953, -87.8842],
        'round-lake'         => [42.3503, -88.1101],
        'schaumburg'         => [42.0334, -88.0834],
        'schiller-park'      => [41.9558, -87.8709],
        'skokie'             => [42.0334, -87.7334],
        'south-barrington'   => [42.1414, -88.1495],
        'streamwood'         => [42.0256, -88.1784],
        'vernon-hills'       => [42.2200, -87.9810],
        'western-springs'    => [41.8092, -87.9006],
        'wheeling'           => [42.1392, -87.9289],
        'willow-springs'     => [41.7350, -87.8645],
        'wilmette'           => [42.0723, -87.7228],
        'winnetka'           => [42.1081, -87.7359],
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $updated = 0;
        $skipped = 0;
        $missing = [];

        foreach (AreaServed::all() as $area) {
            if (!$force && $area->latitude !== null && $area->longitude !== null) {
                $skipped++;
                continue;
            }
            if (!isset($this->coords[$area->slug])) {
                $missing[] = $area->slug;
                continue;
            }
            [$lat, $lng] = $this->coords[$area->slug];
            $area->update(['latitude' => $lat, 'longitude' => $lng]);
            $updated++;
        }

        $this->info("Updated: {$updated}");
        $this->info("Skipped (already set): {$skipped}");
        if (!empty($missing)) {
            $this->warn('Missing coords for: ' . implode(', ', $missing));
        }

        return self::SUCCESS;
    }
}
