<?php

namespace App\Livewire\Admin;

use App\Models\PlatformSetting;
use App\Services\GoogleBusinessProfileService;
use App\Services\YelpBusinessService;
use App\Services\YelpRemoteLoginService;
use Illuminate\Support\Facades\Cache;
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
    public ?int $yelpPasswordLen = null;
    public ?string $yelpPasswordFingerprint = null;
    public ?bool $yelpAuthenticated = null; // null = unknown, true/false = checked
    public ?string $yelpStatusNote = null;
    public bool $yelpSessionDead = false;
    public ?string $yelpSessionDeadAt = null;
    public ?string $yelpSessionDeadNote = null;

    // ---- Yelp remote-login viewer state ----
    public bool $yelpRemoteOpen = false;
    public ?string $yelpRemoteUrl = null;
    public ?string $yelpRemoteError = null;
    public ?int $yelpRemoteExpiresAt = null;
    public ?string $yelpRemoteLogTail = null;
    // True once the login script has exited. The noVNC iframe is gone but
    // the log-tail panel stays visible so the operator can review the
    // final captured stderr (auth outcome, cookie summary, errors).
    public bool $yelpRemoteFinished = false;

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
        $storedPassword = (string) ($yelp->getPassword() ?? '');
        $this->yelpHasPassword = $storedPassword !== '';
        $this->yelpPasswordLen = $this->yelpHasPassword ? strlen($storedPassword) : null;
        $this->yelpPasswordFingerprint = $this->yelpHasPassword
            ? substr(hash('sha256', $storedPassword), 0, 6)
            : null;
        $this->yelpPassword = '';

        // Seed last known Yelp auth state from cache / cookie-file so the UI
        // doesn't flash "unknown" on every page load while the slow re-check
        // runs in the background.
        $quick = $yelp->quickCheckSession();
        if ($quick !== null) {
            $this->yelpAuthenticated = $quick;
        } else {
            $cached = Cache::get('yelp.last_auth');
            if ($cached !== null) {
                $this->yelpAuthenticated = (bool) $cached;
            }
        }

        // Sticky "session expired" signal set by upload jobs when they
        // detect the persistent Chromium profile is no longer logged in.
        // Cleared by markSessionFresh() on the next successful upload /
        // verify-login.
        $dead = Cache::get('yelp.session_dead');
        if (is_array($dead)) {
            $this->yelpSessionDead = true;
            $this->yelpSessionDeadAt = (string) ($dead['at'] ?? '');
            $this->yelpSessionDeadNote = (string) ($dead['note'] ?? '');
        } else {
            $this->yelpSessionDead = false;
            $this->yelpSessionDeadAt = null;
            $this->yelpSessionDeadNote = null;
        }
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
        // Clear the sticky 12h session_dead banner up-front. Without this, if
        // the operator successfully logs in but the post-login checkSession
        // poll fails (DataDome on the headless verifier, network blip), the
        // banner stays set and every queued upload fails with "session
        // expired" — even though the cookies are actually fresh.
        Cache::forget('yelp.session_dead');
        Log::channel('yelp')->info('Yelp Verify Login: operator initiated, cleared session_dead flag', [
            'user_id' => auth()->id(),
        ]);
        $this->startYelpRemoteLogin();
    }

    /**
     * Boot the in-browser remote login viewer (Xvfb + noVNC). Sets state
     * so the blade can render an iframe; no Chromium window is opened on
     * the server's local display (production is headless).
     */
    public function startYelpRemoteLogin(bool $resetProfile = false): void
    {
        $svc = app(YelpBusinessService::class);
        if (! $svc->isConfigured()) {
            session()->flash('platforms-error', 'Set Yelp email and password first.');
            return;
        }
        $remote = app(YelpRemoteLoginService::class);
        $result = $remote->start($resetProfile);
        if (! ($result['ok'] ?? false)) {
            $this->yelpRemoteOpen = false;
            $this->yelpRemoteUrl = null;
            $this->yelpRemoteError = $result['error'] ?? 'Failed to start remote login session.';
            return;
        }
        $this->yelpRemoteOpen = true;
        $this->yelpRemoteUrl = $result['url'];
        $this->yelpRemoteExpiresAt = $result['expires_at'] ?? null;
        $this->yelpRemoteFinished = false;
        $this->yelpRemoteError = null;
        $this->yelpRemoteError = null;
    }

    /**
     * Wipe the persistent Chromium profile and start a fresh session. Use
     * when DataDome appears stuck on the same cookie or the profile is
     * otherwise poisoned.
     */
    public function resetYelpProfile(): void
    {
        Cache::forget('yelp.session_dead');
        Log::channel('yelp')->info('Yelp remote login: operator requested profile reset', [
            'user_id' => auth()->id(),
        ]);
        // Clear viewer state up-front so the iframe unmounts before the
        // new session boots. Without this, the iframe keeps polling the
        // dying websockify and the browser caches the failed handshake,
        // so even a brand-new server gets a "failed to connect" verdict.
        $this->yelpRemoteOpen = false;
        $this->yelpRemoteUrl = null;
        $this->yelpRemoteExpiresAt = null;
        $this->yelpRemoteLogTail = null;
        $this->yelpRemoteError = null;
        $this->yelpRemoteFinished = false;

        app(YelpRemoteLoginService::class)->stop();
        // Give port 6080 a moment to fully release before start() races to
        // re-bind it.
        usleep(500000);
        $this->startYelpRemoteLogin(resetProfile: true);
    }

    public function stopYelpRemoteLogin(): void
    {
        app(YelpRemoteLoginService::class)->stop();
        $this->yelpRemoteOpen = false;
        $this->yelpRemoteUrl = null;
        $this->yelpRemoteExpiresAt = null;
        $this->yelpRemoteFinished = false;
        $this->yelpRemoteLogTail = null;
        $this->refreshStatus();
    }

    /**
     * Polled by the blade every few seconds while the iframe is open.
     * Closes the viewer once the Chromium login process has exited.
     */
    public function pollYelpRemoteLogin(): void
    {
        if (! $this->yelpRemoteOpen) return;
        $remote = app(YelpRemoteLoginService::class);
        $status = $remote->status();
        // Live tail of the headed Chromium's stderr so the operator can see
        // what the embedded browser is doing (navigations, DataDome
        // detections, magic-link redirects) from the admin panel.
        $this->yelpRemoteLogTail = $remote->tailChromeLog(6000);
        if (! ($status['running'] ?? false)) {
            // Tear down the noVNC iframe (browser is dead, viewer would just
            // show a connection-failed banner) but KEEP the log-tail panel
            // visible so the operator can review the final captured stderr
            // (auth outcome, cookie summary, errors). They click "Close"
            // to dismiss it manually.
            $this->yelpRemoteUrl = null;
            $this->yelpRemoteExpiresAt = null;
            $this->yelpRemoteFinished = true;
            $svc = app(YelpBusinessService::class);

            // Prefer the login script's own outcome over a fresh headless
            // probe: re-visiting biz.yelp.com headlessly fires a new
            // DataDome challenge that has nothing to do with the cookies
            // the operator just acquired, and the probe almost always
            // reports "NOT logged in" even when the session is valid.
            $outcome = $remote->readLoginOutcome();
            if (is_array($outcome) && ($outcome['authenticated'] ?? false) === true) {
                $svc->markSessionFresh();
                $this->yelpAuthenticated = true;
                Log::channel('yelp')->info('Yelp remote login: script reported authenticated=true, skipping headless re-check', [
                    'outcome' => $outcome,
                    'user_id' => auth()->id(),
                ]);
            } else {
                $this->yelpAuthenticated = $svc->checkSession();
                Log::channel('yelp')->info('Yelp remote login: poll detected session ended, falling back to checkSession', [
                    'status' => $status,
                    'outcome' => $outcome,
                    'checkSession_result' => $this->yelpAuthenticated,
                    'user_id' => auth()->id(),
                ]);
            }

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
        Log::channel('yelp')->warning('Yelp remote login: iframe error reported by client', [
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

    public function checkYelpSession(): void
    {
        $yelp = app(YelpBusinessService::class);

        $authed = $yelp->checkSession();
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
