<?php

namespace Tests\Unit;

use App\Services\GeoapifyService;
use Tests\TestCase;

/**
 * Pure parsing/anchoring helpers on GeoapifyService — no network involved.
 * Ported from hive2025's tests/Unit/GeoapifyAddressSplitTest.php (Pest) to
 * this suite's PHPUnit style; behaviour is unchanged since the methods were
 * copied verbatim.
 */
class GeoapifyServiceHelpersTest extends TestCase
{
    public function test_it_splits_a_written_address_into_structured_geocoder_fields(): void
    {
        $this->assertSame(
            [
                'housenumber' => '5647',
                'street' => 'N Magnolia Ave',
                'country' => 'United States',
                'city' => 'Chicago',
            ],
            GeoapifyService::splitForGeocoding('5647 N Magnolia Ave, Chicago'),
        );

        $this->assertSame(
            [
                'housenumber' => '5',
                'street' => 'Oak St',
                'country' => 'United States',
                'postcode' => '60010',
                'state' => 'IL',
                'city' => 'Barrington',
            ],
            GeoapifyService::splitForGeocoding('5 Oak St, Barrington, IL 60010, USA'),
        );
    }

    public function test_it_refuses_to_structure_an_address_with_nowhere_to_look(): void
    {
        // A bare street is unanswerable: the geocoder would happily resolve
        // "511 Sherwood Dr" to Rolla, Missouri at full confidence.
        $this->assertNull(GeoapifyService::splitForGeocoding('511 Sherwood Dr'));
        $this->assertNull(GeoapifyService::splitForGeocoding('Sherwood Dr, Chicago'));
        $this->assertNull(GeoapifyService::splitForGeocoding(''));
    }

    public function test_it_recognises_what_anchors_an_address_to_a_place(): void
    {
        $this->assertNull(GeoapifyService::addressAnchor('511 Sherwood Dr'));

        $this->assertSame(
            ['zip' => null, 'state' => null],
            GeoapifyService::addressAnchor('960 Danielson Ct, Gurnee'),
        );

        $this->assertSame(
            ['zip' => '60010', 'state' => 'IL'],
            GeoapifyService::addressAnchor('5 Oak St, Barrington, IL 60010'),
        );
    }

    public function test_it_puts_the_commas_back_into_an_address_typed_without_them(): void
    {
        $this->assertSame(
            '166 Akenside rd, Riverside, IL',
            GeoapifyService::normalizeSeparators('166 Akenside rd  Riverside Il'),
        );

        $this->assertSame(
            '1234 W Mt Prospect Rd, Mount Prospect, IL',
            GeoapifyService::normalizeSeparators('1234 W Mt Prospect Rd Mount Prospect IL'),
        );

        // A trailing ZIP sits after the state.
        $this->assertSame(
            '166 Akenside Rd, Riverside, IL 60546',
            GeoapifyService::normalizeSeparators('166 Akenside Rd Riverside IL 60546'),
        );

        // Already comma-delimited: nothing is second-guessed.
        $this->assertSame(
            '5 Oak St, Barrington, IL 60010',
            GeoapifyService::normalizeSeparators('5 Oak St, Barrington, IL 60010'),
        );
    }

    public function test_it_never_mistakes_a_street_suffix_or_directional_for_the_state(): void
    {
        foreach (['960 Danielson Ct', '123 Main St NE', '77 Lakeview Dr OK'] as $address) {
            $this->assertSame($address, GeoapifyService::normalizeSeparators($address));
            $this->assertNull(GeoapifyService::addressAnchor($address));
        }

        $this->assertNull(GeoapifyService::addressAnchor('511 Sherwood Dr'));
    }

    public function test_it_geocodes_a_comma_less_address_as_full_structured_fields(): void
    {
        $this->assertSame(
            ['zip' => null, 'state' => 'IL'],
            GeoapifyService::addressAnchor('166 Akenside rd  Riverside Il'),
        );

        $this->assertSame(
            [
                'housenumber' => '166',
                'street' => 'Akenside rd',
                'country' => 'United States',
                'state' => 'IL',
                'city' => 'Riverside',
            ],
            GeoapifyService::splitForGeocoding('166 Akenside rd  Riverside Il'),
        );
    }
}
