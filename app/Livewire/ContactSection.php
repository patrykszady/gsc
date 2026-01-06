<?php

namespace App\Livewire;

use App\Mail\ContactFormSubmission;
use App\Models\AreaServed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ContactSection extends Component
{
    public ?AreaServed $area = null;

    #[Validate('required|min:2')]
    public string $name = '';

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $phone = '';

    #[Validate('nullable|string')]
    public string $address = '';

    #[Validate('required|min:10')]
    public string $message = '';

    // Availability - dynamic array of date/time selections
    public array $availability = [];

    // Selected dates from Flux calendar (multiple mode)
    public array $selectedDates = [];

    // Currently selected date for time picking
    public ?string $selectedDateForTimes = null;

    // Times selected per date
    public array $timeSelections = [];

    public function selectDateForTimes(string $date): void
    {
        $this->selectedDateForTimes = $date;
    }

    public function toggleTime(string $date, string $time): void
    {
        if (!isset($this->timeSelections[$date])) {
            $this->timeSelections[$date] = [];
        }

        $idx = array_search($time, $this->timeSelections[$date]);
        if ($idx !== false) {
            unset($this->timeSelections[$date][$idx]);
            $this->timeSelections[$date] = array_values($this->timeSelections[$date]);
        } else {
            $this->timeSelections[$date][] = $time;
        }

        // Remove empty entries
        if (empty($this->timeSelections[$date])) {
            unset($this->timeSelections[$date]);
        }

        $this->syncSelectedDatesFromTimes();
        $this->syncAvailability();
    }

    public function removeTimeSelection(string $date, string $time): void
    {
        if (isset($this->timeSelections[$date])) {
            $idx = array_search($time, $this->timeSelections[$date]);
            if ($idx !== false) {
                unset($this->timeSelections[$date][$idx]);
                $this->timeSelections[$date] = array_values($this->timeSelections[$date]);
            }
            if (empty($this->timeSelections[$date])) {
                unset($this->timeSelections[$date]);
            }
        }
        $this->syncSelectedDatesFromTimes();
        $this->syncAvailability();
    }

    protected function syncSelectedDatesFromTimes(): void
    {
        $this->selectedDates = array_values(array_unique(array_keys($this->timeSelections)));
        sort($this->selectedDates);
    }

    protected function syncAvailability(): void
    {
        $result = [];
        foreach ($this->timeSelections as $date => $times) {
            foreach ($times as $time) {
                $result[] = ['date' => $date, 'time' => $time];
            }
        }
        $this->availability = $result;
    }

    protected function getUnavailableSundays(): string
    {
        // Get Sundays for the next 2 months
        $sundays = [];
        $start = now()->addDay();
        $end = now()->addMonths(2);

        while ($start <= $end) {
            if ($start->dayOfWeek === 0) { // Sunday
                $sundays[] = $start->format('Y-m-d');
            }
            $start->addDay();
        }

        return implode(',', $sundays);
    }

    public function submit(): void
    {
        $this->validate();

        // Send email notification
        Mail::to(config('mail.from.address'))->send(new ContactFormSubmission(
            name: $this->name,
            email: $this->email,
            phone: $this->phone,
            address: $this->address,
            userMessage: $this->message,
            availability: $this->availability,
        ));
        
        session()->flash('success', 'Thank you for your message! We\'ll get back to you soon.');

        $this->reset(['name', 'email', 'phone', 'address', 'message', 'availability', 'selectedDates', 'selectedDateForTimes', 'timeSelections']);
    }

    protected function getUserCity(): ?string
    {
        $ip = request()->ip();
        
        // Don't geolocate localhost/private IPs
        if (in_array($ip, ['127.0.0.1', '::1']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return null;
        }

        // Cache the result for 24 hours per IP
        return Cache::remember("geo_city_{$ip}", 86400, function () use ($ip) {
            try {
                $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}?fields=city,regionName");
                
                if ($response->successful()) {
                    $data = $response->json();
                    return $data['city'] ?? null;
                }
            } catch (\Exception $e) {
                // Silently fail - geolocation is nice-to-have
            }
            
            return null;
        });
    }

    public function render()
    {
        $areasServed = AreaServed::orderBy('city')->pluck('city')->toArray();
        
        // Get user's city from IP
        $userCity = $this->getUserCity();
        $detectedCity = null;
        
        // Check if user's city is in our served areas
        if ($userCity) {
            foreach ($areasServed as $city) {
                if (strcasecmp($city, $userCity) === 0) {
                    $detectedCity = $city;
                    break;
                }
            }
        }

        // Get unavailable Sundays for calendar
        $unavailableSundays = $this->getUnavailableSundays();

        // Flux calendar expects a Y-m-d date string ("today" shorthand exists, but "tomorrow" does not)
        $minSelectableDate = now()->addDay()->format('Y-m-d');
        
        // Available time slots
        $times = ['8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM'];
        
        return view('livewire.contact-section', [
            'areasServed' => $areasServed,
            'detectedCity' => $detectedCity,
            'unavailableSundays' => $unavailableSundays,
            'minSelectableDate' => $minSelectableDate,
            'times' => $times,
            'area' => $this->area,
        ]);
    }
}
