<?php

namespace App\Livewire\Admin;

use App\Models\PlatformSetting;
use App\Services\GoogleBusinessProfileService;
use App\Services\YelpBusinessService;
use App\Services\YelpRemoteLoginService;
use Illuminate\Support\Facades\Log;
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

    // ---- Yelp remote-login viewer state ----
    public bool $yelpRemoteOpen = false;
    public ?string $yelpRemoteUrl = null;
    public ?string $yelpRemoteError = null;
    public ?int $yelpRemoteExpiresAt = null;

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

        // Always (re)launch the remote login viewer right after saving so the
        // user can complete any 2FA / captcha challenges immediately, in-browser.
        if (app(YelpBusinessService::class)->isConfigured()) {
            $this->startYelpRemoteLogin();
        }

        if ($this->yelpRemoteOpen) {
            session()->flash('platforms-success', 'Yelp credentials saved. A remote browser session has opened below — complete any captcha / 2FA there.');
        } else {
            session()->flash('platforms-success', 'Yelp credentials saved.');
        }
    }

    public function verifyYelpLogin(): void
    {
        $this->startYelpRemoteLogin();
    }

    /**
     * Boot the in-browser remote login viewer (Xvfb + noVNC). Sets state
     * so the blade can render an iframe; no Chromium window is opened on
     * the server's local display (production is headless).
     */
    public function startYelpRemoteLogin(): void
    {
        $svc = app(YelpBusinessService::class);
        if (! $svc->isConfigured()) {
            session()->flash('platforms-error', 'Set Yelp email and password first.');
            return;
        }
        $remote = app(YelpRemoteLoginService::class);
        $result = $remote->start();
        if (! ($result['ok'] ?? false)) {
            $this->yelpRemoteOpen = false;
            $this->yelpRemoteUrl = null;
            $this->yelpRemoteError = $result['error'] ?? 'Failed to start remote login session.';
            return;
        }
        $this->yelpRemoteOpen = true;
        $this->yelpRemoteUrl = $result['url'];
        $this->yelpRemoteExpiresAt = $result['expires_at'] ?? null;
        $this->yelpRemoteError = null;
    }

    public function stopYelpRemoteLogin(): void
    {
        app(YelpRemoteLoginService::class)->stop();
        $this->yelpRemoteOpen = false;
        $this->yelpRemoteUrl = null;
        $this->yelpRemoteExpiresAt = null;
        $this->refreshStatus();
    }

    /**
     * Polled by the blade every few seconds while the iframe is open.
     * Closes the viewer once the Chromium login process has exited.
     */
    public function pollYelpRemoteLogin(): void
    {
        if (! $this->yelpRemoteOpen) return;
        $status = app(YelpRemoteLoginService::class)->status();
        if (! ($status['running'] ?? false)) {
            $this->yelpRemoteOpen = false;
            $this->yelpRemoteUrl = null;
            $this->yelpRemoteExpiresAt = null;
            $svc = app(YelpBusinessService::class);
            $this->yelpAuthenticated = $svc->checkSession();
            if ($this->yelpAuthenticated === true) {
                session()->flash('platforms-success', 'Yelp login completed — session is active.');
            } else {
                session()->flash('platforms-error', 'Login window closed before a Yelp session could be verified. Click “Verify Login” to try again.');
            }
        }
    }

    /**
     * Called from the browser when the noVNC iframe fails to load (502, etc).
     * Logs the failure with full context and tears the session down so the
     * admin can click Verify Login to start fresh.
     */
    public function reportYelpRemoteError(string $reason = 'iframe load failure'): void
    {
        Log::warning('Yelp remote login: iframe error reported by client', [
            'reason' => $reason,
            'url' => $this->yelpRemoteUrl,
            'user_id' => auth()->id(),
        ]);
        app(YelpRemoteLoginService::class)->stop();
        $this->yelpRemoteOpen = false;
        $this->yelpRemoteUrl = null;
        $this->yelpRemoteExpiresAt = null;
        $this->yelpRemoteError = 'Remote viewer failed to connect (' . $reason . '). The VNC stack has been reset — click Verify Login to try again.';
    }

    public function checkYelpSession(bool $silent = false): void
    {
        $authed = app(YelpBusinessService::class)->checkSession();
        $this->yelpAuthenticated = $authed;
        if ($silent) return;
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
