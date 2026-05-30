<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class YelpImportCookies extends Command
{
    protected $signature = 'yelp:import-cookies
        {file : Path to a JSON file exported from Cookie-Editor / EditThisCookie}
        {--replace : Replace existing cookies instead of merging}';

    protected $description = 'Import Yelp session cookies (Cookie-Editor JSON export) for puppeteer scripts to inject';

    public function handle(): int
    {
        $src = (string) $this->argument('file');
        if (! is_file($src) || ! is_readable($src)) {
            $this->error("File not found or unreadable: {$src}");

            return self::FAILURE;
        }
        $raw = file_get_contents($src);
        $data = json_decode((string) $raw, true);
        if (! is_array($data)) {
            $this->error('File is not valid JSON');

            return self::FAILURE;
        }
        if (isset($data['cookies']) && is_array($data['cookies'])) {
            $data = $data['cookies'];
        }
        if (! is_array($data) || count($data) === 0 || ! isset($data[0]['name'])) {
            $this->error('Expected an array of cookie objects with at least {name,value,domain}');

            return self::FAILURE;
        }
        $yelpCookies = array_values(array_filter($data, function ($c) {
            $d = strtolower((string) ($c['domain'] ?? ''));

            return str_contains($d, 'yelp.com');
        }));
        if (count($yelpCookies) === 0) {
            $this->error('No yelp.com cookies found in file');

            return self::FAILURE;
        }
        $dest = storage_path('app/yelp-cookies.json');
        @mkdir(dirname($dest), 0755, true);

        $merged = $yelpCookies;
        if (! $this->option('replace') && is_file($dest)) {
            $existing = json_decode((string) file_get_contents($dest), true) ?: [];
            $byKey = [];
            // New file wins on conflict — these are the freshest.
            foreach (array_merge($existing, $yelpCookies) as $c) {
                if (! isset($c['name'], $c['domain'])) continue;
                $k = strtolower(($c['domain'] ?? '').'|'.($c['path'] ?? '/').'|'.$c['name']);
                $byKey[$k] = $c;
            }
            $merged = array_values($byKey);
        }

        file_put_contents($dest, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($dest, 0600);

        $names = collect($merged)->pluck('name')->unique()->sort()->values()->all();
        $this->info('Stored '.count($merged).' cookies (added '.count($yelpCookies).' from input) → '.$dest);
        $this->line('  cookie names: '.implode(', ', $names));
        $hasSession = collect($names)->contains(fn ($n) => in_array(strtolower($n), ['s', 'bse', 'bsd'], true));
        if (! $hasSession) {
            $this->warn('  WARN: did not see a session cookie (expected one of: s, bse, bsd). Login may not actually be authenticated.');
        }

        return self::SUCCESS;
    }
}
