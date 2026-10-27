<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * areas:sync-building-codes asks hive.contractors for each served town's
 * adopted building-code set and stores only a "found" answer — a town hive
 * is still looking up (pending), couldn't resolve (failed/not_found), or an
 * HTTP error must never wipe whatever is already stored.
 */
class AreasSyncBuildingCodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.hive.url', 'https://hive.test');
        Config::set('services.hive.token', 'test-token');
    }

    private function schaumburg(array $attributes = []): AreaServed
    {
        return AreaServed::create(array_merge([
            'city' => 'Schaumburg',
            'slug' => 'schaumburg',
        ], $attributes));
    }

    public function test_a_found_answer_is_stored(): void
    {
        $area = $this->schaumburg();

        Http::fake(['hive.test/api/v1/building-codes*' => Http::response(['data' => [
            'town' => 'Schaumburg',
            'state' => 'IL',
            'status' => 'found',
            'codes' => ['International Residential Code, 2021 Edition', 'International Energy Conservation Code, 2021 Edition'],
            'source_url' => 'https://www.schaumburg.com/building-codes',
            'note' => 'For permits issued after 1/1/2026',
            'checked_at' => '2026-10-27T13:00:00+00:00',
        ]])]);

        $this->artisan('areas:sync-building-codes')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://hive.test/api/v1/building-codes?town=Schaumburg&state=IL');

        $area->refresh();
        $this->assertSame(
            ['International Residential Code, 2021 Edition', 'International Energy Conservation Code, 2021 Edition'],
            $area->building_codes
        );
        $this->assertSame('https://www.schaumburg.com/building-codes', $area->building_codes_source_url);
        $this->assertSame('For permits issued after 1/1/2026', $area->building_codes_note);
        $this->assertSame('2026-10-27T13:00:00+00:00', $area->building_codes_checked_at->toIso8601String());
    }

    public function test_a_pending_answer_keeps_whatever_is_already_stored(): void
    {
        $area = $this->schaumburg([
            'building_codes' => ['Old Stored Code'],
            'building_codes_source_url' => 'https://old-source.test',
        ]);

        Http::fake(['hive.test/api/v1/building-codes*' => Http::response(['data' => [
            'town' => 'Schaumburg', 'state' => 'IL', 'status' => 'pending',
            'codes' => [], 'source_url' => null, 'note' => null, 'checked_at' => null,
        ]])]);

        $this->artisan('areas:sync-building-codes')->assertSuccessful();

        $area->refresh();
        $this->assertSame(['Old Stored Code'], $area->building_codes);
        $this->assertSame('https://old-source.test', $area->building_codes_source_url);
    }

    public function test_a_failed_or_not_found_answer_also_keeps_whatever_is_stored(): void
    {
        $area = $this->schaumburg(['building_codes' => ['Old Stored Code']]);

        foreach (['failed', 'not_found'] as $status) {
            Http::fake(['hive.test/api/v1/building-codes*' => Http::response(['data' => [
                'town' => 'Schaumburg', 'state' => 'IL', 'status' => $status,
                'codes' => [], 'source_url' => null, 'note' => null, 'checked_at' => null,
            ]])]);

            $this->artisan('areas:sync-building-codes')->assertSuccessful();

            $area->refresh();
            $this->assertSame(['Old Stored Code'], $area->building_codes, "status={$status} must not wipe stored codes");
        }
    }

    public function test_a_401_from_hive_keeps_whatever_is_stored_and_logs_rather_than_throws(): void
    {
        $area = $this->schaumburg(['building_codes' => ['Old Stored Code']]);

        Http::fake(['hive.test/api/v1/building-codes*' => Http::response('Unauthorized', 401)]);

        $this->artisan('areas:sync-building-codes')->assertSuccessful();

        $area->refresh();
        $this->assertSame(['Old Stored Code'], $area->building_codes);
    }

    public function test_it_does_nothing_when_the_hive_token_is_not_configured(): void
    {
        Config::set('services.hive.token', '');
        $this->schaumburg();

        Http::fake();

        $this->artisan('areas:sync-building-codes')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_area_option_limits_the_sync_to_one_town(): void
    {
        $schaumburg = $this->schaumburg();
        $other = AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine']);

        Http::fake(['hive.test/api/v1/building-codes*' => Http::response(['data' => [
            'town' => 'Schaumburg', 'state' => 'IL', 'status' => 'found',
            'codes' => ['International Residential Code, 2021 Edition'],
            'source_url' => 'https://www.schaumburg.com/building-codes', 'note' => null, 'checked_at' => '2026-10-27T13:00:00+00:00',
        ]])]);

        $this->artisan('areas:sync-building-codes --area=schaumburg')->assertSuccessful();

        Http::assertSentCount(1);
        $schaumburg->refresh();
        $other->refresh();
        $this->assertNotEmpty($schaumburg->building_codes);
        $this->assertNull($other->building_codes);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $area = $this->schaumburg();

        Http::fake(['hive.test/api/v1/building-codes*' => Http::response(['data' => [
            'town' => 'Schaumburg', 'state' => 'IL', 'status' => 'found',
            'codes' => ['International Residential Code, 2021 Edition'],
            'source_url' => 'https://www.schaumburg.com/building-codes', 'note' => null, 'checked_at' => '2026-10-27T13:00:00+00:00',
        ]])]);

        $this->artisan('areas:sync-building-codes --dry-run')->assertSuccessful();

        $area->refresh();
        $this->assertNull($area->building_codes);
    }
}
