<?php

namespace Tests\Feature;

use App\Console\Commands\SeoHealth;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * seo:health keeps a rolling daily score ledger (reports/health-history.json,
 * same disk and tenant-path convention as reports/health.md — see
 * SeoHealth::appendHealthLedger()) so the admin snapshot can show a
 * week-over-week trend chevron next to the health score.
 *
 * The ledger-write tests call the command's protected writer directly via
 * reflection rather than running `seo:health` end to end: this repo's test
 * sqlite database has no images, areas, GBP activity, rank snapshots or
 * sync logs seeded for the 'gsc' tenant, so every pillar (and therefore the
 * overall score) comes back null — which would make the ledger a no-op and
 * tell us nothing about its own read/prune/write logic.
 */
class SeoHealthPriorScoreTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected const LEDGER_PATH = 'reports/health-history.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function appendLedger(?int $score): void
    {
        $command = app(SeoHealth::class);
        $method = new ReflectionMethod($command, 'appendHealthLedger');
        $method->setAccessible(true);
        $method->invoke($command, $score);
    }

    protected function ledger(): array
    {
        return json_decode(Storage::disk('local')->get(self::LEDGER_PATH), true);
    }

    public function test_it_appends_todays_score_and_prunes_to_the_newest_120_entries(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $seed = [];
        for ($i = 1; $i <= 130; $i++) {
            $seed[now()->subDays($i)->toDateString()] = 50;
        }
        Storage::disk('local')->put(self::LEDGER_PATH, json_encode($seed));

        $this->appendLedger(87);

        $ledger = $this->ledger();

        $this->assertCount(120, $ledger);
        $this->assertSame(87, $ledger[now()->toDateString()]);
        // Newest 120 kept: today (i=0) plus i=1..119. i=120 and older pruned.
        $this->assertArrayHasKey(now()->subDays(119)->toDateString(), $ledger);
        $this->assertArrayNotHasKey(now()->subDays(120)->toDateString(), $ledger);
    }

    public function test_writing_twice_in_one_day_keeps_only_the_last_score(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $this->appendLedger(40);
        $this->appendLedger(91);

        $ledger = $this->ledger();

        $this->assertCount(1, $ledger);
        $this->assertSame(91, $ledger[now()->toDateString()]);
    }

    public function test_a_null_total_never_touches_the_ledger(): void
    {
        Storage::fake('local');

        $this->appendLedger(null);

        $this->assertFalse(Storage::disk('local')->exists(self::LEDGER_PATH));
    }

    public function test_a_ledger_write_failure_is_swallowed_and_never_bubbles_up(): void
    {
        Storage::fake('local');
        // Malformed JSON already on disk — appendHealthLedger must recover
        // (treat as an empty ledger) rather than throw.
        Storage::disk('local')->put(self::LEDGER_PATH, '{not valid json');

        $this->appendLedger(70);

        $ledger = $this->ledger();
        $this->assertSame(70, $ledger[now()->toDateString()]);
    }

    public function test_prior_score_picks_the_ledger_entry_closest_to_seven_days_ago(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-03-15'));

        Storage::disk('local')->put(self::LEDGER_PATH, json_encode([
            now()->subDays(3)->toDateString() => 10,  // age 3, too recent (<5d) — excluded
            now()->subDays(6)->toDateString() => 65,  // age 6, closest to 7
            now()->subDays(9)->toDateString() => 72,  // age 9, further than age 6
            now()->subDays(20)->toDateString() => 99, // age 20, too old (>14d) — excluded
        ]));

        $data = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(65, $data['health']['prior_score']);
    }

    public function test_prior_score_is_null_when_the_ledger_is_empty(): void
    {
        Storage::fake('local');

        $data = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertNull($data['health']['prior_score']);
    }

    public function test_snapshot_still_carries_score_and_pillars_alongside_prior_score(): void
    {
        Storage::fake('local');

        $data = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('score', $data['health']);
        $this->assertArrayHasKey('pillars', $data['health']);
        $this->assertArrayHasKey('prior_score', $data['health']);
        $this->assertIsArray($data['health']['pillars']);
    }
}
