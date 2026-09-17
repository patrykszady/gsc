<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Support\Seo\CrawlFiles;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A town page family dated in config/seo.php (area_family_dates) is announced
 * with that date — including on the day it ships, as "now" — while a family
 * without a date keeps the town's own lastmod.
 */
class SitemapFamilyLastmodTest extends TestCase
{
    /** @return array<string, string> loc => lastmod */
    private function lastmods(): array
    {
        $this->artisan('sitemap:generate', ['--url' => 'https://gs.construction'])->assertSuccessful();
        $xml = (string) file_get_contents(CrawlFiles::sitemapPath());

        $out = [];
        preg_match_all('#<url>(.*?)</url>#s', $xml, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match('#<loc>(.*?)</loc>#s', $block, $loc);
            preg_match('#<lastmod>(.*?)</lastmod>#s', $block, $mod);
            $out[$loc[1] ?? ''] = $mod[1] ?? '';
        }

        return $out;
    }

    public function test_a_family_dated_today_is_announced_today_and_an_undated_family_keeps_the_towns_date(): void
    {
        Carbon::setTestNow('2026-09-17 15:00:00');
        $area = AreaServed::create([
            'city' => 'Kenilworth', 'slug' => 'kenilworth', 'state' => 'IL', 'latitude' => 42.0859, 'longitude' => -87.7176,
            'local_intro' => str_repeat('Unique local copy about the town. ', 60),
        ]);
        AreaServed::whereKey($area->id)->update(['updated_at' => '2026-09-01 10:00:00']);
        config(['seo.area_family_dates' => ['contact' => '2026-09-17', 'about' => '2026-09-30']]);

        $mods = $this->lastmods();
        $base = 'https://gs.construction/areas-served/kenilworth';

        $this->assertStringStartsWith('2026-09-17', $mods["{$base}/contact"] ?? '', 'the contact family shipped today: announced as of now');
        $this->assertStringStartsWith('2026-09-01', $mods[$base] ?? '', 'no family date for the town page: its own date');
        $this->assertStringStartsWith('2026-09-01', $mods["{$base}/about"] ?? '', 'a family date still in the future is ignored');
        $this->assertStringStartsWith('2026-09-01', $mods["{$base}/services"] ?? '', 'an undated family keeps the town date');

        Carbon::setTestNow();
    }
}
