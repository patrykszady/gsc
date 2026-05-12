<?php

namespace App\Livewire\Admin;

use App\Models\PlatformSetting;
use App\Services\GoogleBusinessProfileService;
use App\Services\YelpBusinessService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.admin')]
#[Title('Platforms')]
class PlatformsSettings extends Component
{
    // ---- GBP state ----
    public bool $gbpConnected = false;
    public bool $gbpNeedsReauth = false;
    public ?string $gbpEmail = null;
    public ?string $gbpConnectedAt = null;
    public ?string $gbpHealthStatus = null;
    public ?string $gbpHealthError = null;

    // ---- Yelp state ----
    #[Validate('nullable|email|max:255')]
    public string $yelpEmail = '';

    #[Validate('nullable|string|max:255')]
    public string $yelpPassword = '';

    public bool $yelpHasPassword = false;
    public ?bool $yelpAuthenticated = null; // null = unknown, true/false = checked
    public ?string $yelpStatusNote = null;

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        // GBP
        $gbp = app(GoogleBusinessProfileService::class);
        $stored = $gbp->getStoredToken();
        $this->gbpConnected = $gbp->hasRefreshToken();
        $this->gbpEmail = $stored?->granted_by_email;
        $this->gbpConnectedAt = $stored?->created_at?->diffForHumans();
        $this->gbpNeedsReauth = false;

        if ($this->gbpConnected) {
            $result = $gbp->listMedia(null, 1);
            if ($result === null) {
                $error = $gbp->getLastError();
                $this->gbpHealthStatus = 'error';
                $this->gbpHealthError = $error['error_description'] ?? $error['message'] ?? 'Unknown error';
                $this->gbpNeedsReauth = (bool) ($error['reauthorization_required'] ?? false);
            } else {
                $this->gbpHealthStatus = 'ok';
                $this->gbpHealthError = null;
            }
        }

        // Yelp
        $yelp = app(YelpBusinessService::class);
        $this->yelpEmail = (string) ($yelp->getEmail() ?? '');
        $this->yelpHasPassword = ! empty($yelp->getPassword());
        $this->yelpPassword = '';
    }

    // ---- GBP actions ----
    public function connectGbp(): mixed
    {
        $gbp = app(GoogleBusinessProfileService::class);
        return $this->redirect($gbp->getOAuthUrl(route('admin.platforms.gbp-callback')), navigate: false);
    }

    public function disconnectGbp(): void
    {
        app(GoogleBusinessProfileService::class)->disconnect();
        session()->flash('platforms-success', 'Google Business Profile disconnected.');
        $this->refreshStatus();
    }

    // ---- Yelp actions ----
    public function saveYelp(): void
    {
        $this->validate();

        PlatformSetting::put(YelpBusinessService::SETTING_EMAIL, $this->yelpEmail !== '' ? $this->yelpEmail : null);

        $passwordChanged = $this->yelpPassword !== '';
        if ($passwordChanged) {
            PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, $this->yelpPassword);
        }

        $this->refreshStatus();

        // Always (re)launch the headed login browser right after saving so the
        // user can complete any 2FA / captcha challenges immediately, while
        // they are still in front of their machine.
        $launched = false;
        if (app(YelpBusinessService::class)->isConfigured()) {
            $launched = app(YelpBusinessService::class)->launchLoginBrowser();
        }

        if ($launched) {
            session()->flash('platforms-success', 'Yelp credentials saved. A Chromium window has opened — complete any captcha / 2FA there. The window will close automatically once login succeeds.');
        } else {
            session()->flash('platforms-success', 'Yelp credentials saved.');
        }
    }

    public function verifyYelpLogin(): void
    {
        $svc = app(YelpBusinessService::class);
        if (! $svc->isConfigured()) {
            session()->flash('platforms-error', 'Set Yelp email and password first.');
            return;
        }
        if ($svc->launchLoginBrowser()) {
            session()->flash('platforms-success', 'Chromium window opened — complete login / 2FA / captcha. It will close itself when finished.');
        } else {
            session()->flash('platforms-error', 'Failed to launch login browser. Check logs.');
        }
    }

    public function checkYelpSession(): void
    {
        $authed = app(YelpBusinessService::class)->checkSession();
        $this->yelpAuthenticated = $authed;
        if ($authed === true) {
            session()->flash('platforms-success', 'Yelp session is active.');
        } elseif ($authed === false) {
            session()->flash('platforms-error', 'Yelp session is NOT logged in. Click “Verify Login” to refresh it.');
        } else {
            session()->flash('platforms-error', 'Could not verify Yelp session (script error / timeout).');
        }
    }

    public function clearYelpPassword(): void
    {
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, null);
        session()->flash('platforms-success', 'Yelp password cleared.');
        $this->refreshStatus();
    }

    public function render()
    {
        return view('livewire.admin.platforms-settings');
    }
}
