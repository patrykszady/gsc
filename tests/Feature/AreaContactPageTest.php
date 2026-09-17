<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Support\OfficeTrip;
use App\Support\PermitGuideInfo;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A town's contact page carries that town's own booking facts — the drive
 * from the office, hours, what its building department asks for, the
 * neighbours on the route — instead of repeating the town page. Google had
 * crawled 34 of the old ones and indexed none (2026-09-17).
 */
class AreaContactPageTest extends TestCase
{
    private const INTRO = 'Unique local copy about the town that belongs on the town page only. ';

    private function area(string $city, string $slug, float $lat, float $lng): AreaServed
    {
        return AreaServed::create([
            'city' => $city, 'slug' => $slug, 'state' => 'IL', 'latitude' => $lat, 'longitude' => $lng,
            'local_intro' => str_repeat(self::INTRO, 20),
            'landmarks' => 'The old water tower and the Metra platform',
        ]);
    }

    public function test_office_trip_scales_the_straight_line_to_road_miles_and_a_time_band(): void
    {
        config(['brand.address.lat' => 42.102847, 'brand.address.lng' => -87.9275628, 'brand.address.city' => 'Prospect Heights', 'brand.address.state' => 'IL']);
        $barrington = $this->area('Barrington', 'barrington', 42.1539141, -88.1361888);

        $trip = OfficeTrip::to($barrington);
        $this->assertSame(11.3, $trip['straight_miles']);
        $this->assertSame(15, $trip['miles'], '11.3 straight × 1.3 road factor');
        $this->assertSame([25, 40], [$trip['minutes_low'], $trip['minutes_high']]);
        $this->assertSame('Prospect Heights, IL', $trip['from']);

        // The office's own town: no "drive", a floor of 5–15 minutes.
        $home = $this->area('Prospect Heights', 'prospect-heights', 42.0953, -87.9373);
        $this->assertSame([1, 5, 15], [OfficeTrip::to($home)['miles'], OfficeTrip::to($home)['minutes_low'], OfficeTrip::to($home)['minutes_high']]);

        $this->assertNull(OfficeTrip::to(AreaServed::create(['city' => 'Nowhere', 'slug' => 'nowhere'])), 'no coordinates, no trip');
        $this->assertSame('Mon–Sat 8:00 AM–6:00 PM', OfficeTrip::hoursLine());
    }

    public function test_contact_page_carries_the_towns_own_booking_facts_and_not_the_town_pages_copy(): void
    {
        Cache::flush();
        config(['brand.address.lat' => 42.102847, 'brand.address.lng' => -87.9275628, 'brand.address.city' => 'Prospect Heights']);
        $this->area('Barrington', 'barrington', 42.1539141, -88.1361888);
        $this->area('Lake Zurich', 'lake-zurich', 42.1970, -88.0934);
        $this->area('Inverness', 'inverness', 42.1181, -88.0962);
        config(['permit-guides' => ['barrington' => [
            'town' => 'Barrington',
            'review_time' => 'Approximately 8-10 business days for a standard permit to be "reviewed, processed and issued"; large remodels will likely exceed this estimate.',
            'contractor_registration' => 'Required: "All contractors and subcontractors associated with your permit must be registered with the Village." Registration is annual.',
            'inspections' => 'Inspections occur at various stages of a project. Scheduling: call the village at least 24 hours in advance.',
            'notable_quirks' => ['1) Historic Overlay District: all exterior modifications require Architectural Review Commission approval. 2) Something else.'],
        ]]]);
        PermitGuideInfo::bust();

        $page = $this->get('/areas-served/barrington/contact')->assertOk();
        $page->assertDontSee('noindex', false);
        $page->assertSee('Booking a consultation in Barrington, IL');
        $page->assertSee('About 15 miles');
        $page->assertSee('25–40 min from Prospect Heights');
        $page->assertSee('Mon–Sat 8:00 AM–6:00 PM');
        // The village's own rules, one sentence each, quotes stripped, numbering dropped.
        $page->assertSee('Approximately 8-10 business days for a standard permit to be reviewed, processed and issued.');
        $page->assertSee('All contractors and subcontractors associated with your permit must be registered with the Village.');
        $page->assertSee('Historic Overlay District: all exterior modifications require Architectural Review Commission approval.');
        $page->assertDontSee('2) Something else');
        $page->assertSee('/permits/barrington');
        // Neighbours on the route link to their own contact pages, nearest first.
        $page->assertSeeInOrder(['Inverness', 'Lake Zurich']);
        $page->assertSee('/areas-served/inverness/contact');
        // The booking FAQ answers with this town's figures.
        $page->assertSee('How soon can you come out to Barrington?');
        $page->assertSee('Barrington is about 25–40 minutes from our Prospect Heights office');
        // What the town page says stays on the town page.
        $page->assertDontSee(trim(self::INTRO));
        $page->assertDontSee('The old water tower');
        // The meta description is this town's, not the shared sentence with the city swapped in.
        $page->assertSee('about 25–40 minutes from our Prospect Heights office. Call', false);
    }

    public function test_a_town_without_a_permit_guide_gets_the_general_permit_line_and_the_guides_index(): void
    {
        Cache::flush();
        config(['permit-guides' => []]);
        PermitGuideInfo::bust();
        $this->area('Kenilworth', 'kenilworth', 42.0859, -87.7176);

        $page = $this->get('/areas-served/kenilworth/contact')->assertOk();
        $page->assertSee('Permits in Kenilworth');
        $page->assertSee('The Kenilworth building department reviews remodeling permits before work starts');
        $page->assertSee('/permits"', false);
        $page->assertDontSee('/permits/kenilworth');
        $page->assertSee('Will you handle the Kenilworth permit?');
    }

    public function test_contact_pages_close_again_with_the_flag(): void
    {
        Cache::flush();
        config(['seo.area_index_contact_pages' => false]);
        $this->area('Kenilworth', 'kenilworth', 42.0859, -87.7176);
        $this->get('/areas-served/kenilworth/contact')->assertOk()->assertSee('noindex', false);
    }
}
