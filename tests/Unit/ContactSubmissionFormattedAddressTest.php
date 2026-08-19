<?php

namespace Tests\Unit;

use App\Models\ContactSubmission;
use Tests\TestCase;

class ContactSubmissionFormattedAddressTest extends TestCase
{
    public function test_it_joins_every_part_when_all_are_known(): void
    {
        $lead = new ContactSubmission([
            'address' => '511 Sherwood Dr',
            'city' => 'Addison',
            'state' => 'IL',
            'zip' => '60101',
        ]);

        $this->assertSame('511 Sherwood Dr, Addison, IL 60101', $lead->formattedAddress());
    }

    public function test_it_degrades_gracefully_when_only_the_street_is_known(): void
    {
        $lead = new ContactSubmission(['address' => '511 Sherwood Dr']);

        $this->assertSame('511 Sherwood Dr', $lead->formattedAddress());
    }

    public function test_it_degrades_gracefully_when_only_the_city_is_known(): void
    {
        $lead = new ContactSubmission(['city' => 'Addison']);

        $this->assertSame('Addison', $lead->formattedAddress());
    }

    public function test_it_is_null_when_nothing_is_known(): void
    {
        $this->assertNull((new ContactSubmission)->formattedAddress());
    }

    public function test_state_and_zip_join_the_city_without_a_stray_comma(): void
    {
        $lead = new ContactSubmission([
            'address' => '511 Sherwood Dr',
            'state' => 'IL',
        ]);

        $this->assertSame('511 Sherwood Dr, IL', $lead->formattedAddress());
    }

    public function test_street_strips_a_city_state_zip_tail(): void
    {
        $lead = new ContactSubmission([
            'address' => '2258 South 8th Avenue, North Riverside, IL 60546',
        ]);

        $this->assertSame('2258 South 8th Avenue', $lead->street());
    }

    public function test_street_trims_a_trailing_period_left_by_the_split(): void
    {
        $lead = new ContactSubmission([
            'address' => '424 Broadview Ave., Highland Park',
        ]);

        $this->assertSame('424 Broadview Ave', $lead->street());
    }

    public function test_street_is_unchanged_when_there_is_nothing_to_strip(): void
    {
        $lead = new ContactSubmission(['address' => '511 Sherwood Dr']);

        $this->assertSame('511 Sherwood Dr', $lead->street());
    }

    public function test_street_is_null_without_an_address(): void
    {
        $this->assertNull((new ContactSubmission)->street());
    }
}
