<?php

namespace Tests\Feature;

use App\Services\GeoapifyService;
use App\Services\LeadAddressCompleter;
use Mockery;
use Tests\TestCase;

/**
 * LeadAddressCompleter's policy, ported from hive2025 (see
 * app/Services/LeadAddressCompleter.php and hive2025's
 * tests/Feature/LeadAddressCompletionTest.php).
 *
 * GeoapifyService is always mocked here — a real GeoapifyService uses a raw
 * Guzzle client (not the Http facade), so Http::fake() cannot intercept it.
 * See phpunit.xml, which also blanks GEOAPIFY_API_KEY as a hard backstop.
 */
class LeadAddressCompletionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockGeoapify(): Mockery\MockInterface
    {
        $mock = Mockery::mock(GeoapifyService::class);
        $this->app->instance(GeoapifyService::class, $mock);

        return $mock;
    }

    public function test_a_stated_zip_beats_the_geocoder(): void
    {
        // The sender who wrote their own ZIP knows it better than a geocoder
        // that answers 60642 for the same street.
        $this->mockGeoapify()
            ->shouldReceive('geocodeAddress')
            ->once()
            ->andReturn([
                'address' => '5647 N Magnolia Ave',
                'city' => 'Chicago',
                'state' => 'IL',
                'zip_code' => '60642',
            ]);

        $completed = app(LeadAddressCompleter::class)->complete([
            'address' => '5647 N Magnolia Ave',
            'city' => 'Chicago',
            'state' => null,
            'zip' => '60660',
        ]);

        $this->assertSame('60660', $completed['zip']);
        $this->assertSame('Chicago', $completed['city']);
        $this->assertSame('IL', $completed['state']);
    }

    public function test_an_already_complete_address_never_calls_the_geocoder(): void
    {
        $this->mockGeoapify()->shouldNotReceive('geocodeAddress');
        $this->mockGeoapify()->shouldNotReceive('nearbyAddressCandidates');

        $data = [
            'address' => '5 Oak St',
            'city' => 'Barrington',
            'state' => 'IL',
            'zip' => '60010',
        ];

        $this->assertSame($data, app(LeadAddressCompleter::class)->complete($data));
    }

    public function test_an_unanchored_street_with_no_nearby_match_is_left_incomplete(): void
    {
        // A bare street with nothing else stated: geocodeAddress refuses (a
        // real GeoapifyService would too — see GeoapifyServiceHelpersTest's
        // addressAnchor coverage), and there's no nearby match either.
        $mock = $this->mockGeoapify();
        $mock->shouldReceive('geocodeAddress')->once()->andReturnNull();
        $mock->shouldReceive('nearbyAddressCandidates')->once()->with('511 Sherwood Dr')->andReturn([]);

        $completed = app(LeadAddressCompleter::class)->complete([
            'address' => '511 Sherwood Dr',
            'city' => null,
            'state' => null,
            'zip' => null,
        ]);

        $this->assertNull($completed['city']);
        $this->assertNull($completed['state']);
        $this->assertNull($completed['zip']);
        $this->assertArrayNotHasKey('address_candidates', $completed);
    }

    public function test_a_single_nearby_candidate_is_taken_as_the_answer(): void
    {
        // "511 Sherwood Dr" only exists once inside the service area — safe
        // to commit to it.
        $mock = $this->mockGeoapify();
        $mock->shouldReceive('geocodeAddress')->once()->andReturnNull();
        $mock->shouldReceive('nearbyAddressCandidates')->once()->andReturn([
            ['address' => '511 Sherwood Dr', 'city' => 'Addison', 'state' => 'IL', 'zip_code' => '60101', 'miles' => 12.0],
        ]);

        $completed = app(LeadAddressCompleter::class)->complete([
            'address' => '511 Sherwood Dr',
            'city' => null,
            'state' => null,
            'zip' => null,
        ]);

        $this->assertSame('Addison', $completed['city']);
        $this->assertSame('IL', $completed['state']);
        $this->assertSame('60101', $completed['zip']);
        $this->assertArrayNotHasKey('address_candidates', $completed);
    }

    public function test_multiple_nearby_candidates_are_stored_for_a_human_to_pick(): void
    {
        // "511 Sherwood Dr" is real in BOTH Addison and Streamwood — taking
        // the nearest would file the lead under the wrong town, so neither
        // is picked automatically.
        $candidates = [
            ['address' => '511 Sherwood Dr', 'city' => 'Addison', 'state' => 'IL', 'zip_code' => '60101', 'miles' => 12.0],
            ['address' => '511 Sherwood Dr', 'city' => 'Streamwood', 'state' => 'IL', 'zip_code' => '60107', 'miles' => 13.4],
        ];

        $mock = $this->mockGeoapify();
        $mock->shouldReceive('geocodeAddress')->once()->andReturnNull();
        $mock->shouldReceive('nearbyAddressCandidates')->once()->andReturn($candidates);

        $completed = app(LeadAddressCompleter::class)->complete([
            'address' => '511 Sherwood Dr',
            'city' => null,
            'state' => null,
            'zip' => null,
        ]);

        $this->assertNull($completed['city']);
        $this->assertNull($completed['state']);
        $this->assertNull($completed['zip']);
        $this->assertSame($candidates, $completed['address_candidates']);
    }

    public function test_a_blank_the_sender_left_null_is_still_filled(): void
    {
        // The website form posts blanks explicitly ("city": null). A naive
        // `$leadData + [...]` union treats the key as already filled and
        // throws the geocoder's answer away — this must not regress.
        $this->mockGeoapify()
            ->shouldReceive('geocodeAddress')
            ->once()
            ->andReturn([
                'address' => '166 Akenside Road',
                'city' => 'Riverside',
                'state' => 'IL',
                'zip_code' => '60546',
            ]);

        $completed = app(LeadAddressCompleter::class)->complete([
            'address' => '166 Akenside rd  Riverside Il',
            'city' => null,
            'state' => 'IL',
            'zip' => '60546',
        ]);

        $this->assertSame('Riverside', $completed['city']);
        // The street no longer repeats the city it now sits next to.
        $this->assertSame('166 Akenside Rd', $completed['address']);
    }

    public function test_no_address_at_all_is_a_no_op(): void
    {
        $this->mockGeoapify()->shouldNotReceive('geocodeAddress');

        $data = ['city' => 'Chicago', 'state' => null, 'zip' => null];

        $this->assertSame($data, app(LeadAddressCompleter::class)->complete($data));
    }
}
