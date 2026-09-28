<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunSeoChannelSyncJob;
use App\Jobs\YelpAutoLogin;
use App\Models\ImagePlatformUpload;
use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Models\ProjectImage;
use App\Models\ReviewUrl;
use App\Models\Site;
use App\Models\Testimonial;
use App\Services\AiContentService;
use App\Services\GoogleBusinessProfileService;
use App\Services\GoogleSearchConsoleService;
use App\Services\InstagramRemoteLoginService;
use App\Services\MetaSocialService;
use App\Services\YelpBusinessService;
use App\Services\YelpRemoteLoginService;
use App\Support\GoogleBusinessListing;
use App\Support\Reviews\ReviewImport;
use App\Support\Seo\BingSettings;
use App\Support\Seo\ClaritySettings;
use App\Support\Seo\DataForSeoSettings;
use App\Support\Seo\PsiSettings;
use App\Support\Seo\SeoCredentialsImport;
use App\Support\Tenancy;
use App\Support\YelpCookieJar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use SsSystems\Platform\Auth\OAuthState;
use SsSystems\Platform\Google\BusinessProfile\Client as GbpClient;
use SsSystems\Platform\Google\BusinessProfile\Contracts\ListingStore;
use SsSystems\Platform\Google\BusinessProfile\Http\Concerns\ServesGbpPlatform;
use SsSystems\Platform\Google\OAuthClient;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use SsSystems\Platform\Seo\SearchConsoleSyncRule;

/**
 * Ops-domain API for the central admin's Platforms screen: connection
 * status for Google Business Profile, Google Search Console and Meta (all
 * three OAuth, via oauth_tokens — Business Profile and the Google sign-in
 * client through the kit's ServesGbpPlatform since 0.14.0, gs.construction's
 * own hooks near the end of this class), plus the FULL Yelp session-automation
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
    use ServesGbpPlatform;

    /** Providers this controller drives an OAuth dance for. Yelp/Instagram are session/cookie-based, not OAuth. */
    protected const OAUTH_PROVIDERS = ['gbp', 'gsc', 'meta'];

    /** How long a signed viewer URL stays valid — long enough to load the iframe right after start/reset, short enough to not be worth guarding further. */
    protected const VIEWER_SIGNATURE_TTL_MINUTES = 15;

    public function status(): JsonResponse
    {
        return $this->itemResponse([
            // The CRM connection, from what is on file — no call to hive
            // here; GET platforms/hive checks it for real.
            'hive' => app(PlatformsHiveController::class)->payload(live: false),
            'google' => $this->googleStatus(),
            'gbp' => $this->gbpStatus(),
            'gsc' => $this->gscStatus(),
            'meta' => $this->metaStatus(),
            'yelp' => $this->yelpStatus(),
            'instagram' => $this->instagramStatus(),
            // Houzz and Angi: the scraped review imports, each keyed by platform.
            ...collect(ReviewImport::sources())->mapWithKeys(fn (string $source) => [$source::platform() => $source::status()])->all(),
            'bing' => $this->bingStatus(),
            'clarity' => $this->clarityStatus(),
            'pagespeed' => $this->pagespeedStatus(),
            'dataforseo' => $this->dataForSeoCredentialStatus(),
        ]);
    }

    /**
     * GET platforms/{provider}/oauth-url
     *
     * The consent URL for the shared /admin-oauth/{provider}/callback
     * (routes/web.php) on this tenant's host, carrying a signed state the
     * callback verifies — no admin session needed. Business Profile is the
     * kit's (ServesGbpPlatform::gbpOauthUrl(), the ONE shared client; with
     * no client on the server it answers url null and a sentence); Search
     * Console and Meta are built here, as before.
     */
    public function oauthUrl(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        if ($provider === 'gbp') {
            return $this->gbpOauthUrl();
        }

        $redirectUri = route(OAuthClient::CALLBACK_ROUTE, ['provider' => $provider]);
        $state = OAuthState::make($provider);

        $url = match ($provider) {
            'gsc' => app(GoogleSearchConsoleService::class)->getOAuthUrl($redirectUri, $state),
            'meta' => app(MetaSocialService::class)->getOAuthUrl($redirectUri, MetaSocialService::OAUTH_SCOPES, $state),
        };

        return $this->itemResponse(['url' => $url]);
    }

    /**
     * DELETE platforms/{provider} — forgets the stored oauth_tokens row
     * (Business Profile through the kit's client, which also clears what it
     * cached for the grant). Yelp/Instagram have no OAuth token to remove,
     * so neither is a valid provider here.
     */
    public function disconnect(string $provider): Response
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        if ($provider === 'gbp') {
            return $this->disconnectGbp();
        }

        match ($provider) {
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
     * POST platforms/{platform}/reviews/sync — import that platform's new
     * reviews now, on the queue, as this tenant. Same job the Monday
     * schedule dispatches.
     */
    public function syncPlatformReviews(string $platform): JsonResponse
    {
        $source = collect(ReviewImport::sources())->first(fn (string $s) => $s::platform() === $platform);
        abort_unless($source !== null, 404);

        if (! $source::profileUrl()) {
            return $this->itemResponse(['ok' => false, 'message' => 'Add the '.$source::label().' profile URL first.']);
        }
        if ($source::isRunning()) {
            return $this->itemResponse(['ok' => false, 'message' => 'A '.$source::label().' import is already running — give it a few minutes.']);
        }

        $source::dispatchImport();

        return $this->itemResponse(['ok' => true, 'message' => 'Importing new '.$source::label().' reviews in the background — this takes a few minutes.']);
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

    /**
     * POST platforms/gsc/sync — runs the shared kit's Search Console sync
     * on the queue right now, instead of waiting for the schedule's next
     * three-hour tick. Queued rather than run inline (unlike
     * submitGscSitemaps() above) because a full paginated pull can take
     * minutes — the same reason RunSeoChannelSyncJob exists at all.
     */
    public function syncGsc(): JsonResponse
    {
        RunSeoChannelSyncJob::dispatch('seo:gsc-sync');

        return $this->itemResponse(['queued' => true]);
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
    // POST/DELETE platforms/google/credentials are ServesGbpPlatform's
    // refusals since kit 0.14.0: the Google sign-in client is the ONE shared
    // client in server configuration, never a per-site value typed into an
    // admin (the old App\Support\GoogleOAuthApp overlay is gone).

    // ---- SEO sources: Bing, Clarity, PageSpeed, DataForSEO -------------

    /**
     * POST platforms/bing/credentials — cloned from saveGoogleCredentials():
     * a blank field never overwrites what is already stored (there is no
     * "harmless to blank" identifier field here the way Yelp's email is),
     * and the fresh status block comes back, never the key itself.
     */
    public function saveBingCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['api_key'])) {
            PlatformSetting::put(BingSettings::SETTING_API_KEY, $data['api_key']);
        }

        return $this->itemResponse(['bing' => $this->bingStatus()]);
    }

    /** DELETE platforms/bing/credentials — back to whatever the server's env provides (usually nothing). */
    public function clearBingCredentials(): JsonResponse
    {
        PlatformSetting::put(BingSettings::SETTING_API_KEY, null);

        return $this->itemResponse(['bing' => $this->bingStatus()]);
    }

    public function saveClarityCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:4096'], // a JWT, ~700 chars (2026-09-23)
        ]);

        if (! empty($data['project_id'])) {
            PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, $data['project_id']);
        }
        if (! empty($data['api_token'])) {
            PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, $data['api_token']);
        }

        // The public layout caches projectId() for a few minutes (it reads
        // on every page); without this the new id would not show up there
        // until that TTL expired.
        ClaritySettings::forgetProjectIdCache();

        return $this->itemResponse(['clarity' => $this->clarityStatus()]);
    }

    public function clearClarityCredentials(): JsonResponse
    {
        PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, null);
        PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, null);
        ClaritySettings::forgetProjectIdCache();

        return $this->itemResponse(['clarity' => $this->clarityStatus()]);
    }

    /**
     * POST platforms/pagespeed/credentials — the one optional credential of
     * the four: PageSpeedInsightsService runs keyless on Google's shared
     * limit either way, so clearing this never breaks the sync, only
     * lowers its daily quota.
     */
    public function savePagespeedCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['api_key'])) {
            PlatformSetting::put(PsiSettings::SETTING_API_KEY, $data['api_key']);
        }

        return $this->itemResponse(['pagespeed' => $this->pagespeedStatus()]);
    }

    public function clearPagespeedCredentials(): JsonResponse
    {
        PlatformSetting::put(PsiSettings::SETTING_API_KEY, null);

        return $this->itemResponse(['pagespeed' => $this->pagespeedStatus()]);
    }

    /**
     * POST platforms/dataforseo/credentials — same shape as the other
     * three, but the caller is ss.systems provisioning this tenant's share
     * of its own metered account (Patryk's call, 2026-09-22) once the
     * tenant is switched on, not this site's own admin typing a key in —
     * see DataForSeoSettings.
     */
    public function saveDataForSeoCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['login'])) {
            PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, $data['login']);
        }
        if (! empty($data['password'])) {
            PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, $data['password']);
        }

        return $this->itemResponse(['dataforseo' => $this->dataForSeoCredentialStatus()]);
    }

    public function clearDataForSeoCredentials(): JsonResponse
    {
        PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, null);
        PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, null);

        return $this->itemResponse(['dataforseo' => $this->dataForSeoCredentialStatus()]);
    }

    /**
     * POST platforms/seo-credentials/import — the API door onto
     * seo:credentials-import-from-env (App\Support\Seo\SeoCredentialsImport),
     * so the central admin's SEO screen Connect Services modal can run the
     * same env-to-platform_settings copy per source without an ssh session.
     * Always a real import, never a dry run. Same rules as the command:
     * never overwrites a stored value, never logs or returns a value —
     * only which labels moved, plus each source's fresh status block.
     */
    public function importSeoCredentialsFromEnv(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', Rule::in(SeoCredentialsImport::SOURCES)],
        ]);

        $result = app(SeoCredentialsImport::class)->run($data['sources'] ?? []);

        return $this->itemResponse([
            'imported' => $result['imported'],
            'already_stored' => $result['already_stored'],
            'absent' => $result['absent'],
            'status' => [
                'bing' => $this->bingStatus(),
                'clarity' => $this->clarityStatus(),
                'pagespeed' => $this->pagespeedStatus(),
                'dataforseo' => $this->dataForSeoCredentialStatus(),
            ],
        ]);
    }

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

    // ---- Google Business Profile: the kit's ServesGbpPlatform hooks -----
    //
    // Every platforms/gbp/* endpoint (listings, listing, reviews, media) and
    // the google/gbp status blocks are the kit's since 0.14.0 — one
    // implementation for every tenant, the exact shapes ss.systems'
    // PlatformsSettings and photo pipeline read. What gs.construction differs
    // on is the hooks below.

    protected function gbpClient(): GbpClient
    {
        return app(GbpClient::class);
    }

    protected function gbpListingStore(): ListingStore
    {
        return app(ListingStore::class);
    }

    /** Search Console still signs in with OAuth here, so its callback URI goes on the shared client too. */
    protected function googleRedirectProviders(): array
    {
        return ['gbp', 'gsc'];
    }

    /**
     * One Google account can manage several businesses, and the API hands
     * every one of them to whichever site holds the grant — so this page
     * listed another client's business by name. Only listings whose website
     * is one of THIS site's hosts are offered (plus the one already linked);
     * the rest are counted and shown on request.
     */
    protected function gbpSiteHosts(): ?array
    {
        return GoogleBusinessListing::siteHosts();
    }

    /** Put the public Maps address on the Social Media page, unless the site already has a Google link of its own. */
    protected function gbpListingSaved(string $accountId, string $locationId, ?array $location): void
    {
        GoogleBusinessListing::adoptAsSocialUrl();
    }

    /**
     * Which of these Google review ids this site already holds: a
     * review_urls row for platform google carrying the id (the key
     * SyncGoogleReviews and the central admin's import write);
     * whereHas('testimonial') keeps it to THIS site's testimonials.
     */
    protected function gbpImportedReviewIds(array $reviewIds): array
    {
        return ReviewUrl::query()
            ->where('platform', 'google')
            ->whereIn('external_id', $reviewIds)
            ->whereHas('testimonial')
            ->pluck('external_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** Google reviews held as testimonials — the same two numbers the Houzz and Angi cards report. */
    protected function gbpReviewStats(): array
    {
        $googleReviews = Testimonial::query()->whereHas('reviewUrls', fn ($q) => $q->where('platform', 'google'));
        $latest = (clone $googleReviews)->max('review_date');

        return [
            'count' => $googleReviews->count(),
            'latest' => $latest ? Carbon::parse($latest)->toDateString() : null,
        ];
    }

    /** The retired publishing switch, still reported under the key this site has always sent. */
    protected function gbpStatusExtras(): array
    {
        return ['enabled' => GoogleBusinessListing::publishingEnabled()];
    }

    /**
     * The central admin's project photos arrive through the POST/DELETE
     * platforms/gbp/media pass-through — never gated by
     * GBP_PHOTOS_OWNED_BY: it is how ss.systems' uploads reach Google.
     */
    protected function gbpAcceptsMediaWrites(): bool
    {
        return true;
    }

    /**
     * One of THIS site's project photos (its project must be published),
     * as the Google copy: the dated, geotagged JPEG (captured_at/latitude/
     * longitude override the project's own date and place), ADDITIONAL, and
     * the caption — each overridable by the request, as before.
     */
    protected function gbpMediaPhoto(Request $request, array $input): array|JsonResponse
    {
        $request->validate([
            'image_id' => ['required', 'integer', function ($attribute, $value, $fail) {
                $image = ProjectImage::query()->with('project')->find($value);

                if (! $image || ! $image->project?->is_published) {
                    $fail('That image is not available — its project must be published.');
                }
            }],
        ]);

        $service = app(GoogleBusinessProfileService::class);
        $image = ProjectImage::query()->with('project')->findOrFail($request->integer('image_id'));

        $latitude = isset($input['latitude']) ? (float) $input['latitude'] : null;
        $longitude = isset($input['longitude']) ? (float) $input['longitude'] : null;
        $capturedAt = ! empty($input['captured_at']) ? Carbon::parse($input['captured_at']) : null;

        return [
            'source_url' => (string) $service->getPublicImageUrl($image, $latitude, $longitude, $capturedAt),
            'category' => $input['category'] ?? $service->mapCategory($image),
            'description' => $input['description'] ?? $service->buildDescription($image),
            'image_id' => $image->id,
        ];
    }

    /**
     * Recorded exactly as UploadProjectImageToGooglePlaces records a
     * self-upload, so the Social Media counters and DeleteGooglePlacesMedia
     * keep working for an image uploaded this way.
     */
    protected function gbpMediaUploaded(array $photo, array $media, string $accountId, string $locationId): void
    {
        ImagePlatformUpload::record((int) $photo['image_id'], ImagePlatformUpload::PLATFORM_GOOGLE_PLACES, [
            'remote_id' => $media['name'],
            'remote_url' => $media['url'],
            'metadata' => ['account_id' => $accountId, 'location_id' => $locationId],
        ]);
    }

    /**
     * remote_id is the primary match; the given image's own row is an
     * alternate locator so a caller that only knows the image still clears
     * the right row even if the stored remote_id has drifted.
     * (google_places_media_name / google_places_uploaded_at are accessors
     * over this row — ProjectImage::platformUpload() — nothing else to clear.)
     */
    protected function gbpMediaDeleted(string $mediaName, ?int $imageId): void
    {
        ImagePlatformUpload::query()
            ->where('platform', ImagePlatformUpload::PLATFORM_GOOGLE_PLACES)
            ->where(function ($query) use ($mediaName, $imageId) {
                $query->where('remote_id', $mediaName);

                if (! empty($imageId)) {
                    $query->orWhere('project_image_id', $imageId);
                }
            })
            ->delete();
    }

    /**
     * POST platforms/gbp/publishing — RETIRED 2026-09-23. It toggled the
     * separate "publishing" switch that connected-but-switched-off listings
     * tripped over; connected now means ready to post and nothing reads the
     * stored flag. Kept only so an admin build from before that change does
     * not get a 404 while the two deploy.
     */
    public function saveGbpPublishing(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        GoogleBusinessListing::setEnabled((bool) $data['enabled']);

        return response()->json(['data' => $this->gbpStatus()]);
    }

    // ---- internals -------------------------------------------------------

    protected function gscStatus(): array
    {
        $service = app(GoogleSearchConsoleService::class);
        $token = $service->getStoredToken();
        $config = config('services.google.search_console');

        // Bookkeeping from the last seo:gsc-sync run, written by
        // App\Support\Seo\SearchConsoleWriter::recordSyncRun(). Null-safe
        // throughout: a site that has never synced — or was never
        // connected — has no metadata to read yet, and the card must
        // render calmly rather than assume a run happened.
        $sync = $token?->metadata['sync'] ?? null;
        $syncedAt = $sync['finished_at'] ?? null;

        return [
            'connected' => (bool) $token?->refresh_token,
            'app_credentials_configured' => ! empty($config['client_id']) && ! empty($config['client_secret']),
            'write_scope' => $service->hasWriteScope(),
            'granted_at' => $token?->created_at?->toIso8601String(),
            'updated_at' => $token?->updated_at?->toIso8601String(),
            'access_token_expires_at' => $token?->access_token_expires_at?->toIso8601String(),
            'scopes' => $token?->scopes,
            'configured' => $service->isConfigured(),
            'last_synced_at' => $syncedAt,
            'last_sync_status' => $sync['status'] ?? null,
            'last_sync_error' => $sync['error'] ?? null,
            // Rows the last run wrote, the same key jpeterson reports.
            'last_sync_rows' => $sync ? (($sync['inserted'] ?? 0) + ($sync['updated'] ?? 0)) : null,
            // Twice the schedule's cadence (SearchConsoleSyncRule's own
            // definition of "stale") — a site still inside that window is
            // simply due any minute now, not broken.
            'sync_stale' => $syncedAt
                ? Carbon::parse($syncedAt)->lt(now()->subHours(SearchConsoleSyncRule::SYNCED_STALE_AFTER_HOURS))
                : null,
        ];
    }

    /**
     * Bing Webmaster Tools. No live sync bookkeeping exists yet (that is
     * Phase 2's BingSyncDispatcher/reconcile work), so the last-sync
     * fields stay null for now rather than faking a check — this block
     * only reports the credential itself, presence/fingerprint only,
     * never the key.
     */
    protected function bingStatus(): array
    {
        $settings = app(BingSettings::class);
        $apiKey = (string) ($settings->apiKey() ?? '');

        return [
            'configured' => $settings->isConfigured(),
            'api_key_configured' => filled($apiKey),
            'api_key_fingerprint' => filled($apiKey) ? substr(hash('sha256', $apiKey), 0, 6) : null,
            'source' => $settings->source(),
            'last_synced_at' => null,
            'last_sync_status' => null,
            'last_sync_error' => null,
        ];
    }

    /**
     * Microsoft Clarity. 'configured' needs BOTH the project id and the
     * API token — the same requirement ClaritySettings::isConfigured()
     * enforces before any export call is attempted.
     */
    protected function clarityStatus(): array
    {
        $settings = app(ClaritySettings::class);
        $projectId = (string) ($settings->projectId() ?? '');
        $apiToken = (string) ($settings->apiToken() ?? '');

        return [
            'configured' => $settings->isConfigured(),
            'project_id_configured' => filled($projectId),
            'project_id_fingerprint' => filled($projectId) ? substr(hash('sha256', $projectId), 0, 6) : null,
            'api_token_configured' => filled($apiToken),
            'api_token_fingerprint' => filled($apiToken) ? substr(hash('sha256', $apiToken), 0, 6) : null,
            'source' => $settings->source(),
            'last_synced_at' => null,
            'last_sync_status' => null,
            'last_sync_error' => null,
        ];
    }

    /**
     * PageSpeed Insights. Never a broken/disconnected state while
     * unconfigured — PSI runs keyless on Google's shared limit either way
     * (PsiSettings has no isConfigured() gate on purpose). 'configured'
     * here mirrors usingOwnKey() only so the shape matches the other three
     * blocks; the card reads using_own_key for its actual copy.
     */
    protected function pagespeedStatus(): array
    {
        $settings = app(PsiSettings::class);
        $apiKey = (string) ($settings->apiKey() ?? '');

        return [
            'configured' => $settings->usingOwnKey(),
            'using_own_key' => $settings->usingOwnKey(),
            'api_key_configured' => filled($apiKey),
            'api_key_fingerprint' => filled($apiKey) ? substr(hash('sha256', $apiKey), 0, 6) : null,
            'source' => $settings->source(),
            'last_synced_at' => null,
            'last_sync_status' => null,
            'last_sync_error' => null,
        ];
    }

    /**
     * DataForSEO. 'configured' just means this tenant has a working
     * login/password, from wherever it came from — the "switched on for
     * this site" business decision lives on ss.systems, not here (see
     * DataForSeoSettings). spend_this_month sums this month's
     * seo_intel_runs cost rows when that bookkeeping table exists; null on
     * a site that has never run seo:intel, never a live spend call.
     */
    protected function dataForSeoCredentialStatus(): array
    {
        $settings = app(DataForSeoSettings::class);
        $login = (string) ($settings->login() ?? '');
        $password = (string) ($settings->password() ?? '');

        return [
            'configured' => $settings->isConfigured(),
            'login_configured' => filled($login),
            'login_fingerprint' => filled($login) ? substr(hash('sha256', $login), 0, 6) : null,
            'password_configured' => filled($password),
            'password_fingerprint' => filled($password) ? substr(hash('sha256', $password), 0, 6) : null,
            'source' => $settings->source(),
            'last_synced_at' => null,
            'last_sync_status' => null,
            'last_sync_error' => null,
            'spend_this_month' => $this->dataForSeoSpendThisMonth(),
        ];
    }

    /** This calendar month's total seo_intel_runs cost, or null before that table exists. */
    protected function dataForSeoSpendThisMonth(): ?float
    {
        if (! Schema::hasTable('seo_intel_runs')) {
            return null;
        }

        $spent = Tenancy::table('seo_intel_runs')
            ->where('taken_on', '>=', now()->startOfMonth()->toDateString())
            ->sum('cost');

        return round((float) $spent, 2);
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
