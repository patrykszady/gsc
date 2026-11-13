<?php

namespace App\Console\Commands;

use App\Services\YelpBusinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Log in to biz.yelp.com headlessly with the stored credentials.
 *
 * The whole backend login path in one command: no cookies to export, no
 * browser extension, no VNC session. Requires a captcha solver key and a
 * proxy — DataDome binds a solved challenge to the IP that requested it, so
 * a solve bought from the solver's own IP is rejected.
 */
class YelpLogin extends Command
{
    protected $signature = 'yelp:login {--queue : Dispatch as a background job instead of running inline}';

    protected $description = 'Log in to Yelp for Business headlessly using the stored email/password.';

    public function handle(YelpBusinessService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('Yelp email/password are not set. Add them in Admin → Platforms.');
            return self::FAILURE;
        }

        if (! $service->canAutoLogin()) {
            $this->warn('Unattended login needs BOTH a captcha key and a proxy:');
            $this->line('  captcha : ' . ($service->captchaKey('twocaptcha') || $service->captchaKey('anticaptcha') ? 'set' : 'MISSING (TWOCAPTCHA_API_KEY / ANTICAPTCHA_API_KEY)'));
            $this->line('  proxy   : ' . (config('services.yelp.business.proxy') ? 'set' : 'MISSING (YELP_BIZ_PROXY)'));
            $this->newLine();
            $this->line('DataDome ties a solved captcha to the IP that requested it, so the');
            $this->line('solver cannot help without routing the browser through the same proxy.');

            if (! $this->option('queue') && ! $this->confirm('Attempt anyway?', false)) {
                return self::FAILURE;
            }
        }

        if ($this->option('queue')) {
            \App\Jobs\YelpAutoLogin::dispatch()->onQueue('media-sync');
            $this->info('Queued a background login attempt on media-sync.');
            return self::SUCCESS;
        }

        $this->info('Launching headless Chromium and logging in…');

        // The same 30-minute floor the queued job keeps, so the job a failed
        // check queues and this command never buy two captcha solves back to
        // back (the 02:30 schedule line also stands down while it is set).
        Cache::put('yelp.auto_login_attempted', true, now()->addMinutes(30));

        $result = $service->loginHeadless();

        // What the queued job leaves behind too: the result the admin shows,
        // and for a verification prompt or a rejected password the pause and
        // the owner's email.
        $service->recordAutoLoginResult($result);

        if ($result['ok']) {
            $this->info('✓ ' . $result['hint']);
            return self::SUCCESS;
        }

        // Last line on purpose: it is what the schedule's failure hook points at.
        $this->error("✗ Yelp sign-in failed ({$result['code']}): {$result['hint']}");

        return self::FAILURE;
    }
}
