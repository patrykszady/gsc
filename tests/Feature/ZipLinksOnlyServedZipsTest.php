<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\HiveProjectZipCount;
use App\Services\HiveProjectsClient;
use App\Services\ZipCodeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ZIP and town pages link only ZIPs that have a page of their own. On
 * 2026-10-02 the ZIP pages' "nearest ZIPs" list, built from every ZIP Hive
 * has finished a job in, linked 9 ZIPs outside the published area list
 * (60013 Cary, 60040 Highwood…), and each of those 301s to /service-area.
 */
class ZipLinksOnlyServedZipsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function jobZip(string $zip, string $city, float $lat, float $lng): void
    {
        HiveProjectZipCount::create([
            'zip' => $zip, 'city' => $city, 'state' => 'IL',
            'zip_latitude' => $lat, 'zip_longitude' => $lng, 'count' => 3,
        ]);
    }

    private function fixture(): AreaServed
    {
        Cache::flush();
        $area = AreaServed::create([
            'city' => 'Arlington Heights', 'slug' => 'arlington-heights', 'state' => 'IL',
            'latitude' => 42.0884, 'longitude' => -87.9806,
            'local_intro' => str_repeat('Unique local copy about the town. ', 60),
        ]);
        $this->jobZip('60004', 'Arlington Heights', 42.1103, -87.9798);
        $this->jobZip('60005', 'Arlington Heights', 42.0631, -87.9859);
        $this->jobZip('60013', 'Cary', 42.2203, -88.2387);
        Cache::flush();

        return $area;
    }

    public function test_a_zip_page_links_only_nearby_zips_with_a_page(): void
    {
        $this->fixture();
        $zips = app(ZipCodeService::class);

        $this->get('/service-area/60013')->assertStatus(301);

        $points = app(HiveProjectsClient::class)->storedZipPoints();
        $nearest = $zips->nearestServedZips($points, '60004', 42.1103, -87.9798);

        $this->assertSame(['60005'], array_column($nearest, 'zip'));
    }

    public function test_a_town_lists_only_its_zips_with_a_page(): void
    {
        $area = $this->fixture();
        HiveProjectZipCount::create(['zip' => '60013', 'city' => 'Arlington Heights', 'state' => 'IL', 'count' => 1]);
        Cache::flush();

        $this->assertContains('60013', $area->postalCodes());
        $this->assertSame(['60004', '60005'], $area->servedPostalCodes());
    }
}
