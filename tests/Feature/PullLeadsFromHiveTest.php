<?php

namespace Tests\Feature;

use App\Models\ContactSubmission;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mirroring hive leads into this site's leads admin.
 *
 * The case that matters most is the collision guard. Rows created by the
 * website carry a `hive_lead_id` too — assigned when SendLeadToHive pushed
 * them — so matching a mirrored lead on that id alone lets it overwrite an
 * unrelated customer's submission. That is not theoretical: it destroyed two
 * real rows during development before the source was added to the key.
 */
class PullLeadsFromHiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.hive.url', 'https://hive.test');
        Config::set('services.hive.token', 'test-token');
    }

    private function fakeHiveReturns(array $leads): void
    {
        Http::fake([
            'hive.test/api/v1/leads*' => Http::response(['data' => $leads], 200),
        ]);
    }

    private function lead(int $id, array $data = []): array
    {
        return [
            'id' => $id,
            'external_source' => 'crew-email',
            'origin' => 'Email',
            'date' => '2026-08-01T20:51:49+00:00',
            'notes' => 'Basement — summary',
            'lead_data' => array_merge([
                'name' => 'Rob Rothbaum',
                'email' => 'robert.rothbaum@gmail.com',
                'address' => '960 Danielson Ct',
                'city' => 'Gurnee',
                'message' => 'Full email body here.',
            ], $data),
        ];
    }

    public function test_it_mirrors_a_lead_into_the_leads_admin(): void
    {
        $this->fakeHiveReturns([$this->lead(139)]);

        $this->artisan('leads:pull-from-hive --source=crew-email')->assertSuccessful();

        $row = ContactSubmission::where('source', 'crew-email')->firstOrFail();
        $this->assertSame('Rob Rothbaum', $row->name);
        $this->assertSame('Gurnee', $row->city);
        $this->assertSame(139, $row->hive_lead_id);
        // The full email, not the summary.
        $this->assertSame('Full email body here.', $row->message);
    }

    public function test_it_does_not_overwrite_a_web_submission_sharing_a_hive_lead_id(): void
    {
        $web = ContactSubmission::create([
            'name' => 'Jon Green',
            'email' => 'green2847@comcast.net',
            'phone' => '8474361014',
            'message' => 'Kitchen quote please',
            'source' => 'web',
            'hive_lead_id' => 139,
        ]);

        $this->fakeHiveReturns([$this->lead(139)]);

        $this->artisan('leads:pull-from-hive --source=crew-email')->assertSuccessful();

        $web->refresh();
        $this->assertSame('Jon Green', $web->name, 'a web submission must never be overwritten by a mirror');
        $this->assertSame('web', $web->source);

        $this->assertSame(1, ContactSubmission::where('source', 'crew-email')->count());
        $this->assertSame(2, ContactSubmission::count());
    }

    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->fakeHiveReturns([$this->lead(139), $this->lead(140, ['name' => 'Amy Dusto'])]);

        $this->artisan('leads:pull-from-hive --source=crew-email')->assertSuccessful();
        $this->artisan('leads:pull-from-hive --source=crew-email')->assertSuccessful();

        $this->assertSame(2, ContactSubmission::where('source', 'crew-email')->count());
    }

    public function test_a_lead_without_a_phone_is_stored(): void
    {
        // The enquiry that prompted this feature has no phone number at all.
        $this->fakeHiveReturns([$this->lead(139)]);

        $this->artisan('leads:pull-from-hive --source=crew-email')->assertSuccessful();

        $this->assertNull(ContactSubmission::where('source', 'crew-email')->firstOrFail()->phone);
    }

    public function test_it_files_the_lead_at_the_time_it_was_received(): void
    {
        // The admin sorts by created_at; stamping now() would file a week-old
        // enquiry at the top as though it had just arrived.
        $this->fakeHiveReturns([$this->lead(139)]);

        $this->artisan('leads:pull-from-hive --source=crew-email')->assertSuccessful();

        $this->assertSame(
            '2026-08-01',
            ContactSubmission::where('source', 'crew-email')->firstOrFail()->created_at->toDateString(),
        );
    }
}
