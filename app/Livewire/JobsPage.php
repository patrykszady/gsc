<?php

namespace App\Livewire;

use App\Mail\JobApplicationSubmission;
use App\Services\SeoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.app')]
class JobsPage extends Component
{
    /**
     * Who can reach out through the careers / partnership form.
     *
     * @return array<string, string>
     */
    public static function applicantTypes(): array
    {
        return [
            'team_member' => 'Join the team (employee)',
            'tradesperson' => 'Tradesperson / Craftsman',
            'subcontractor' => 'Subcontractor / Trade company',
            'designer' => 'Interior designer',
            'architect' => 'Architect',
            'showroom' => 'Showroom / Design center',
            'countertop' => 'Countertop fabricator / supplier',
            'cabinet' => 'Cabinet maker / supplier',
            'supplier' => 'Material supplier / manufacturer',
            'partner' => 'Other partnership',
        ];
    }

    #[Validate('required|min:2|max:100')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|max:30')]
    public string $phone = '';

    #[Validate('required|in:team_member,tradesperson,subcontractor,designer,architect,showroom,countertop,cabinet,supplier,partner')]
    public string $applicantType = 'tradesperson';

    #[Validate('nullable|string|max:150')]
    public string $trade = '';

    #[Validate('nullable|string|max:150')]
    public string $company = '';

    #[Validate('nullable|string|max:200')]
    public string $companyWebsite = '';

    #[Validate('nullable|string|max:150')]
    public string $languages = '';

    #[Validate('required|min:10|max:2000')]
    public string $message = '';

    /** Honeypot — must stay empty. Bots tend to fill every field. */
    public string $nickname = '';

    public string $turnstileToken = '';

    public int $formLoadedAt = 0;

    public function mount(): void
    {
        SeoService::jobs();
        $this->formLoadedAt = time();
    }

    public function submit(): void
    {
        $this->validate();

        // Rate limit: 3 submissions per hour per IP.
        $key = 'job-application:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = (int) ceil($seconds / 60);
            session()->flash('error', "You've submitted a few times already. Please try again in {$minutes} minute(s).");

            return;
        }

        // Spam detection. Unlike the contact form we do NOT require a
        // remodeling-topic match — applicants legitimately write about trades,
        // partnerships and suppliers in many different ways.
        if ($spamReason = $this->detectSpam()) {
            Log::channel('submissions')->warning('Job application flagged as spam', [
                'reason' => $spamReason,
                'name' => $this->name,
                'email' => $this->email,
                'ip' => request()->ip(),
            ]);

            // Show success to the bot so it doesn't adapt; nothing is emailed.
            session()->flash('success', "Thanks for reaching out! We'll review your information and get back to you soon.");
            $this->resetForm();

            return;
        }

        RateLimiter::hit($key, 3600);

        // Notify the company.
        Mail::to(config('mail.from.address'))->send(new JobApplicationSubmission(
            name: $this->name,
            email: $this->email,
            applicantTypeLabel: $this->applicantTypeLabel(),
            phone: $this->phone ?: null,
            trade: $this->trade ?: null,
            company: $this->company ?: null,
            website: $this->companyWebsite ?: null,
            languages: $this->languages ?: null,
            userMessage: $this->message ?: null,
        ));

        Log::channel('submissions')->info('Job application submitted', [
            'name' => $this->name,
            'email' => $this->email,
            'applicant_type' => $this->applicantType,
            'trade' => $this->trade,
            'company' => $this->company,
            'ip' => request()->ip(),
        ]);

        session()->flash('success', "Thanks for reaching out! We'll review your information and get back to you soon.");
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset([
            'name', 'email', 'phone', 'trade', 'company',
            'companyWebsite', 'languages', 'message', 'nickname', 'turnstileToken',
        ]);
        $this->applicantType = 'tradesperson';
        $this->formLoadedAt = time();
    }

    protected function applicantTypeLabel(): string
    {
        return self::applicantTypes()[$this->applicantType] ?? ucfirst($this->applicantType);
    }

    /**
     * Lightweight spam heuristics tuned for a careers / partnership form.
     */
    protected function detectSpam(): ?string
    {
        // Turnstile verification (if enabled). Required for non-US visitors.
        if (config('services.turnstile.enabled') && config('services.turnstile.secret_key')) {
            if (empty($this->turnstileToken)) {
                if (! $this->isUSVisitor()) {
                    return 'turnstile_missing_token';
                }
            } elseif (! $this->verifyTurnstile()) {
                return 'turnstile_failed';
            }
        }

        // Honeypot.
        if (! empty($this->nickname)) {
            return 'honeypot_filled';
        }

        // Submitted too fast (under 3 seconds = bot).
        if ((time() - $this->formLoadedAt) < 3) {
            return 'submitted_too_fast';
        }

        // Gibberish message.
        if ($this->containsGibberish($this->message)) {
            return 'gibberish_detected';
        }

        // Suspicious / disposable email.
        if ($this->isDisposableEmail($this->email)) {
            return 'disposable_email';
        }

        // Classic spam keywords (marketing/SEO outreach disguised as applications).
        $spamKeywords = [
            'viagra', 'cialis', 'casino', 'lottery', 'bitcoin', 'crypto',
            'investment opportunity', 'make money fast', 'click here',
            'seo services', 'web traffic', 'backlinks', 'google ranking',
            'first page', 'digital marketing', 'lead generation',
            'grow your business', 'boost your', 'increase your sales',
            'qualified leads', 'free demo', 'schedule a demo',
            'redesign your website', 'website redesign',
            'takeoff services', 'construction takeoffs', 'cost estimation services',
        ];
        $content = strtolower($this->name . ' ' . $this->company . ' ' . $this->message);
        foreach ($spamKeywords as $keyword) {
            if (str_contains($content, $keyword)) {
                return 'spam_keyword: ' . $keyword;
            }
        }

        // Excessive URLs in the message.
        if (preg_match_all('/https?:\/\/|www\./i', $this->message) >= 3) {
            return 'excessive_urls';
        }

        return null;
    }

    protected function isUSVisitor(): bool
    {
        $country = session('visitor_country', 'XX');
        $usCountries = ['US', 'PR', 'VI', 'GU', 'AS', 'MP'];

        return in_array($country, $usCountries, true) || $country === 'XX';
    }

    protected function containsGibberish(string $text): bool
    {
        if (strlen($text) < 20) {
            return false;
        }

        $vowels = preg_match_all('/[aeiouAEIOU]/', $text);
        $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]/', $text);
        if ($consonants > 0 && $vowels > 0 && ($consonants / $vowels) > 6) {
            return true;
        }

        // Long unbroken strings (bots often omit spaces).
        if (preg_match('/\S{30,}/', $text)) {
            return true;
        }

        return false;
    }

    protected function isDisposableEmail(string $email): bool
    {
        $disposableDomains = [
            'tempmail.com', 'temp-mail.org', 'guerrillamail.com', 'guerrillamail.net',
            'mailinator.com', '10minutemail.com', 'throwaway.email', 'fakeinbox.com',
            'yopmail.com', 'sharklasers.com', 'trashmail.com', 'maildrop.cc',
            'getnada.com', 'mailnesia.com', 'tempail.com', 'dispostable.com',
            'mintemail.com', 'mohmal.com', 'emailondeck.com', 'temp.email',
            'tempr.email', 'discard.email', 'throwawaymail.com', 'fakemailgenerator.com',
            'tempinbox.com', 'mailcatch.com', 'spamgourmet.com', 'mytrashmail.com',
        ];

        $domain = strtolower(explode('@', $email)[1] ?? '');

        return in_array($domain, $disposableDomains, true);
    }

    protected function verifyTurnstile(): bool
    {
        try {
            $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $this->turnstileToken,
                'remoteip' => request()->ip(),
            ]);

            $result = $response->json();

            if (! ($result['success'] ?? false)) {
                $errorCodes = $result['error-codes'] ?? [];

                // Misconfigured secret shouldn't block real users.
                if (in_array('invalid-input-secret', $errorCodes, true) || in_array('missing-input-secret', $errorCodes, true)) {
                    return true;
                }

                return false;
            }

            return true;
        } catch (\Exception $e) {
            // Graceful degradation if the Turnstile API is unreachable.
            Log::channel('submissions')->error('Turnstile verification error (jobs)', ['error' => $e->getMessage()]);

            return true;
        }
    }

    public function render()
    {
        return view('livewire.jobs-page', [
            'applicantTypes' => self::applicantTypes(),
            'turnstileSiteKey' => config('services.turnstile.site_key'),
            'turnstileEnabled' => config('services.turnstile.enabled') && config('services.turnstile.secret_key'),
            'isUSVisitor' => in_array(session('visitor_country', 'XX'), ['US', 'PR', 'VI', 'GU', 'AS', 'MP', 'XX'], true),
        ]);
    }
}
