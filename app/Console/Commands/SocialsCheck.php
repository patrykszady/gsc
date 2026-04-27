<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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

                // 403 from known bot-blocking hosts is acceptable (Yelp, Angi, etc.)
                $host = parse_url($url, PHP_URL_HOST) ?: '';
                $botBlockHosts = ['www.yelp.com', 'yelp.com', 'www.angi.com', 'angi.com'];
                $tolerated403 = $status === 403 && in_array($host, $botBlockHosts, true);

                $ok = ($status >= 200 && $status < 400) || $tolerated403;

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
            Log::channel('single')->warning('Socials check found issues', $issues);
            $this->newLine();
            $this->warn(count($issues).' social link(s) need attention.');

            return self::FAILURE;
        }

        if (! $this->option('quiet-on-success')) {
            $this->info('All social links healthy.');
        }

        return self::SUCCESS;
    }
}
