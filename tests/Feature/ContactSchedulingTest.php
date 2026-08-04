<?php

namespace Tests\Feature;

use App\Livewire\ContactSection;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * When a visitor may book, and in which window.
 *
 * Both rules are enforced server-side as well as in the calendar: the
 * selections arrive as public Livewire properties, so a crafted payload can
 * name any date and any label without the browser ever being involved.
 */
class ContactSchedulingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_offered_windows_are_the_ones_the_crew_works(): void
    {
        $this->assertSame(
            ['Anytime', '7-9 AM', '9-11 AM', '11-1 PM', '1-3 PM'],
            ContactSection::timeSlots(),
        );
    }

    public function test_the_next_three_business_days_are_not_bookable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00')); // a Monday

        foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $tooSoon) {
            $this->assertFalse(ContactSection::isSelectableDate($tooSoon), "{$tooSoon} is inside the lead time");
        }

        $this->assertTrue(ContactSection::isSelectableDate('2026-08-06'));
    }

    public function test_lead_time_counts_business_days_not_calendar_days(): void
    {
        // The case that makes calendar days wrong: a Thursday enquiry must
        // offer Tuesday, not Monday — otherwise the weekend absorbs two of
        // the three days and the crew gets one working day of notice.
        Carbon::setTestNow(Carbon::parse('2026-08-06 09:00')); // a Thursday

        $this->assertFalse(ContactSection::isSelectableDate('2026-08-10'), 'Monday is only one business day away');
        $this->assertTrue(ContactSection::isSelectableDate('2026-08-11'), 'Tuesday is the third business day');
    }

    public function test_weekends_are_never_bookable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00'));

        $this->assertFalse(ContactSection::isSelectableDate('2026-08-08')); // Saturday
        $this->assertFalse(ContactSection::isSelectableDate('2026-08-09')); // Sunday
    }

    public function test_a_crafted_selection_cannot_book_inside_the_lead_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00'));

        $component = \Livewire\Livewire::test(ContactSection::class)
            ->call('toggleTime', '2026-08-04', '7-9 AM');   // tomorrow — never offered

        $this->assertSame([], $component->get('availability'));
    }

    public function test_a_crafted_selection_cannot_invent_a_time_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00'));

        $component = \Livewire\Livewire::test(ContactSection::class)
            ->call('toggleTime', '2026-08-06', '11 PM-1 AM');

        $this->assertSame([], $component->get('availability'));
    }

    public function test_a_valid_selection_still_works(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00'));

        $component = \Livewire\Livewire::test(ContactSection::class)
            ->call('toggleTime', '2026-08-06', '9-11 AM');

        $this->assertSame(
            [['date' => '2026-08-06', 'time' => '9-11 AM']],
            $component->get('availability'),
        );
    }

    public function test_malformed_dates_are_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00'));

        foreach (['', 'tomorrow', '2026-13-45', '06/08/2026', '2026-8-6'] as $bad) {
            $this->assertFalse(ContactSection::isSelectableDate($bad), "rejected: {$bad}");
        }
    }

    public function test_today_is_the_business_day_not_the_utc_day(): void
    {
        // 01:00 UTC Tuesday is 20:00 Monday in Chicago — the evening hours
        // when homeowners actually fill this in. Reckoning on UTC silently
        // withdrew a day from every visitor after 7pm local.
        Carbon::setTestNow(Carbon::parse('2026-08-04 01:00', 'UTC'));

        $this->assertSame('2026-08-06', ContactSection::earliestSelectableDate()->format('Y-m-d'));
        $this->assertTrue(ContactSection::isSelectableDate('2026-08-06'));
    }

    /** @return \Livewire\Features\SupportTesting\Testable */
    private function pick(array $picks)
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Chicago'));
        $c = \Livewire\Livewire::test(ContactSection::class);
        foreach ($picks as [$date, $time]) {
            $c->call('toggleTime', $date, $time);
        }

        return $c;
    }

    public function test_send_is_disabled_until_both_minimums_are_met(): void
    {
        $this->assertFalse($this->pick([])->instance()->canSubmit);

        // Enough windows, but all on one day — the crew still has only one
        // date to work with.
        $this->assertFalse($this->pick([
            ['2026-08-06', '7-9 AM'], ['2026-08-06', '9-11 AM'], ['2026-08-06', '11-1 PM'],
        ])->instance()->canSubmit);

        // Two days, but only two windows.
        $this->assertFalse($this->pick([
            ['2026-08-06', '9-11 AM'], ['2026-08-07', '9-11 AM'],
        ])->instance()->canSubmit);

        // Three windows across two days.
        $this->assertTrue($this->pick([
            ['2026-08-06', '7-9 AM'], ['2026-08-06', '9-11 AM'], ['2026-08-07', '1-3 PM'],
        ])->instance()->canSubmit);
    }

    public function test_anytime_counts_double(): void
    {
        // A whole free day plus one window is 3 by weight, so it qualifies —
        // otherwise offering two entire days would be rejected while three
        // narrow windows passed, which is backwards.
        $c = $this->pick([['2026-08-06', 'Anytime'], ['2026-08-07', '9-11 AM']]);

        $this->assertSame(3, $c->get('selectedWeight'));
        $this->assertSame(2, $c->get('selectedDayCount'));
        $this->assertTrue($c->instance()->canSubmit);

        $this->assertSame(2, ContactSection::slotWeight('Anytime'));
        $this->assertSame(1, ContactSection::slotWeight('9-11 AM'));
    }

    public function test_two_anytime_days_are_enough(): void
    {
        $this->assertTrue($this->pick([
            ['2026-08-06', 'Anytime'], ['2026-08-07', 'Anytime'],
        ])->instance()->canSubmit);
    }

    public function test_submitting_below_the_minimum_is_refused_server_side(): void
    {
        // The disabled button lives in the browser; submit() can be called
        // directly over the wire.
        $this->pick([['2026-08-06', '9-11 AM']])
            ->set('name', 'Test Person')
            ->set('email', 'test@example.com')
            ->set('phone', '(224) 555-0123')
            ->set('address', '123 Example Street')
            ->set('message', 'We would like a quote for our kitchen remodel please.')
            ->call('submit')
            ->assertHasErrors('availability');
    }

    public function test_the_minimums_match_the_crew_scheduler(): void
    {
        // hive's PickTimes calls these "the website's ask" — if either side
        // moves, the two ends of the same conversation disagree.
        $this->assertSame(2, ContactSection::MIN_DAYS);
        $this->assertSame(3, ContactSection::MIN_TIMES);
    }
}
