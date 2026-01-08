<?php

namespace App\Livewire;

use App\Mail\ContactFormSubmission;
use App\Models\AreaServed;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ContactSection extends Component
{
    public ?AreaServed $area = null;

    #[Validate('required|min:2|max:100')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    // Masked phone input (e.g. "(555) 123-4567")
    public string $phone = '';

    // Digits-only phone for validation / processing
    #[Validate('required|numeric|digits:10')]
    public string $phoneDigits = '';

    #[Validate('nullable|string|max:500')]
    public string $address = '';

    #[Validate('required|min:10|max:2000')]
    public string $message = '';

    // Honeypot field - should remain empty (bots will fill it)
    public string $website = '';

    // Timestamp when form was loaded (for time-based protection)
    public int $formLoadedAt = 0;

    // Availability - dynamic array of date/time selections
    public array $availability = [];

    // Selected dates from Flux calendar (multiple mode)
    public array $selectedDates = [];

    // Currently selected date for time picking
    public ?string $selectedDateForTimes = null;

    // Times selected per date
    public array $timeSelections = [];

    public function mount(): void
    {
        $this->formLoadedAt = time();
    }

    public function updatedPhone(?string $value): void
    {
        $this->phoneDigits = preg_replace('/\D+/', '', $value ?? '');
    }

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
        // Ensure digits-only phone is always in sync (covers defer/blur updates)
        $this->phoneDigits = preg_replace('/\D+/', '', $this->phone);

        // Spam protection checks
        $spamReason = $this->detectSpam();
        if ($spamReason) {
            \Log::warning('Spam submission blocked', [
                'reason' => $spamReason,
                'ip' => request()->ip(),
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phoneDigits,
            ]);
            
            // Show generic success message to not alert spammers
            session()->flash('success', 'Thank you for your message! We\'ll get back to you soon.');
            $this->reset(['name', 'email', 'phone', 'phoneDigits', 'address', 'message', 'website', 'availability', 'selectedDates', 'selectedDateForTimes', 'timeSelections']);
            return;
        }

        // Rate limiting: 3 submissions per IP per hour
        $rateLimitKey = 'contact-form:' . request()->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $this->addError('form', "Too many submissions. Please try again in {$seconds} seconds.");
            return;
        }
        RateLimiter::hit($rateLimitKey, 3600); // 1 hour decay

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

        // Log the contact form submission
        \Log::info('Contact form submitted', [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phoneDigits,
            'address' => $this->address,
        ]);
        
        session()->flash('success', 'Thank you for your message! We\'ll get back to you soon.');

        // Dispatch browser event for analytics tracking
        $this->dispatch('contact-form-submitted');

        $this->reset(['name', 'email', 'phone', 'phoneDigits', 'address', 'message', 'website', 'availability', 'selectedDates', 'selectedDateForTimes', 'timeSelections']);
    }

    /**
     * Detect spam submissions using multiple heuristics.
     * Returns the spam reason if detected, null if legitimate.
     */
    protected function detectSpam(): ?string
    {
        // 1. Honeypot check - if filled, it's a bot
        if (!empty($this->website)) {
            return 'honeypot_filled';
        }

        // 2. Time-based check - form submitted too quickly (less than 3 seconds)
        $timeTaken = time() - $this->formLoadedAt;
        if ($timeTaken < 3) {
            return 'submitted_too_fast';
        }

        // 3. Gibberish detection - check for random character patterns
        if ($this->containsGibberish($this->name) || $this->containsGibberish($this->message)) {
            return 'gibberish_detected';
        }

        // 4. Suspicious email patterns
        if ($this->isSuspiciousEmail($this->email)) {
            return 'suspicious_email';
        }

        // 5. Check for common spam keywords
        $spamKeywords = ['viagra', 'cialis', 'casino', 'lottery', 'bitcoin', 'crypto', 'investment opportunity', 'make money fast'];
        $content = strtolower($this->name . ' ' . $this->message);
        foreach ($spamKeywords as $keyword) {
            if (str_contains($content, $keyword)) {
                return 'spam_keyword: ' . $keyword;
            }
        }

        // 6. All caps check (more than 50% uppercase in long messages)
        if (strlen($this->message) > 20) {
            $uppercaseCount = preg_match_all('/[A-Z]/', $this->message);
            $letterCount = preg_match_all('/[a-zA-Z]/', $this->message);
            if ($letterCount > 0 && ($uppercaseCount / $letterCount) > 0.5) {
                return 'excessive_caps';
            }
        }

        return null;
    }

    /**
     * Check if a string contains gibberish (random characters).
     */
    protected function containsGibberish(string $text): bool
    {
        if (strlen($text) < 10) {
            return false;
        }

        // Check consonant-to-vowel ratio (gibberish often has unusual ratios)
        $vowels = preg_match_all('/[aeiouAEIOU]/', $text);
        $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]/', $text);
        
        if ($consonants > 0 && $vowels > 0) {
            $ratio = $consonants / $vowels;
            // Normal English has ~1.5-2 consonants per vowel, gibberish often has 3+
            if ($ratio > 4) {
                return true;
            }
        }

        // Check for sequences of uppercase/lowercase mixing (like "uBCmAHXTQBjRSGizvtTKfyL")
        if (preg_match('/[A-Z][a-z][A-Z][a-z][A-Z]/', $text) || preg_match('/[a-z][A-Z][a-z][A-Z][a-z]/', $text)) {
            // Count the total alternations
            $alternations = preg_match_all('/[A-Z][a-z]|[a-z][A-Z]/', $text);
            if ($alternations > 5) {
                return true;
            }
        }

        // Check for long strings without spaces (bots often omit spaces)
        if (preg_match('/\S{25,}/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Check for suspicious email patterns.
     */
    protected function isSuspiciousEmail(string $email): bool
    {
        // Check for random-looking local part (like "osolu.w.i.ci41@")
        $localPart = explode('@', $email)[0] ?? '';
        
        // Multiple dots in local part is suspicious (like "a.b.c.d@")
        if (substr_count($localPart, '.') >= 3) {
            return true;
        }

        // Random number suffix pattern (like "user123456@")
        if (preg_match('/\d{4,}@/', $email)) {
            return true;
        }

        // Very short random local part with numbers
        if (preg_match('/^[a-z]{1,3}\d{2,}@/i', $email)) {
            return true;
        }

        return false;
    }

    public function render()
    {
        $areasServed = AreaServed::orderBy('city')->pluck('city')->toArray();

        // Get unavailable Sundays for calendar
        $unavailableSundays = $this->getUnavailableSundays();

        // Flux calendar expects a Y-m-d date string ("today" shorthand exists, but "tomorrow" does not)
        $minSelectableDate = now()->addDay()->format('Y-m-d');
        
        // Available time slots
        $times = ['8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM'];
        
        return view('livewire.contact-section', [
            'areasServed' => $areasServed,
            'unavailableSundays' => $unavailableSundays,
            'minSelectableDate' => $minSelectableDate,
            'times' => $times,
            'area' => $this->area,
        ]);
    }
}
