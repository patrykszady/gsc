<?php

namespace App\Livewire;

use App\Mail\ContactFormSubmission;
use App\Models\AreaServed;
use Illuminate\Support\Facades\Http;
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

    // Cloudflare Turnstile token
    public string $turnstileToken = '';

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

        // VALIDATION FIRST - always show real validation errors to users
        $this->validate();

        // Rate limiting: 3 submissions per IP per hour
        $rateLimitKey = 'contact-form:' . request()->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $this->addError('form', "Too many submissions. Please try again in {$seconds} seconds.");
            return;
        }
        RateLimiter::hit($rateLimitKey, 3600); // 1 hour decay

        // Spam protection checks (after validation passes)
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
            $this->reset(['name', 'email', 'phone', 'phoneDigits', 'address', 'message', 'website', 'turnstileToken', 'availability', 'selectedDates', 'selectedDateForTimes', 'timeSelections']);
            return;
        }

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

        $this->reset(['name', 'email', 'phone', 'phoneDigits', 'address', 'message', 'website', 'turnstileToken', 'availability', 'selectedDates', 'selectedDateForTimes', 'timeSelections']);
    }

    /**
     * Detect spam submissions using multiple heuristics.
     * Returns the spam reason if detected, null if legitimate.
     */
    protected function detectSpam(): ?string
    {
        // 0. Turnstile verification (if enabled)
        if (config('services.turnstile.enabled') && config('services.turnstile.secret_key')) {
            if (empty($this->turnstileToken)) {
                return 'turnstile_missing';
            }
            
            if (!$this->verifyTurnstile()) {
                return 'turnstile_failed';
            }
        }

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
        $spamKeywords = [
            // Classic spam
            'viagra', 'cialis', 'casino', 'lottery', 'bitcoin', 'crypto', 
            'investment opportunity', 'make money fast', 'click here', 'act now', 
            'limited time', 'congratulations', 'winner', 'free money', 
            'nigerian prince', 'inheritance',
            // SEO/Marketing spam (like "Daniel Wright" email)
            'seo services', 'web traffic', 'backlinks', 'google ranking',
            'first page', 'search engine', 'digital marketing', 'lead generation',
            'show you a demo', 'quick demo', 'free demo', 'schedule a demo',
            'grow your business', 'boost your', 'increase your sales',
            'live within 24 hours', 'within 24 hours', '24 hours',
            'people searching for what you sell', 'in front of',
            'qualified leads', 'potential customers', 'target audience',
            // Web design/dev spam
            'redesign your website', 'website redesign', 'new website',
            'mobile friendly', 'web development services',
            // Generic sales pitch patterns
            'would you like me to', 'interested in learning more',
            'free consultation', 'no obligation', 'risk free',
        ];
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

        // 7. Excessive URLs in message (spam often contains multiple links)
        $urlCount = preg_match_all('/https?:\/\/|www\./i', $this->message);
        if ($urlCount >= 2) {
            return 'excessive_urls';
        }

        // 8. Disposable email domain check
        if ($this->isDisposableEmail($this->email)) {
            return 'disposable_email';
        }

        // 9. Marketing/spam email domain check
        if ($this->isSpamEmailDomain($this->email)) {
            return 'spam_email_domain';
        }

        // 10. Message doesn't mention remodeling-related terms (likely not a real inquiry)
        if (!$this->mentionsRemodelingTopics($this->message)) {
            return 'no_remodeling_context';
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

    /**
     * Check if email is from a disposable/temporary email service.
     */
    protected function isDisposableEmail(string $email): bool
    {
        $disposableDomains = [
            'tempmail.com', 'temp-mail.org', 'guerrillamail.com', 'guerrillamail.net',
            'mailinator.com', '10minutemail.com', 'throwaway.email', 'fakeinbox.com',
            'yopmail.com', 'sharklasers.com', 'trashmail.com', 'maildrop.cc',
            'getnada.com', 'mailnesia.com', 'tempail.com', 'dispostable.com',
            'mintemail.com', 'mohmal.com', 'emailondeck.com', 'temp.email',
            'tempr.email', 'discard.email', 'throwawaymail.com', 'fakemailgenerator.com',
            'tempinbox.com', 'tempmailaddress.com', 'disposableemailaddresses.com',
            'spamgourmet.com', 'mytrashmail.com', 'mailcatch.com', 'spamdecoy.net',
            'jetable.org', 'kasmail.com', 'crazymailing.com', 'filzmail.com',
            'safetymail.info', 'inboxalias.com', 'spamobox.com', 'nwldx.com',
            'mailforspam.com', 'spambox.us', 'tempmailgen.com', 'burnermail.io',
        ];

        $domain = strtolower(explode('@', $email)[1] ?? '');
        
        return in_array($domain, $disposableDomains);
    }

    /**
     * Check if email is from a known spam/marketing email domain.
     * These are domains used by spammers for outreach campaigns.
     */
    protected function isSpamEmailDomain(string $email): bool
    {
        $spamDomains = [
            // Known spam/marketing domains
            'jmailservice.com', // The "Daniel Wright" spam
            'mailservice.com', 'emailservice.com', 'fastmail.services',
            'businessmail.com', 'promail.services', 'mailpro.services',
            'leadgen.email', 'outreach.email', 'coldmail.email',
            'marketingmail.com', 'salesmail.com', 'prospectmail.com',
            // Generic fake-looking domains (pattern: [word]service.com)
        ];

        $domain = strtolower(explode('@', $email)[1] ?? '');
        
        // Direct match
        if (in_array($domain, $spamDomains)) {
            return true;
        }
        
        // Pattern match: domains with "service", "mail", "lead", "outreach" in them
        // but not legitimate providers like gmail, hotmail, etc.
        $legitimateMailProviders = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'aol.com', 'icloud.com', 'protonmail.com', 'fastmail.com', 'zoho.com'];
        if (!in_array($domain, $legitimateMailProviders)) {
            if (preg_match('/(jmail|mailservice|emailservice|leadgen|outreach|coldmail|prospecting)/i', $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the message mentions remodeling-related topics.
     * Real inquiries typically mention kitchens, bathrooms, renovations, etc.
     */
    protected function mentionsRemodelingTopics(string $message): bool
    {
        $message = strtolower($message);
        
        $remodelingKeywords = [
            // Rooms/areas
            'kitchen', 'bathroom', 'bath', 'basement', 'bedroom', 'living room',
            'dining room', 'garage', 'attic', 'laundry', 'mudroom', 'closet',
            'master', 'guest', 'powder room', 'half bath', 'full bath',
            // Project types
            'remodel', 'renovation', 'renovate', 'addition', 'extension',
            'update', 'upgrade', 'refresh', 'makeover', 'redo', 'gut',
            'restore', 'transform', 'convert', 'finish', 'unfinish',
            // Specific work
            'cabinets', 'countertop', 'counters', 'flooring', 'tile', 'tiles',
            'backsplash', 'sink', 'faucet', 'shower', 'tub', 'bathtub', 
            'toilet', 'vanity', 'mirror', 'lighting', 'fixtures',
            'appliances', 'island', 'pantry', 'storage',
            'drywall', 'paint', 'painting', 'trim', 'molding', 'crown',
            'windows', 'doors', 'roof', 'siding', 'deck', 'patio', 'porch',
            // Materials
            'granite', 'quartz', 'marble', 'hardwood', 'laminate', 'vinyl',
            'ceramic', 'porcelain', 'stone', 'brick', 'wood', 'stainless',
            // Intent signals
            'quote', 'estimate', 'bid', 'cost', 'price', 'budget',
            'interested', 'looking', 'need', 'want', 'help', 'project',
            'home', 'house', 'condo', 'apartment', 'property',
            'contractor', 'builder', 'work', 'job',
            // Timeline
            'soon', 'asap', 'months', 'weeks', 'spring', 'summer', 'fall', 'winter',
            // Questions about the company
            'available', 'schedule', 'appointment', 'meet', 'visit', 'see',
            'portfolio', 'references', 'licensed', 'insured', 'warranty',
        ];

        foreach ($remodelingKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify Cloudflare Turnstile token.
     */
    protected function verifyTurnstile(): bool
    {
        try {
            $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $this->turnstileToken,
                'remoteip' => request()->ip(),
            ]);

            $result = $response->json();
            
            if (!($result['success'] ?? false)) {
                \Log::warning('Turnstile verification failed', [
                    'error-codes' => $result['error-codes'] ?? [],
                    'ip' => request()->ip(),
                    'hostname' => $result['hostname'] ?? null,
                    'name' => $this->name,
                    'email' => $this->email,
                    'phone' => $this->phoneDigits,
                    'address' => $this->address,
                    'message' => \Str::limit($this->message, 200),
                ]);
                return false;
            }

            \Log::debug('Turnstile verification passed', [
                'ip' => request()->ip(),
                'hostname' => $result['hostname'] ?? null,
                'email' => $this->email,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error('Turnstile verification error', [
                'error' => $e->getMessage(),
            ]);
            // Allow form submission if Turnstile API fails (graceful degradation)
            return true;
        }
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
            'turnstileSiteKey' => config('services.turnstile.site_key'),
            'turnstileEnabled' => config('services.turnstile.enabled') && config('services.turnstile.secret_key'),
        ]);
    }
}
