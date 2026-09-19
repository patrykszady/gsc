<?php

namespace Tests\Feature\Areas;

use App\Support\Areas\StateLocator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adding a town from the coverage map took 4.6-7.3 seconds, ~1.5s of which was
 * a Nominatim reverse-geocode asking which state the click was in. The bundled
 * ZIP gazetteer already knows, so these pin that it answers correctly — a wrong
 * state silently rejects a legitimate town as "outside this studio's markets".
 */
class StateLocatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Nothing here may reach the network; that is the entire point.
        Http::preventStrayRequests();
    }

    /** @return array<string, array{0: string, 1: float, 2: float, 3: string}> */
    public static function towns(): array
    {
        return [
            'Atlanta, GA' => ['Atlanta', 33.7490, -84.3880, 'GA'],
            'Marietta, GA' => ['Marietta', 33.9526, -84.5499, 'GA'],
            'Evanston, IL' => ['Evanston', 42.0451, -87.6877, 'IL'],
            'Naperville, IL' => ['Naperville', 41.7508, -88.1535, 'IL'],
            'St. Joseph, MI' => ['St. Joseph', 42.1098, -86.4800, 'MI'],
        ];
    }

    #[DataProvider('towns')]
    public function test_it_places_a_town_in_its_state(string $city, float $lat, float $lng, string $expected): void
    {
        $this->assertSame($expected, StateLocator::forTown($city, $lat, $lng));
    }

    /**
     * The name is used before the coordinates, and this is why: Decatur exists
     * in both Georgia and Illinois, so the nearest one bearing the name is
     * meant — not the nearest ZIP centroid of any name, which near a state
     * line can sit across the border.
     */
    public function test_a_shared_town_name_resolves_by_which_one_is_nearest(): void
    {
        $this->assertSame('GA', StateLocator::forTown('Decatur', 33.7748, -84.2963));
        $this->assertSame('IL', StateLocator::forTown('Decatur', 39.8403, -88.9548));
    }

    /** A point in open water belongs to no town; the caller falls back to the geocoder. */
    public function test_it_declines_to_guess_when_nothing_is_close(): void
    {
        $this->assertNull(StateLocator::forTown('Nowhere', 30.0, -70.0));
    }

    public function test_an_unknown_name_still_resolves_from_the_coordinates(): void
    {
        // Not a ZIP city name anywhere, but the point is plainly in Georgia.
        $this->assertSame('GA', StateLocator::forTown('Some Unnamed Place', 33.7490, -84.3880));
    }
}
