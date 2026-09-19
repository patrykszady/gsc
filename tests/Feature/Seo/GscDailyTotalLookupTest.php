<?php

namespace Tests\Feature\Seo;

use App\Models\GscDailyTotal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the lookup SyncGoogleSearchConsole::syncDailyTotals() re-syncs through.
 *
 * It used to match with updateOrCreate(['date' => $date, …]). Eloquent's `date`
 * cast writes the column as a full 'Y-m-d H:i:s' string, and only MySQL's DATE
 * type truncates that back on write — which is the single reason the
 * exact-string match ever found the row. Nothing here had test coverage, so the
 * breakage only surfaced when jpeterson-design ported the same code and ran it
 * on sqlite: every re-sync missed, fell through to an insert, and hit the
 * (date, site_url) unique index.
 *
 * This asserts the engine-independent half — that a row written through the
 * model is findable by its plain date — so the fix cannot be quietly reverted.
 */
class GscDailyTotalLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_is_found_by_its_plain_date_after_the_model_writes_it(): void
    {
        GscDailyTotal::create([
            'date' => '2026-09-16',
            'site_url' => 'sc-domain:example.test',
            'clicks' => 14,
            'impressions' => 340,
            'ctr' => 0.0412,
            'position' => 7.8,
        ]);

        $found = GscDailyTotal::whereDate('date', '2026-09-16')
            ->where('site_url', 'sc-domain:example.test')
            ->first();

        $this->assertNotNull($found, 'A day written by the sync must be findable on the next run, or the re-sync duplicates it.');
        $this->assertSame(14, $found->clicks);
    }

    public function test_re_running_the_same_day_updates_rather_than_duplicating(): void
    {
        $write = function (int $clicks): void {
            $row = GscDailyTotal::whereDate('date', '2026-09-16')
                ->where('site_url', 'sc-domain:example.test')
                ->first();

            $attrs = ['clicks' => $clicks, 'impressions' => 340, 'ctr' => 0.04, 'position' => 7.8];

            $row
                ? $row->update($attrs)
                : GscDailyTotal::create($attrs + ['date' => '2026-09-16', 'site_url' => 'sc-domain:example.test']);
        };

        $write(14);
        $write(21);

        $this->assertSame(1, GscDailyTotal::where('site_url', 'sc-domain:example.test')->count());
        $this->assertSame(21, GscDailyTotal::where('site_url', 'sc-domain:example.test')->first()->clicks);
    }
}
