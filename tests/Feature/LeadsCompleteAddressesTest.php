<?php

namespace Tests\Feature;

use App\Models\ContactSubmission;
use App\Services\LeadAddressCompleter;
use Mockery;
use Tests\TestCase;

class LeadsCompleteAddressesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockCompleter(): Mockery\MockInterface
    {
        $mock = Mockery::mock(LeadAddressCompleter::class);
        $this->app->instance(LeadAddressCompleter::class, $mock);

        return $mock;
    }

    private function incompleteLead(array $overrides = []): ContactSubmission
    {
        return ContactSubmission::create(array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '5551212',
            'address' => '511 Sherwood Dr',
            'message' => 'Quote please',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_dry_run_calls_the_completer_but_never_saves(): void
    {
        $lead = $this->incompleteLead();

        $this->mockCompleter()
            ->shouldReceive('complete')
            ->once()
            ->andReturn([
                'address' => '511 Sherwood Dr',
                'city' => 'Addison',
                'state' => 'IL',
                'zip' => '60101',
            ]);

        $this->artisan('leads:complete-addresses --dry-run --limit=3')->assertSuccessful();

        $this->assertNull($lead->fresh()->city, 'dry-run must not write to the database');
    }

    public function test_a_real_run_persists_the_completed_parts(): void
    {
        $lead = $this->incompleteLead();

        $this->mockCompleter()
            ->shouldReceive('complete')
            ->once()
            ->andReturn([
                'address' => '511 Sherwood Dr',
                'city' => 'Addison',
                'state' => 'IL',
                'zip' => '60101',
            ]);

        $this->artisan('leads:complete-addresses --limit=1 --sleep=0')->assertSuccessful();

        $fresh = $lead->fresh();
        $this->assertSame('Addison', $fresh->city);
        $this->assertSame('IL', $fresh->state);
        $this->assertSame('60101', $fresh->zip);
    }

    public function test_leads_that_are_already_complete_are_skipped(): void
    {
        $this->incompleteLead([
            'email' => 'complete@example.com',
            'city' => 'Addison',
            'state' => 'IL',
            'zip' => '60101',
        ]);

        $this->mockCompleter()->shouldNotReceive('complete');

        $this->artisan('leads:complete-addresses --sleep=0')->assertSuccessful();
    }

    public function test_a_failure_on_one_lead_does_not_stop_the_batch(): void
    {
        $bad = $this->incompleteLead(['email' => 'bad@example.com']);
        $good = $this->incompleteLead(['email' => 'good@example.com', 'address' => '5 Oak St']);

        $mock = $this->mockCompleter();
        $mock->shouldReceive('complete')
            ->with(Mockery::on(fn ($data) => $data['address'] === '511 Sherwood Dr'))
            ->once()
            ->andThrow(new \RuntimeException('Geoapify unreachable'));
        $mock->shouldReceive('complete')
            ->with(Mockery::on(fn ($data) => $data['address'] === '5 Oak St'))
            ->once()
            ->andReturn(['address' => '5 Oak St', 'city' => 'Barrington', 'state' => 'IL', 'zip' => '60010']);

        $this->artisan('leads:complete-addresses --sleep=0')->assertSuccessful();

        $this->assertNull($bad->fresh()->city);
        $this->assertSame('Barrington', $good->fresh()->city);
    }
}
