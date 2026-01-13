<?php

namespace App\Livewire;

use App\Mail\ContactFormSubmission;
use App\Models\AreaServed;
use App\Models\ContactSubmission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="relative bg-white dark:bg-gray-900">
            <div class="mx-auto grid max-w-7xl grid-cols-1 lg:grid-cols-2">
                <div class="relative px-6 py-8 sm:py-10 lg:px-8 lg:py-12">
                    <div class="mx-auto max-w-xl lg:mx-0 lg:max-w-lg space-y-4">
                        <div class="aspect-[4/3] max-w-md bg-zinc-200 dark:bg-zinc-700 rounded-xl animate-pulse"></div>
                        <div class="h-12 w-3/4 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                        <div class="h-6 w-full bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                        <div class="space-y-3 pt-4">
                            <div class="h-5 w-48 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                            <div class="h-5 w-56 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                            <div class="h-5 w-44 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-8 sm:py-10 lg:px-8 lg:py-12">
                    <div class="mx-auto max-w-xl lg:max-w-lg space-y-4">
                        <div class="h-12 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                        <div class="h-12 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                        <div class="h-12 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                        <div class="h-32 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse"></div>
                        <div class="h-12 w-1/3 bg-sky-200 dark:bg-sky-800 rounded animate-pulse"></div>
                    </div>
                </div>
            </div>
        </section>
        HTML;
    }

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
            Log::channel('submissions')->warning('Spam submission blocked', [
                'reason' => $spamReason,
                'ip' => request()->ip(),
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phoneDigits,
                'address' => $this->address,
                'message' => $this->message,
                'availability' => $this->availability,
                'time_taken' => time() - $this->formLoadedAt,
            ]);
            
            // Store spam submission for review
            $this->storeSubmission('spam', $spamReason);
            
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

        // Store submission in database (independent of GA/email)
        $this->storeSubmission();

        // Log the contact form submission with full details
        Log::channel('submissions')->info('Contact form submitted', [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phoneDigits,
            'address' => $this->address,
            'message' => $this->message,
            'availability' => $this->availability,
            'area' => $this->area?->city,
            'ip' => request()->ip(),
            'time_taken' => time() - $this->formLoadedAt,
        ]);
        
        // Server-side GA4 tracking (works even if client blocks GA)
        $this->sendServerSideAnalytics();
        
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
        // Note: We only block on failed verification, not missing token
        // (missing token could be due to ad blockers, network issues, etc.)
        if (config('services.turnstile.enabled') && config('services.turnstile.secret_key')) {
            if (!empty($this->turnstileToken)) {
                if (!$this->verifyTurnstile()) {
                    return 'turnstile_failed';
                }
            } else {
                // Log missing token for monitoring but don't block
                // Real users may have ad blockers or slow connections
                Log::channel('submissions')->info('Turnstile token missing (not blocked)', [
                    'ip' => request()->ip(),
                    'name' => $this->name,
                    'email' => $this->email,
                ]);
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

        // 3. Gibberish detection - only check message (names can be foreign with unusual patterns)
        if ($this->containsGibberish($this->message)) {
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
        // Only check longer text to avoid false positives on short messages
        if (strlen($text) < 20) {
            return false;
        }

        // Check consonant-to-vowel ratio (gibberish often has unusual ratios)
        $vowels = preg_match_all('/[aeiouAEIOU]/', $text);
        $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]/', $text);
        
        if ($consonants > 0 && $vowels > 0) {
            $ratio = $consonants / $vowels;
            // Normal English has ~1.5-2 consonants per vowel, gibberish often has 5+
            if ($ratio > 5) {
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
                Log::channel('submissions')->warning('Turnstile verification failed', [
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

            Log::channel('submissions')->debug('Turnstile verification passed', [
                'ip' => request()->ip(),
                'hostname' => $result['hostname'] ?? null,
                'email' => $this->email,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::channel('submissions')->error('Turnstile verification error', [
                'error' => $e->getMessage(),
            ]);
            // Allow form submission if Turnstile API fails (graceful degradation)
            return true;
        }
    }
    
    /**
     * Send server-side analytics to GA4 via Measurement Protocol.
     * This tracks form submissions even when client-side GA is blocked.
     */
    protected function sendServerSideAnalytics(): void
    {
        $measurementId = config('services.google.measurement_id');
        $apiSecret = config('services.google.measurement_api_secret');
        
        if (! $measurementId || ! $apiSecret) {
            return;
        }
        
        try {
            // Try to get GA client_id in order of reliability:
            // 1. GA's own cookie (most reliable)
            // 2. Our session-stored ID
            // 3. Generate new UUID (VPN/incognito sessions)
            $gaCookie = request()->cookie('_ga');
            if ($gaCookie && preg_match('/GA\d+\.\d+\.(.+)/', $gaCookie, $matches)) {
                $clientId = $matches[1]; // Extract client_id from _ga cookie
                $trackingSource = 'ga_cookie';
            } elseif (session()->has('ga_client_id')) {
                $clientId = session('ga_client_id');
                $trackingSource = 'session';
            } else {
                // Generate and store for this session
                $clientId = \Str::uuid()->toString();
                session(['ga_client_id' => $clientId]);
                $trackingSource = 'generated';
            }
            
            Http::timeout(5)->post("https://www.google-analytics.com/mp/collect?measurement_id={$measurementId}&api_secret={$apiSecret}", [
                'client_id' => $clientId,
                'events' => [
                    [
                        'name' => 'generate_lead',
                        'params' => [
                            'event_category' => 'contact',
                            'event_label' => 'contact_form_submission',
                            'value' => 1,
                            'tracking_method' => 'server_side',
                            'client_id_source' => $trackingSource, // 'ga_cookie', 'session', or 'generated' (VPN/incognito)
                            'page_location' => request()->fullUrl(),
                            'page_referrer' => request()->header('referer'),
                            'user_agent' => request()->userAgent(),
                            'city' => $this->area?->city ?? 'not_specified',
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            // Don't let analytics failure affect form submission
            \Log::warning('Server-side GA4 tracking failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Store form submission in database for reliable tracking.
     * This provides a backup independent of email delivery and analytics.
     * 
     * @param string $status 'pending' for legitimate, 'spam' for blocked
     * @param string|null $spamReason Reason for spam classification
     */
    protected function storeSubmission(string $status = 'pending', ?string $spamReason = null): void
    {
        try {
            ContactSubmission::create([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phoneDigits,
                'address' => $this->address,
                'message' => $this->message,
                'availability' => $this->availability,
                'city' => $this->area?->city,
                'source' => request()->header('X-Requested-With') === 'XMLHttpRequest' ? 'ajax' : 'web',
                'referrer' => request()->header('referer'),
                'user_agent' => request()->userAgent(),
                'ip_address' => request()->ip(),
                'status' => $status,
                'spam_reason' => $spamReason,
                'utm_source' => session('utm_source') ?? request()->input('utm_source'),
                'utm_medium' => session('utm_medium') ?? request()->input('utm_medium'),
                'utm_campaign' => session('utm_campaign') ?? request()->input('utm_campaign'),
            ]);
        } catch (\Exception $e) {
            // Don't let database failure affect form submission
            Log::channel('submissions')->error('Failed to store contact submission', ['error' => $e->getMessage()]);
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
