<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use SsSystems\Platform\Seo\Status\CommandHeartbeat;
use Throwable;

class TenantsRun extends Command
{
    protected $signature = 'tenants:run
        {cmd : The artisan command to run, quoted if it has arguments}
        {--site=* : Site slug, host or id. Repeatable. Omit for every active site.}
        {--include-inactive : Also run for sites whose domain is not live yet}
        {--continue-on-error : Keep going if one tenant fails}';

    protected $description = 'Run an artisan command once per tenant, with that site bound as current';

    /**
     * Each tenant's run goes through the kit's CommandHeartbeat::around()
     * (kit 0.28.0, 2026-11-12), inside Tenancy::for() so the heartbeat is
     * that tenant's: a command started with `$this->call()` raises no
     * CommandStarting/CommandFinished event, so the kit's listener never
     * hears of it and `seo:snapshot-store`, `seo:gsc-sync`, `seo:faq-bank`
     * and the rest would leave nothing for `GET seo/status` to read. A
     * failing exit and a thrown exception are recorded as failed runs, the
     * exception thrown on to the handling below; a command no data source
     * knows passes straight through.
     */
    public function handle(): int
    {
        $keys = (array) $this->option('site');

        $sites = $keys
            ? collect($keys)->map(function (string $key) {
                $site = Tenancy::find($key);
                if (! $site) {
                    $this->error("Unknown site [{$key}].");
                }

                return $site;
            })->filter()->values()
            : Tenancy::sites((bool) $this->option('include-inactive'));

        if ($keys && $sites->count() !== count($keys)) {
            return self::FAILURE;
        }

        if ($sites->isEmpty()) {
            $this->warn('No matching sites.');

            return self::SUCCESS;
        }

        [$name, $args] = $this->parse((string) $this->argument('cmd'));
        $failed = [];

        foreach ($sites as $site) {
            $this->line("<fg=cyan>┌ {$site->slug}</> <fg=gray>({$site->primary_host})</> → {$name}");

            try {
                $code = Tenancy::for($site, fn () => CommandHeartbeat::around(
                    (string) $this->argument('cmd'),
                    fn (): int => $this->call($name, $args),
                ));

                if ($code !== self::SUCCESS) {
                    $failed[] = "{$site->slug} (exit {$code})";
                    $this->line("<fg=yellow>└ {$site->slug}: exit {$code}</>");
                    if (! $this->option('continue-on-error')) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                $failed[] = "{$site->slug} ({$e->getMessage()})";
                $this->line("<fg=red>└ {$site->slug}: {$e->getMessage()}</>");

                if (! $this->option('continue-on-error')) {
                    return self::FAILURE;
                }
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('Failed for: ' . implode(', ', $failed));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Completed for {$sites->count()} site(s).");

        return self::SUCCESS;
    }

    /**
     * Split "seo:autopilot --dry-run --limit=5" into a name and an args array
     * that Artisan::call() understands.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    protected function parse(string $input): array
    {
        $parts = str_getcsv(trim($input), ' ', '"', '\\');
        $parts = array_values(array_filter($parts, fn ($p) => $p !== null && $p !== ''));
        $name = array_shift($parts) ?? '';
        $args = [];

        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                [$k, $v] = array_pad(explode('=', substr($part, 2), 2), 2, true);
                // Repeated options (--tag=a --tag=b) must become an array.
                if (array_key_exists("--{$k}", $args)) {
                    $args["--{$k}"] = array_merge((array) $args["--{$k}"], [$v]);
                } else {
                    $args["--{$k}"] = $v;
                }
            } else {
                $args[] = $part;
            }
        }

        return [$name, $args];
    }
}
