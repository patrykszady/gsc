<?php

namespace App\Livewire\Admin;

use App\Models\PlatformSetting;
use App\Models\ProjectImage;
use App\Services\GoogleBusinessProfileService;
use App\Services\AiContentService;
use App\Services\InstagramRemoteLoginService;
use App\Services\MetaSocialService;
use App\Services\YelpBusinessService;
use App\Services\YelpRemoteLoginService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    // ---- Meta (Instagram/Facebook) state ----
    public bool $metaEnabled = false;
    public bool $metaConnected = false;
    public ?string $metaCredentialSource = null; // 'oauth' | 'env' | null
    public bool $metaInstagramConfigured = false;
    public bool $metaFacebookConfigured = false;
    public ?string $metaHealthStatus = null;
    public ?string $metaHealthError = null;
    public ?string $metaHealthWarning = null;
    public ?string $metaPageId = null;
    public ?string $metaPageName = null;
    public ?string $metaInstagramId = null;
    public ?string $metaInstagramUsername = null;
    public array $metaMissingImageWarnings = [];

    // ---- Instagram (Puppeteer profile) state ----
    public ?bool $igProfileExists = null;
    public ?bool $igSessionAuthenticated = null; // null = unknown, true/false = checked
    public ?string $igSessionCheckedAt = null;
    public ?string $igSessionUsername = null;
    public bool $igRemoteOpen = false;
    public ?string $igRemoteUrl = null;
    public ?string $igRemoteError = null;
    public ?int $igRemoteExpiresAt = null;
    public ?string $igRemoteLogTail = null;
    public bool $igRemoteFinished = false;

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

        $this->refreshMetaStatus();
        $this->refreshMetaImageWarnings();
        $this->refreshInstagramPuppeteerStatus();

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

    protected function refreshMetaStatus(): void
    {
        $meta = app(MetaSocialService::class);
        $cfg = config('services.meta');
        $creds = $meta->getCredentials();

        $this->metaEnabled = (bool) ($cfg['enabled'] ?? false);
        $this->metaConnected = $creds['token'] !== null;
        $this->metaCredentialSource = $creds['source'];
        $this->metaInstagramConfigured = $meta->isInstagramConfigured();
        $this->metaFacebookConfigured = $meta->isFacebookConfigured();
        $this->metaHealthStatus = null;
        $this->metaHealthError = null;
        $this->metaHealthWarning = null;
        $this->metaPageId = $creds['page_id'];
        $this->metaPageName = $creds['page_name'];
        $this->metaInstagramId = $creds['ig_id'];
        $this->metaInstagramUsername = $creds['ig_username'];

        if (! $this->metaEnabled) {
            $this->metaHealthStatus = 'disabled';
            return;
        }

        if ($creds['token'] === null) {
            $this->metaHealthStatus = 'not_configured';
            $this->metaHealthError = 'Click “Connect Facebook” to authorise the app.';
            return;
        }

        $pageId = $creds['page_id'];
        if (! $pageId) {
            $this->metaHealthStatus = 'partial';
            $this->metaHealthError = 'Connected, but no Facebook Page ID was discovered. Reconnect and pick a page.';
            return;
        }

        // Live Graph check confirms the token is still valid and refreshes
        // the page/IG metadata we display.
        $response = Http::timeout(20)->get("https://graph.facebook.com/v25.0/{$pageId}", [
            'fields' => 'id,name,instagram_business_account{id,username}',
            'access_token' => $creds['token'],
        ]);

        if (! $response->successful()) {
            $body = $response->json();
            $this->metaHealthStatus = 'error';
            $this->metaHealthError = (string) ($body['error']['message'] ?? 'Meta Graph request failed.');
            return;
        }

        $page = $response->json();
        $this->metaPageId = (string) ($page['id'] ?? $pageId);
        $this->metaPageName = (string) ($page['name'] ?? $creds['page_name'] ?? '');

        $ig = $page['instagram_business_account'] ?? null;
        if (! is_array($ig)) {
            $this->metaHealthStatus = 'partial';
            $this->metaHealthError = 'Page token is valid, but no linked Instagram business account is visible to this app.';
            return;
        }

        $this->metaInstagramId = (string) ($ig['id'] ?? '');
        $this->metaInstagramUsername = (string) ($ig['username'] ?? '');
        $this->metaHealthStatus = 'ok';
    }

    protected function refreshMetaImageWarnings(): void
    {
        $this->metaMissingImageWarnings = ProjectImage::query()
            ->with('project:id,title,slug,project_type,location,is_published')
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->latest('id')
            ->limit(25)
            ->get()
            ->filter(fn (ProjectImage $image) => ! $image->fileExists())
            ->take(5)
            ->map(fn (ProjectImage $image) => [
                'id' => $image->id,
                'alt_text' => $image->alt_text,
                'project' => $image->project?->title ?? 'N/A',
                'path' => $image->path,
            ])
            ->values()
            ->all();
    }

    protected function refreshInstagramPuppeteerStatus(): void
    {
        $remote = app(InstagramRemoteLoginService::class);
        $dir = $remote->userDataDir();
        $cookieDb = $dir . '/Default/Cookies';
        $this->igProfileExists = is_dir($dir) && is_file($cookieDb) && (int) @filesize($cookieDb) > 1024;

        // Lightweight cached status — the actual headless probe is expensive
        // (~10-20s) so we only run it when the operator clicks "Verify".
        $cached = Cache::get('instagram.last_session_check');
        if (is_array($cached)) {
            $this->igSessionAuthenticated = (bool) ($cached['authed'] ?? false);
            $this->igSessionCheckedAt = (string) ($cached['at'] ?? '');
            $this->igSessionUsername = $cached['username'] ?? null;
        }
    }

    public function verifyInstagramSession(): void
    {
        $remote = app(InstagramRemoteLoginService::class);
        if (! $this->igProfileExists) {
            $this->igSessionAuthenticated = false;
            session()->flash('platforms-error', 'No Instagram puppeteer profile found. Click “Open Login Window” to create one.');
            return;
        }
        $authed = $remote->checkSession();
        if ($authed === null) {
            $this->igSessionAuthenticated = null;
            session()->flash('platforms-error', 'Could not verify Instagram session (script error / timeout).');
            return;
        }
        // Try to capture the username from the check log too.
        $username = null;
        $log = (string) @shell_exec(sprintf(
            '%s %s --user-data-dir=%s --timeout-ms=20000 2>/dev/null',
            escapeshellarg((string) config('services.instagram.node_binary', 'node')),
            escapeshellarg(base_path('scripts/instagram-check-session.mjs')),
            escapeshellarg($remote->userDataDir())
        ));
        if ($log !== '' && preg_match('/"username"\s*:\s*"([^"]+)"/', $log, $m)) {
            $username = $m[1];
        }

        $this->igSessionAuthenticated = $authed;
        $this->igSessionUsername = $username;
        $this->igSessionCheckedAt = now()->toIso8601String();
        Cache::put('instagram.last_session_check', [
            'authed' => $authed,
            'username' => $username,
            'at' => $this->igSessionCheckedAt,
        ], now()->addHours(6));

        if ($authed) {
            session()->flash('platforms-success', 'Instagram session is active' . ($username ? " ({$username})." : '.'));
        } else {
            session()->flash('platforms-error', 'Instagram session is NOT logged in. Click “Open Login Window” to refresh it.');
        }
    }

    public function startInstagramRemoteLogin(bool $resetProfile = false): void
    {
        $remote = app(InstagramRemoteLoginService::class);

        // If session is already valid, don't bother spinning up the noVNC stack —
        // chromium would auto-detect and exit immediately, leaving a dead iframe.
        if (! $resetProfile) {
            $authed = $remote->checkSession(20);
            if ($authed === true) {
                $this->igSessionAuthenticated = true;
                $this->igRemoteOpen = false;
                $this->igRemoteUrl = null;
                $this->igRemoteError = null;
                $this->igRemoteFinished = false;
                session()->flash('platforms-success', 'Instagram session is already valid — no re-login needed.');
                $this->refreshInstagramPuppeteerStatus();
                return;
            }
        }

        $result = $remote->start($resetProfile);
        if (! ($result['ok'] ?? false)) {
            $this->igRemoteOpen = false;
            $this->igRemoteUrl = null;
            $this->igRemoteError = $result['error'] ?? 'Failed to start remote login session.';
            return;
        }
        $this->igRemoteOpen = true;
        $this->igRemoteUrl = $result['url'];
        $this->igRemoteExpiresAt = $result['expires_at'] ?? null;
        $this->igRemoteFinished = false;
        $this->igRemoteError = null;
    }

    public function resetInstagramProfile(): void
    {
        Log::info('Instagram remote login: operator requested profile reset', [
            'user_id' => auth()->id(),
        ]);
        $this->igRemoteOpen = false;
        $this->igRemoteUrl = null;
        $this->igRemoteExpiresAt = null;
        $this->igRemoteLogTail = null;
        $this->igRemoteError = null;
        $this->igRemoteFinished = false;
        app(InstagramRemoteLoginService::class)->stop();
        usleep(500000);
        $this->startInstagramRemoteLogin(resetProfile: true);
    }

    public function stopInstagramRemoteLogin(): void
    {
        app(InstagramRemoteLoginService::class)->stop();
        $this->igRemoteOpen = false;
        $this->igRemoteUrl = null;
        $this->igRemoteExpiresAt = null;
        $this->igRemoteFinished = false;
        $this->igRemoteLogTail = null;
        $this->refreshStatus();
    }

    public function pollInstagramRemoteLogin(): void
    {
        if (! $this->igRemoteOpen) return;
        $remote = app(InstagramRemoteLoginService::class);
        $status = $remote->status();
        $this->igRemoteLogTail = $remote->tailChromeLog(6000);
        if (! ($status['running'] ?? false)) {
            $this->igRemoteUrl = null;
            $this->igRemoteExpiresAt = null;
            $this->igRemoteFinished = true;

            // Trust the script's outcome JSON over a fresh headless re-probe.
            $outcome = $remote->readLoginOutcome();
            if (is_array($outcome) && ($outcome['authenticated'] ?? false) === true) {
                $this->igSessionAuthenticated = true;
                $this->igSessionUsername = $outcome['username'] ?? null;
                $this->igSessionCheckedAt = now()->toIso8601String();
                Cache::put('instagram.last_session_check', [
                    'authed' => true,
                    'username' => $this->igSessionUsername,
                    'at' => $this->igSessionCheckedAt,
                ], now()->addHours(6));
                session()->flash('platforms-success', 'Instagram login completed — session is active'
                    . ($this->igSessionUsername ? " ({$this->igSessionUsername})." : '.'));
            } else {
                // Script timed out / closed without success — verify headlessly.
                $authed = $remote->checkSession();
                $this->igSessionAuthenticated = $authed === true;
                if ($this->igSessionAuthenticated) {
                    session()->flash('platforms-success', 'Instagram session detected after login window closed.');
                } else {
                    session()->flash('platforms-error', 'Login window closed before an Instagram session could be verified. Click “Open Login Window” to try again.');
                }
            }
            $this->refreshInstagramPuppeteerStatus();
        }
    }

    public function reportInstagramRemoteError(string $reason = 'iframe load failure'): void
    {
        Log::warning('Instagram remote login: iframe error reported by client', [
            'reason' => $reason,
            'url' => $this->igRemoteUrl,
            'user_id' => auth()->id(),
        ]);
        app(InstagramRemoteLoginService::class)->stop();
        $this->igRemoteOpen = false;
        $this->igRemoteUrl = null;
        $this->igRemoteExpiresAt = null;
        $this->igRemoteError = 'Remote viewer failed to connect (' . $reason . '). The VNC stack has been reset — click Open Login Window to try again.';
    }

    // ---- Meta actions ----
    public function connectMeta(): mixed
    {
        $meta = app(MetaSocialService::class);
        return $this->redirect($meta->getOAuthUrl(route('admin.platforms.meta-callback')), navigate: false);
    }

    public function disconnectMeta(): void
    {
        app(MetaSocialService::class)->disconnect();
        session()->flash('platforms-success', 'Meta (Facebook + Instagram) disconnected.');
        $this->refreshStatus();
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

    public function testMetaConnection(): void
    {
        $service = app(MetaSocialService::class);
        $aiService = app(AiContentService::class);

        $image = ProjectImage::query()
            ->with('project')
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->latest('id')
            ->limit(25)
            ->get()
            ->first(fn (ProjectImage $candidate) => $candidate->fileExists());

        if (! $image) {
            session()->flash('platforms-error', 'No eligible project image with a local source file was found for the Meta test.');
            return;
        }

        $shortLinkUrl = $service->getShortLinkUrl($image);
        $content = $aiService->generateSocialMediaContent($image, $shortLinkUrl);

        if (! $content) {
            session()->flash('platforms-error', 'Meta test failed during caption generation: ' . $aiService->getLastError());
            return;
        }

        $fullCaption = trim((string) ($content['caption'] ?? ''));
        if ($shortLinkUrl !== '') {
            $fullCaption .= "\n\n🔗 {$shortLinkUrl}";
        }
        if (! empty($content['hashtags'])) {
            $fullCaption .= "\n\n" . trim((string) $content['hashtags']);
        }

        $container = $service->createInstagramContainer(
            (string) $service->getPublicImageUrl($image),
            $fullCaption
        );

        if (! $container) {
            $error = $service->getLastError();
            session()->flash('platforms-error', 'Meta test failed: ' . ($error['message'] ?? 'Unknown error'));
            return;
        }

        session()->flash('platforms-success', "Meta test succeeded for image #{$image->id}. Container ID: {$container['id']} (not published).");
        $this->refreshStatus();
    }

    public function render()
    {
        return view('livewire.admin.platforms-settings');
    }
}
