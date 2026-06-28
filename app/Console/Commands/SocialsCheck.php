<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocialsCheck extends Command
{
    protected $signature = 'socials:check {--quiet-on-success}';

    protected $description = 'HEAD-test every URL in config/socials.php and log unhealthy entries.';

    public function handle(): int
    {
        $socials = config('socials', []);
        $issues = [];

        foreach ($socials as $key => $cfg) {
            $url = $cfg['url'] ?? null;
            if (! $url) {
                continue;
            }

            try {
                $resp = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'GSConstructionLinkChecker/1.0'])
                    ->withoutRedirecting()
                    ->head($url);

                $status = $resp->status();
                $location = $resp->header('Location');

                // 403/429 from known bot-blocking hosts is acceptable
                // for this synthetic checker (Yelp/Angi/Houzz often challenge).
                $host = parse_url($url, PHP_URL_HOST) ?: '';
                $botBlockHosts = ['www.yelp.com', 'yelp.com', 'www.angi.com', 'angi.com', 'www.houzz.com', 'houzz.com'];
                $toleratedBotBlock = in_array($status, [403, 429], true) && in_array($host, $botBlockHosts, true);

                $ok = ($status >= 200 && $status < 400) || $toleratedBotBlock;

                if (! $ok) {
                    $issues[] = compact('key', 'url', 'status', 'location');
                }

                $this->line(sprintf('%-10s %d %s', $key, $status, $url));
            } catch (\Throwable $e) {
                $issues[] = ['key' => $key, 'url' => $url, 'status' => 'ERR', 'message' => $e->getMessage()];
                $this->error("{$key}: {$e->getMessage()}");
            }
        }

        if ($issues) {
            $fingerprint = md5(json_encode($issues));
            $cacheKey = 'socials:check:warn:' . now()->format('Ymd') . ':' . $fingerprint;

            if (Cache::add($cacheKey, true, now()->addHours(30))) {
                Log::channel('single')->warning('Socials check found issues', $issues);
            } else {
                Log::channel('single')->info('Socials check repeated issues suppressed', $issues);
            }

            $this->newLine();
            $this->warn(count($issues).' social link(s) need attention.');

            // Return SUCCESS even when findings exist so the Laravel scheduler
            // doesn't log an extra ERROR on top of our WARNING. Real findings
            // are surfaced via the warning log + storage/logs/socials-check.log.
            // A non-zero exit is reserved for actual command crashes.
            return self::SUCCESS;
        }

        if (! $this->option('quiet-on-success')) {
            $this->info('All social links healthy.');
        }

        return self::SUCCESS;
    }
}
