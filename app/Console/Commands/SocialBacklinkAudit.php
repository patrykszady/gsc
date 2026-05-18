<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SocialBacklinkAudit extends Command
{
    protected $signature = 'socials:audit-backlinks
        {--platforms=houzz,angi : Comma-separated social config keys to audit}
        {--domain=gs.construction : Domain that should appear as a backlink}
        {--strict : Fail when a platform is unknown/unverifiable (403/429/etc.)}';

    protected $description = 'Audit whether configured social profile pages expose backlinks to your domain.';

    public function handle(): int
    {
        $keys = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('platforms')))));
        $domain = strtolower(trim((string) $this->option('domain')));
        $strict = (bool) $this->option('strict');

        if ($domain === '') {
            $this->error('The --domain option cannot be empty.');
            return self::FAILURE;
        }

        $rows = [];
        $hasMissing = false;
        $hasUnknown = false;

        foreach ($keys as $key) {
            $url = (string) config("socials.{$key}.url", '');
            if ($url === '') {
                $rows[] = [$key, 'n/a', 'no', 'Missing URL in config/socials.php'];
                $hasMissing = true;
                continue;
            }

            try {
                $response = Http::timeout(20)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ])
                    ->get($url);

                $status = $response->status();

                if (in_array($status, [401, 403, 429], true)) {
                    $rows[] = [$key, (string) $status, 'unknown', 'Bot-blocked or rate-limited. Verify manually in platform UI.'];
                    $hasUnknown = true;
                    continue;
                }

                if ($status < 200 || $status >= 400) {
                    $rows[] = [$key, (string) $status, 'unknown', 'Non-success response. Verify URL and platform accessibility.'];
                    $hasUnknown = true;
                    continue;
                }

                $body = strtolower($response->body());
                $hasBacklink = str_contains($body, $domain) || str_contains($body, 'www.' . $domain);

                if ($hasBacklink) {
                    $rows[] = [$key, (string) $status, 'yes', 'Domain reference found in HTML response.'];
                } else {
                    $rows[] = [$key, (string) $status, 'no', 'Domain not found in response HTML.'];
                    $hasMissing = true;
                }
            } catch (\Throwable $e) {
                $rows[] = [$key, 'ERR', 'unknown', $e->getMessage()];
                $hasUnknown = true;
            }
        }

        $this->table(['Platform', 'HTTP', 'Backlink', 'Notes'], $rows);

        $this->line('Manual Houzz requirement: In Houzz dashboard, each project photo should include a website/project URL pointing to https://gs.construction (or a project URL).');

        if ($hasMissing) {
            $this->warn('One or more platforms appear to be missing backlinks.');
            return self::FAILURE;
        }

        if ($hasUnknown && $strict) {
            $this->warn('Some platforms could not be verified and --strict was enabled.');
            return self::FAILURE;
        }

        if ($hasUnknown) {
            $this->info('No confirmed missing backlinks, but some platforms need manual verification.');
            return self::SUCCESS;
        }

        $this->info('All audited platforms include the target domain backlink.');
        return self::SUCCESS;
    }
}
