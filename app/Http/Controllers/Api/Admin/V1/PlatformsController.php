<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\YelpAutoLogin;
use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Models\ProjectImage;
use App\Models\Site;
use App\Models\Testimonial;
use App\Services\AiContentService;
use App\Services\GoogleBusinessProfileService;
use App\Services\GoogleSearchConsoleService;
use App\Services\InstagramRemoteLoginService;
use App\Services\MetaSocialService;
use App\Services\YelpBusinessService;
use App\Services\YelpRemoteLoginService;
use App\Support\YelpCookieJar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Ops-domain API for the central admin's Platforms screen: connection
 * status for Google Business Profile, Google Search Console and Meta (all
 * three OAuth, via oauth_tokens), plus the FULL Yelp session-automation
 * surface (credentials, cookie injection, the remote-login noVNC viewer,
 * captcha/proxy auto-login) and the Instagram Puppeteer session used for
 * post location-tagging.
 *
 * Every side-effecting endpoint here wraps the EXACT same service calls the
 * legacy Livewire component (app/Livewire/Admin/PlatformsSettings.php) makes
 * — this controller is the API-shaped port of that component's action
 * methods, not a reimplementation. It never serializes a secret value
 * (password / captcha key) — only presence booleans, lengths and
 * fingerprints, same treatment the legacy blade gives them.
 *
 * The noVNC remote-login viewer is special-cased: the raw viewer URL (which
 * embeds a one-time VNC password) is never returned to the caller directly.
 * Instead it's cached server-side (see remoteLoginPayload()) and the caller
 * gets back a short-TTL SIGNED URL to PlatformsViewerController, which is
 * the only thing safe to embed in an iframe on ss-systems' origin — see
 * routes/platforms-viewer.php for why that route sits outside 'auth'.
 */
class PlatformsController extends Controller
{
    use BuildsApiResponses;

    /** Providers this controller drives an OAuth dance for. Yelp/Instagram are session/cookie-based, not OAuth. */
    protected const OAUTH_PROVIDERS = ['gbp', 'gsc', 'meta'];

    /** How long a signed viewer URL stays valid — long enough to load the iframe right after start/reset, short enough to not be worth guarding further. */
    protected const VIEWER_SIGNATURE_TTL_MINUTES = 15;

    public function status(): JsonResponse
    {
        return $this->itemResponse([
            'gbp' => $this->gbpStatus(),
            'gsc' => $this->gscStatus(),
            'meta' => $this->metaStatus(),
            'yelp' => $this->yelpStatus(),
            'instagram' => $this->instagramStatus(),
        ]);
    }

    /**
     * GET platforms/{provider}/oauth-url
     *
     * Built EXACTLY as the legacy component's connect*() actions do:
     * $service->getOAuthUrl(route('admin.platforms.{provider}-callback')).
     * That route() call is what makes the returned authorize URL's
     * redirect_uri match the callback already allowlisted in the Google /
     * Meta consoles — generation happens here, on gsc, with the {site}
     * segment supplied explicitly (URL::defaults() isn't populated on this
     * stateless API, unlike the legacy web request that filled it via
     * ResolveAdminSite).
     */
    public function oauthUrl(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        $site = ['site' => Site::current()->primary_host];

        $url = match ($provider) {
            'gbp' => app(GoogleBusinessProfileService::class)
                ->getOAuthUrl(route('admin.platforms.gbp-callback', $site)),
            'gsc' => app(GoogleSearchConsoleService::class)
                ->getOAuthUrl(route('admin.platforms.gsc-callback', $site)),
            'meta' => app(MetaSocialService::class)
                ->getOAuthUrl(route('admin.platforms.meta-callback', $site)),
        };

        return $this->itemResponse(['url' => $url]);
    }

    /**
     * DELETE platforms/{provider} — deletes the stored oauth_tokens row via
     * the same service methods the legacy disconnect*() actions call.
     * Yelp/Instagram have no OAuth token to remove, so neither is a valid
     * provider here.
     */
    public function disconnect(string $provider): Response
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        match ($provider) {
            'gbp' => app(GoogleBusinessProfileService::class)->disconnect(),
            'gsc' => app(GoogleSearchConsoleService::class)->disconnect(),
            'meta' => app(MetaSocialService::class)->disconnect(),
        };

        return response()->noContent();
    }

    /**
     * GET platforms/yelp/reviews-summary — pure DB read mirroring the
     * legacy view's inline query (platforms-settings.blade.php ~594-601):
     * how many Testimonials carry a Yelp reviewUrls row, and the newest
     * review_date as a stand-in for "last sync" (there's no dedicated
     * synced_at column — the review pipeline just re-reads the public Yelp
     * page and reviews land with whatever date Yelp shows). Never touches
     * yelp.com itself; testimonials:sync-yelp-reviews owns that on its own
     * schedule.
     */
    public function yelpReviewsSummary(): JsonResponse
    {
        $query = Testimonial::query()
            ->whereHas('reviewUrls', fn ($q) => $q->where('platform', 'yelp'));

        // max() is a raw aggregate, not a hydrated attribute, so it skips
        // the model's 'date' cast — normalize to a plain Y-m-d string here
        // rather than let the format vary with the DB driver (sqlite hands
        // back a full 'Y-m-d 00:00:00', mysql just 'Y-m-d').
        $latest = (clone $query)->max('review_date');

        return $this->itemResponse([
            'count' => (clone $query)->count(),
            'latest_review_date' => $latest ? Carbon::parse($latest)->toDateString() : null,
        ]);
    }

    /**
     * POST platforms/gsc/submit-sitemaps — runs the same command the
     * nightly schedule and Forge deploys already call
     * (seo:gsc-submit-sitemaps) on demand, so reconnecting gives instant
     * proof it worked. Mirrors the legacy component's submitSitemapsNow():
     * trimmed Artisan::output(), capped to 400 chars.
     *
     * SAFETY: this calls Google's Search Console API for real. Tests must
     * mock the Artisan facade (Artisan::shouldReceive(...)) rather than let
     * this run for real — never exercise it against a live token.
     */
    public function submitGscSitemaps(): JsonResponse
    {
        Artisan::call('seo:gsc-submit-sitemaps');
        $output = mb_substr(trim(Artisan::output()), 0, 400);

        return $this->itemResponse(['output' => $output]);
    }

    // ---- Yelp: credentials -------------------------------------------

    /**
     * POST platforms/yelp/credentials — mirrors the legacy component's
     * saveYelp(), minus the auto-launch of the remote-login viewer (the
     * caller — ss-systems' Livewire action — does that itself as a second
     * call to remote-login/start, exactly reproducing the same visible
     * behaviour without coupling two unrelated side effects into one
     * endpoint). Email is always (re)written; password is written only when
     * a new one was actually typed — blank means "keep the existing one",
     * same as the legacy form.
     */
    public function saveYelpCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        PlatformSetting::put(YelpBusinessService::SETTING_EMAIL, $data['email'] ?? null);

        if (! empty($data['password'])) {
            PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, $data['password']);
        }

        return $this->itemResponse([
            'yelp' => $this->yelpStatus(),
            'configured' => app(YelpBusinessService::class)->isConfigured(),
        ]);
    }

    public function clearYelpPassword(): JsonResponse
    {
        PlatformSetting::put(YelpBusinessService::SETTING_PASSWORD, null);

        return $this->itemResponse(['yelp' => $this->yelpStatus()]);
    }

    // ---- Yelp: session -------------------------------------------------

    /**
     * POST platforms/yelp/session/check — "run the 6-hourly keep-alive job
     * right now". Deliberately keepSessionAlive(), not the bare
     * checkSession(): see the legacy component's checkYelpSession() for why
     * (a negative bare check can only make things worse from an admin
     * button — markSessionDead() freezes uploads and spends a captcha
     * solve / sends an owner email, never writes cookies back).
     */
    public function checkYelpSession(): JsonResponse
    {
        $authed = app(YelpBusinessService::class)->keepSessionAlive();

        return $this->itemResponse(['authenticated' => $authed]);
    }

    // ---- Yelp: cookie injection (manual fallback) ----------------------

    /**
     * POST platforms/yelp/cookies/import — accepts a Cookie-Editor JSON
     * paste, filters to yelp.com cookies, normalizes, and merges or
     * replaces storage/app/yelp-cookies.json. Byte-for-byte the same
     * parsing/merge logic as the legacy component's
     * importYelpCookiesFromPaste() and the yelp:import-cookies artisan
     * command.
     */
    public function importYelpCookies(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'paste' => ['required', 'string'],
            'replace' => ['sometimes', 'boolean'],
        ]);

        // 200 with ok=false for all of these, deliberately — they're business
        // outcomes on an otherwise well-formed request, not malformed input.
        // A 422 here would make ss-systems' SiteApiConnection throw
        // SiteApiValidationException instead of returning the response body,
        // losing this exact message (that exception carries Laravel's
        // {field: [messages]} 'errors' shape, which this response has none
        // of) — see SiteApiConnection::handle().
        $data = json_decode(trim($validated['paste']), true);
        if (! is_array($data)) {
            return $this->itemResponse(['ok' => false, 'message' => 'That does not look like valid JSON.']);
        }
        if (isset($data['cookies']) && is_array($data['cookies'])) {
            $data = $data['cookies'];
        }
        if (count($data) === 0 || ! isset($data[0]['name'])) {
            return $this->itemResponse(['ok' => false, 'message' => 'Expected an array of cookie objects ({name, value, domain, ...}).']);
        }

        $yelpCookies = array_values(array_filter($data, function ($c) {
            $d = strtolower((string) ($c['domain'] ?? ''));

            return str_contains($d, 'yelp.com');
        }));
        if (count($yelpCookies) === 0) {
            return $this->itemResponse(['ok' => false, 'message' => 'No yelp.com cookies found in the pasted JSON.']);
        }

        $replace = (bool) ($validated['replace'] ?? false);
        $dest = YelpCookieJar::path();
        @mkdir(dirname($dest), 0755, true);

        $merged = $yelpCookies;
        if (! $replace && is_file($dest)) {
            $existing = json_decode((string) file_get_contents($dest), true) ?: [];
            $byKey = [];
            foreach (array_merge($existing, $yelpCookies) as $c) {
                if (! isset($c['name'], $c['domain'])) {
                    continue;
                }
                $k = strtolower(($c['domain'] ?? '').'|'.($c['path'] ?? '/').'|'.$c['name']);
                $byKey[$k] = $c;
            }
            $merged = array_values($byKey);
        }

        file_put_contents($dest, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($dest, 0600);

        $names = collect($merged)->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();
        $hasSession = (bool) array_intersect(['s', 'bse', 'bsd'], $names);

        Log::channel('yelp')->info('Yelp cookies imported via paste (central admin)', [
            'added' => count($yelpCookies),
            'total' => count($merged),
            'replace' => $replace,
            'has_session_cookie' => $hasSession,
        ]);

        // Importing fresh cookies almost always means the prior session was
        // dead — clear the sticky banner so queued uploads can resume.
        Cache::forget('yelp.session_dead');

        $message = 'Imported '.count($yelpCookies).' cookies ('.count($merged).' total in store).';
        if (! $hasSession) {
            $message .= ' WARN: no session cookie (s/bse/bsd) detected — login may not actually be authenticated.';
        }

        return $this->itemResponse([
            'ok' => true,
            'message' => $message,
            'has_session_cookie' => $hasSession,
            'yelp' => $this->yelpStatus(),
        ]);
    }

    public function clearYelpCookies(): JsonResponse
    {
        $path = YelpCookieJar::path();
        if (is_file($path)) {
            @unlink($path);
            Log::channel('yelp')->info('Yelp cookies file deleted (central admin)', []);
        }

        return $this->itemResponse(['ok' => true, 'yelp' => $this->yelpStatus()]);
    }

    // ---- Yelp: captcha solver / proxy (unattended sign-in) -------------

    /**
     * POST platforms/yelp/auto-login/settings — save the captcha key /
     * proxy that unattended login needs. Stored in PlatformSetting
     * (encrypted), same as the legacy component's saveYelpAutoLogin();
     * blank field means "keep the existing value", never overwrites with
     * empty.
     */
    public function saveYelpAutoLoginSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'captcha_key' => ['nullable', 'string', 'max:120'],
            'proxy' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['captcha_key'])) {
            PlatformSetting::put('yelp_twocaptcha_key', $data['captcha_key']);
        }
        if (! empty($data['proxy'])) {
            PlatformSetting::put('yelp_proxy', $data['proxy']);
        }

        return $this->itemResponse(['yelp' => $this->yelpStatus()]);
    }

    /**
     * POST platforms/yelp/auto-login/run — queue a background login using
     * the stored credentials. Mirrors the legacy component's
     * yelpLoginNow(), including the 30-minute captcha-spend floor: each
     * attempt buys a real 2captcha solve, so a repeat click within the
     * window is refused rather than spending another one on a failure mode
     * (e.g. "Yelp wants a verification code") a retry cannot fix.
     */
    public function runYelpAutoLogin(): JsonResponse
    {
        if (Cache::has('yelp.auto_login_attempted')) {
            // 200 with ok=false, not 429 — see the comment in
            // importYelpCookies() for why: this is a business outcome the
            // caller needs the exact message text for, not a transport
            // error SiteApiConnection should collapse into a generic one.
            return $this->itemResponse([
                'ok' => false,
                'message' => 'A sign-in attempt ran in the last 30 minutes. Waiting before spending another captcha solve — check "Last recovery attempt" below for the result.',
            ]);
        }

        YelpAutoLogin::dispatch()->onQueue('media-sync');

        Log::channel('yelp')->info('Yelp: login requested from admin (central admin)', []);

        return $this->itemResponse([
            'ok' => true,
            'message' => 'Logging in to Yelp in the background — refresh in a minute for the result.',
        ]);
    }

    // ---- Yelp: remote-login viewer (Xvfb + noVNC) -----------------------

    /**
     * POST platforms/yelp/remote-login/start — boots the in-browser remote
     * login viewer, exactly as the legacy component's
     * startYelpRemoteLogin(). Returns a SIGNED viewer URL, never the raw
     * noVNC URL — see remoteLoginPayload().
     */
    public function startYelpRemoteLogin(Request $request): JsonResponse
    {
        $resetProfile = $request->boolean('reset_profile');

        if (! app(YelpBusinessService::class)->isConfigured()) {
            // 200, not 422 — same reasoning as importYelpCookies() above.
            return $this->itemResponse(['ok' => false, 'error' => 'Set Yelp email and password first.']);
        }

        $result = app(YelpRemoteLoginService::class)->start($resetProfile);

        return $this->itemResponse($this->remoteLoginPayload('yelp', $result));
    }

    /**
     * POST platforms/yelp/remote-login/poll — polled while the viewer is
     * open. Mirrors pollYelpRemoteLogin(): live chromium log tail while
     * running; once the process has exited, prefers the login script's own
     * outcome JSON over a fresh headless re-probe (re-visiting biz.yelp.com
     * headlessly fires a NEW DataDome challenge unrelated to the cookies
     * just acquired) and re-queues any photos that stalled while the
     * session was down.
     */
    public function pollYelpRemoteLogin(): JsonResponse
    {
        $remote = app(YelpRemoteLoginService::class);
        $status = $remote->status();
        $logTail = $remote->tailChromeLog(6000);

        if ($status['running'] ?? false) {
            return $this->itemResponse(['running' => true, 'finished' => false, 'log_tail' => $logTail]);
        }

        $svc = app(YelpBusinessService::class);
        $outcome = $remote->readLoginOutcome();
        $requeued = 0;

        if (is_array($outcome) && ($outcome['authenticated'] ?? false) === true) {
            $requeued = $svc->markSessionFresh();
            $authenticated = true;
            Log::channel('yelp')->info('Yelp remote login: script reported authenticated=true, skipping headless re-check', [
                'outcome' => $outcome,
            ]);
        } else {
            $authenticated = $svc->checkSession();
            Log::channel('yelp')->info('Yelp remote login: poll detected session ended, falling back to checkSession', [
                'status' => $status,
                'outcome' => $outcome,
                'checkSession_result' => $authenticated,
            ]);
        }

        Cache::forget('platforms.remote_login_url.yelp');

        return $this->itemResponse([
            'running' => false,
            'finished' => true,
            'authenticated' => $authenticated,
            'requeued' => $requeued,
            'log_tail' => $logTail,
        ]);
    }

    public function stopYelpRemoteLogin(): JsonResponse
    {
        app(YelpRemoteLoginService::class)->stop();
        Cache::forget('platforms.remote_login_url.yelp');

        return $this->itemResponse(['yelp' => $this->yelpStatus()]);
    }

    /**
     * POST platforms/yelp/remote-login/reset — wipe the persistent Chromium
     * profile and start fresh. Mirrors resetYelpProfile() exactly,
     * including the 500ms pause after stop() so port 6080 fully releases
     * before start() races to re-bind it.
     */
    public function resetYelpProfile(): JsonResponse
    {
        Cache::forget('yelp.session_dead');
        Cache::forget('platforms.remote_login_url.yelp');
        Log::channel('yelp')->info('Yelp remote login: operator requested profile reset (central admin)', []);

        $remote = app(YelpRemoteLoginService::class);
        $remote->stop();
        usleep(500000);

        if (! app(YelpBusinessService::class)->isConfigured()) {
            // 200, not 422 — same reasoning as importYelpCookies() above.
            return $this->itemResponse(['ok' => false, 'error' => 'Set Yelp email and password first.']);
        }

        $result = $remote->start(resetProfile: true);

        return $this->itemResponse($this->remoteLoginPayload('yelp', $result));
    }

    /**
     * POST platforms/yelp/remote-login/report-error — called when the
     * embedded iframe fails to load (502, etc). Logs with full context and
     * tears the session down so the operator can start fresh.
     */
    public function reportYelpRemoteError(Request $request): JsonResponse
    {
        $reason = (string) $request->input('reason', 'iframe load failure');

        Log::channel('yelp')->warning('Yelp remote login: iframe error reported by client (central admin)', [
            'reason' => $reason,
        ]);

        app(YelpRemoteLoginService::class)->stop();
        Cache::forget('platforms.remote_login_url.yelp');

        return $this->itemResponse(['ok' => true]);
    }

    // ---- Instagram (Puppeteer profile, used for location-tagging) ------

    /**
     * POST platforms/instagram/session/verify — mirrors
     * verifyInstagramSession(): headless probe of the persisted profile,
     * plus a best-effort username scrape from the same check script's
     * output.
     */
    public function verifyInstagramSession(): JsonResponse
    {
        $remote = app(InstagramRemoteLoginService::class);

        if (! $this->instagramProfileExists($remote)) {
            return $this->itemResponse([
                'authenticated' => false,
                'error' => 'No Instagram puppeteer profile found. Click “Open Login Window” to create one.',
            ]);
        }

        $authed = $remote->checkSession();
        if ($authed === null) {
            return $this->itemResponse([
                'authenticated' => null,
                'error' => 'Could not verify Instagram session (script error / timeout).',
            ]);
        }

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

        $checkedAt = now()->toIso8601String();
        Cache::put('instagram.last_session_check', [
            'authed' => $authed,
            'username' => $username,
            'at' => $checkedAt,
        ], now()->addHours(6));

        return $this->itemResponse(['authenticated' => $authed, 'username' => $username, 'checked_at' => $checkedAt]);
    }

    /**
     * POST platforms/instagram/remote-login/start — mirrors
     * startInstagramRemoteLogin(): skips spinning up the noVNC stack when a
     * quick headless check already finds a valid session (chromium would
     * auto-detect and exit immediately, leaving a dead iframe).
     */
    public function startInstagramRemoteLogin(Request $request): JsonResponse
    {
        $resetProfile = $request->boolean('reset_profile');
        $remote = app(InstagramRemoteLoginService::class);

        if (! $resetProfile) {
            $authed = $remote->checkSession(20);
            if ($authed === true) {
                Cache::put('instagram.last_session_check', [
                    'authed' => true,
                    'username' => null,
                    'at' => now()->toIso8601String(),
                ], now()->addHours(6));

                return $this->itemResponse(['ok' => true, 'already_authenticated' => true]);
            }
        }

        $result = $remote->start($resetProfile);

        return $this->itemResponse($this->remoteLoginPayload('instagram', $result));
    }

    /** POST platforms/instagram/remote-login/poll — mirrors pollInstagramRemoteLogin(). */
    public function pollInstagramRemoteLogin(): JsonResponse
    {
        $remote = app(InstagramRemoteLoginService::class);
        $status = $remote->status();
        $logTail = $remote->tailChromeLog(6000);

        if ($status['running'] ?? false) {
            return $this->itemResponse(['running' => true, 'finished' => false, 'log_tail' => $logTail]);
        }

        $outcome = $remote->readLoginOutcome();
        $username = null;

        if (is_array($outcome) && ($outcome['authenticated'] ?? false) === true) {
            $username = $outcome['username'] ?? null;
            $authenticated = true;
            Cache::put('instagram.last_session_check', [
                'authed' => true,
                'username' => $username,
                'at' => now()->toIso8601String(),
            ], now()->addHours(6));
        } else {
            $authenticated = $remote->checkSession() === true;
            if ($authenticated) {
                Cache::put('instagram.last_session_check', [
                    'authed' => true,
                    'username' => null,
                    'at' => now()->toIso8601String(),
                ], now()->addHours(6));
            }
        }

        Cache::forget('platforms.remote_login_url.instagram');

        return $this->itemResponse([
            'running' => false,
            'finished' => true,
            'authenticated' => $authenticated,
            'username' => $username,
            'log_tail' => $logTail,
        ]);
    }

    public function stopInstagramRemoteLogin(): JsonResponse
    {
        app(InstagramRemoteLoginService::class)->stop();
        Cache::forget('platforms.remote_login_url.instagram');

        return $this->itemResponse(['instagram' => $this->instagramStatus()]);
    }

    /** POST platforms/instagram/remote-login/reset — mirrors resetInstagramProfile(). */
    public function resetInstagramProfile(): JsonResponse
    {
        Log::channel('social')->info('Instagram remote login: operator requested profile reset (central admin)', []);
        Cache::forget('platforms.remote_login_url.instagram');

        $remote = app(InstagramRemoteLoginService::class);
        $remote->stop();
        usleep(500000);

        $result = $remote->start(resetProfile: true);

        return $this->itemResponse($this->remoteLoginPayload('instagram', $result));
    }

    public function reportInstagramRemoteError(Request $request): JsonResponse
    {
        $reason = (string) $request->input('reason', 'iframe load failure');

        Log::channel('social')->warning('Instagram remote login: iframe error reported by client (central admin)', [
            'reason' => $reason,
        ]);

        app(InstagramRemoteLoginService::class)->stop();
        Cache::forget('platforms.remote_login_url.instagram');

        return $this->itemResponse(['ok' => true]);
    }

    // ---- Meta: test connection ------------------------------------------

    /**
     * POST platforms/meta/test-connection — mirrors testMetaConnection()
     * exactly: picks the newest published, alt-texted project image with a
     * local source file, generates AI caption/hashtags, and creates (but
     * never publishes) an Instagram media container. Message text is
     * byte-for-byte the same as the legacy component's flash messages so
     * the ported UI reads identically.
     */
    public function testMetaConnection(): JsonResponse
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
            return $this->itemResponse([
                'ok' => false,
                'message' => 'No eligible project image with a local source file was found for the Meta test.',
            ]);
        }

        $shortLinkUrl = $service->getShortLinkUrl($image);
        $content = $aiService->generateSocialMediaContent($image, $shortLinkUrl);

        if (! $content) {
            return $this->itemResponse([
                'ok' => false,
                'message' => 'Meta test failed during caption generation: '.$aiService->getLastError(),
            ]);
        }

        $fullCaption = trim((string) ($content['caption'] ?? ''));
        if ($shortLinkUrl !== '') {
            $fullCaption .= "\n\n🔗 {$shortLinkUrl}";
        }
        if (! empty($content['hashtags'])) {
            $fullCaption .= "\n\n".trim((string) $content['hashtags']);
        }

        $container = $service->createInstagramContainer(
            (string) $service->getPublicImageUrl($image),
            $fullCaption
        );

        if (! $container) {
            $error = $service->getLastError();

            return $this->itemResponse([
                'ok' => false,
                'message' => 'Meta test failed: '.($error['message'] ?? 'Unknown error'),
            ]);
        }

        return $this->itemResponse([
            'ok' => true,
            'message' => "Meta test succeeded for image #{$image->id}. Container ID: {$container['id']} (not published).",
        ]);
    }

    // ---- internals -------------------------------------------------------

    protected function gbpStatus(): array
    {
        $service = app(GoogleBusinessProfileService::class);
        $token = $service->getStoredToken();
        $config = config('services.google.business_profile');

        return [
            'connected' => $service->hasRefreshToken(),
            'source' => $token?->refresh_token ? 'oauth' : ($service->hasRefreshToken() ? 'env' : null),
            'email' => $token?->granted_by_email,
            'granted_at' => $token?->created_at?->toIso8601String(),
            'updated_at' => $token?->updated_at?->toIso8601String(),
            'access_token_expires_at' => $token?->access_token_expires_at?->toIso8601String(),
            'scopes' => $token?->scopes,
            'app_credentials_configured' => $service->hasOAuthCredentials(),
            'fully_configured' => $service->isConfigured(),
            // Presence booleans only, for the "Configuration Status" dot-row
            // checklist (legacy view's inline $gbpChecks,
            // platforms-settings.blade.php ~136-144). NEVER the client
            // secret / IDs themselves — just whether each is set.
            'enabled' => (bool) ($config['enabled'] ?? false),
            'client_id_configured' => ! empty($config['client_id']),
            'client_secret_configured' => ! empty($config['client_secret']),
            'account_id_configured' => ! empty($config['account_id']),
            'location_id_configured' => ! empty($config['location_id']),
            'refresh_token_present' => $service->hasRefreshToken(),
        ];
    }

    protected function gscStatus(): array
    {
        $service = app(GoogleSearchConsoleService::class);
        $token = $service->getStoredToken();

        return [
            'connected' => (bool) $token?->refresh_token,
            'write_scope' => $service->hasWriteScope(),
            'granted_at' => $token?->created_at?->toIso8601String(),
            'updated_at' => $token?->updated_at?->toIso8601String(),
            'access_token_expires_at' => $token?->access_token_expires_at?->toIso8601String(),
            'scopes' => $token?->scopes,
            'configured' => $service->isConfigured(),
        ];
    }

    protected function metaStatus(): array
    {
        $service = app(MetaSocialService::class);
        $creds = $service->getCredentials();
        // getCredentials() doesn't carry token-row timestamps; look the row
        // up directly (read-only — id/timestamps/metadata, no token value)
        // when the credentials actually came from one.
        $token = $creds['source'] === 'oauth' ? OAuthToken::forProvider('meta') : null;

        return [
            'enabled' => (bool) config('services.meta.enabled'),
            'connected' => $creds['token'] !== null,
            'source' => $creds['source'],
            'page_id' => $creds['page_id'],
            'page_name' => $creds['page_name'],
            'instagram_id' => $creds['ig_id'],
            'instagram_username' => $creds['ig_username'],
            'instagram_configured' => $service->isInstagramConfigured(),
            'facebook_configured' => $service->isFacebookConfigured(),
            'granted_by' => $token?->granted_by_email,
            'granted_at' => $token?->created_at?->toIso8601String(),
            'updated_at' => $token?->updated_at?->toIso8601String(),
            'app_credentials_configured' => filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret')),
        ];
    }

    /**
     * Full Yelp management surface: session/cookie status (as before) plus
     * everything the legacy credentials form, cookie-injection fold and
     * captcha/proxy fold need to render. Still everything read here is
     * local — a cached flag, a cookie file's mtime, a DB count, or a
     * PlatformSetting row's PRESENCE (never its value).
     */
    protected function yelpStatus(): array
    {
        $service = app(YelpBusinessService::class);
        $dead = Cache::get('yelp.session_dead');
        $isDead = is_array($dead);

        $pendingPhotoCount = ProjectImage::query()
            ->whereHas('project', fn ($q) => $q->where('is_published', true))
            ->notUploadedTo('yelp_biz')
            ->count();

        $storedPassword = (string) ($service->getPassword() ?? '');
        $hasPassword = $storedPassword !== '';

        $autoLoginResult = Cache::get('yelp.auto_login_result');

        return array_merge([
            'credentials_configured' => $service->isConfigured(),
            'email' => $service->getEmail(),
            'has_password' => $hasPassword,
            'password_len' => $hasPassword ? strlen($storedPassword) : null,
            'password_fingerprint' => $hasPassword ? substr(hash('sha256', $storedPassword), 0, 6) : null,
            'session_authenticated' => $service->quickCheckSession(),
            'session_dead' => $isDead,
            'session_dead_at' => $isDead ? ($dead['at'] ?? null) : null,
            'session_dead_note' => $isDead ? ($dead['note'] ?? null) : null,
            'pending_photo_count' => $pendingPhotoCount,
            'extension_configured' => (bool) (
                PlatformSetting::get(YelpBusinessService::SETTING_INGEST_TOKEN)
                    ?: config('services.yelp.business.cookie_ingest_token')
            ),
            'extension_paired_at' => Cache::get('yelp.extension_paired_at'),
            'has_captcha_key' => (bool) ($service->captchaKey('twocaptcha') || $service->captchaKey('anticaptcha')),
            'has_proxy' => (bool) $service->proxyUrl(),
            'can_auto_login' => $service->canAutoLogin(),
            'auto_login_at' => Cache::get('yelp.auto_login_at'),
            'auto_login_result' => is_array($autoLoginResult) ? $autoLoginResult : null,
            'node_binary_configured' => ! empty(config('services.yelp.business.node_binary')),
            'user_data_dir_configured' => ! empty(config('services.yelp.business.user_data_dir')),
        ], $this->yelpCookieStatus());
    }

    /**
     * storage/app/yelp-cookies.json headline facts — count, mtime, key
     * cookie expirations — same read as the legacy component's
     * refreshYelpCookieStatus().
     */
    protected function yelpCookieStatus(): array
    {
        $path = YelpCookieJar::path();
        if (! is_file($path)) {
            return [
                'cookie_file_count' => null,
                'cookie_file_updated_at' => null,
                'cookie_datadome_expires_at' => null,
                'cookie_bse_expires_at' => null,
                'cookie_expired_count' => 0,
            ];
        }

        $raw = (string) @file_get_contents($path);
        $data = json_decode($raw, true);
        $updatedAt = Carbon::createFromTimestamp((int) filemtime($path))->toIso8601String();

        if (! is_array($data)) {
            return [
                'cookie_file_count' => 0,
                'cookie_file_updated_at' => $updatedAt,
                'cookie_datadome_expires_at' => null,
                'cookie_bse_expires_at' => null,
                'cookie_expired_count' => 0,
            ];
        }

        $dataDomeExpiresAt = null;
        $bseExpiresAt = null;
        foreach ($data as $c) {
            $name = strtolower((string) ($c['name'] ?? ''));
            if (! isset($c['expires']) && ! isset($c['expirationDate'])) {
                continue;
            }
            $exp = (int) ($c['expires'] ?? $c['expirationDate'] ?? 0);
            if ($exp <= 0) {
                continue;
            }
            $iso = Carbon::createFromTimestamp($exp)->toIso8601String();
            if ($name === 'datadome') {
                $dataDomeExpiresAt = $iso;
            }
            if ($name === 'bse') {
                $bseExpiresAt = $iso;
            }
        }

        return [
            'cookie_file_count' => count($data),
            'cookie_file_updated_at' => $updatedAt,
            'cookie_datadome_expires_at' => $dataDomeExpiresAt,
            'cookie_bse_expires_at' => $bseExpiresAt,
            'cookie_expired_count' => count(YelpCookieJar::expiredNames($data)),
        ];
    }

    /**
     * Instagram (Puppeteer profile) status — profile presence plus the last
     * CACHED session check (the live headless probe is expensive, ~10-20s,
     * so it only ever runs from an explicit Verify/poll call, same as the
     * legacy component's refreshInstagramPuppeteerStatus()).
     */
    protected function instagramStatus(): array
    {
        $remote = app(InstagramRemoteLoginService::class);
        $cached = Cache::get('instagram.last_session_check');

        return [
            'enabled' => $remote->isEnabled(),
            'profile_exists' => $this->instagramProfileExists($remote),
            'session_authenticated' => is_array($cached) ? (bool) ($cached['authed'] ?? false) : null,
            'session_checked_at' => is_array($cached) ? ($cached['at'] ?? null) : null,
            'session_username' => is_array($cached) ? ($cached['username'] ?? null) : null,
        ];
    }

    protected function instagramProfileExists(InstagramRemoteLoginService $remote): bool
    {
        $dir = $remote->userDataDir();
        $cookieDb = $dir.'/Default/Cookies';

        return is_dir($dir) && is_file($cookieDb) && (int) @filesize($cookieDb) > 1024;
    }

    /**
     * Shared shape for every remote-login start/reset response, for both
     * Yelp and Instagram. The service's own start() return value carries
     * the raw noVNC URL (embeds a one-time VNC password) — that value is
     * cached server-side, keyed by provider, and NEVER put in the JSON
     * response. What the caller gets instead is a signed URL to
     * PlatformsViewerController, which looks the cached URL up and
     * redirects — see routes/platforms-viewer.php.
     */
    protected function remoteLoginPayload(string $provider, array $result): array
    {
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Failed to start remote login session.'];
        }

        $expiresAt = $result['expires_at'] ?? null;
        $cacheTtl = $expiresAt ? max(60, (int) $expiresAt - time()) : 900;
        Cache::put("platforms.remote_login_url.{$provider}", $result['url'], now()->addSeconds($cacheTtl));

        $viewerUrl = URL::temporarySignedRoute(
            'admin.platforms.viewer',
            now()->addMinutes(self::VIEWER_SIGNATURE_TTL_MINUTES),
            ['site' => Site::current()->primary_host, 'provider' => $provider],
        );

        return [
            'ok' => true,
            'viewer_url' => $viewerUrl,
            'started_at' => $result['started_at'] ?? null,
            'expires_at' => $expiresAt,
        ];
    }
}
