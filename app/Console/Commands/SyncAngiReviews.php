<?php

namespace App\Console\Commands;

use App\Models\ReviewUrl;
use App\Models\Site;
use App\Models\Testimonial;
use App\Support\Reviews\AngiReviews;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Import the reviews on this site's Angi profile.
 *
 * Angi has no API and no per-review permalink, so the scraper reads the
 * profile page's schema.org block (full review bodies) in a real browser on
 * a virtual display, and each imported review links back to the profile.
 *
 * A scraped review is matched against the testimonials we already have —
 * same text, or same reviewer and date — so a review left on several sites
 * is stored once. An unmatched review is created; a matched one keeps the
 * text it already has and simply gains an Angi citation, since it is on
 * Angi too.
 */
class SyncAngiReviews extends Command
{
    protected $signature = 'testimonials:sync-angi-reviews
        {--profile-url= : Angi profile URL (default: the site setting from Admin → Social Media)}
        {--max-pages=10 : How many review pages to walk at most}
        {--timeout-ms=90000 : Per-page navigation timeout}
        {--proxy= : Residential proxy URL to fall back to (default: the configured scraper proxy)}
        {--direct-only : Never fall back to the residential proxy}
        {--headless : Run without a virtual display (Angi usually blocks this)}
        {--from-json= : Read scraper output from this JSON file instead of running a browser}
        {--dry-run : Show what would change without writing to the database}';

    protected $description = 'Scrape the Angi profile reviews and add new ones as testimonials.';

    /**
     * Angi publishes no per-review permalink and no edit history, so nothing
     * anchors an update: a matched review is left exactly as it is, and only
     * unseen ones are created.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            return $this->sync($dryRun);
        } catch (\Throwable $e) {
            if (! $dryRun) {
                AngiReviews::recordRun([], mb_substr($e->getMessage(), 0, 300));
            }

            throw $e;
        }
    }

    private function sync(bool $dryRun): int
    {
        $profileUrl = (string) ($this->option('profile-url') ?: AngiReviews::profileUrl() ?? '');
        if ($profileUrl === '') {
            $this->warn('No Angi profile URL is configured for '.Site::current()->name.'. Add it under Admin → Social Media → profile links.');
            if (! $dryRun) {
                AngiReviews::recordRun([], 'No Angi profile URL configured.');
            }

            return self::FAILURE;
        }

        $this->info('Reading '.$profileUrl);
        $scraped = ($fromJson = (string) $this->option('from-json')) !== ''
            ? $this->readScrapedJson($fromJson)
            : $this->scrape($profileUrl);

        if ($error = ($scraped['error'] ?? null)) {
            $message = match ($error) {
                'blocked' => 'Angi’s bot protection blocked the read, from this server and from the backup connection. It often works again on the next run.',
                'not_found' => 'Angi returned "page not found" for the profile URL — check it under Admin → Social Media.',
                'wrong_business' => 'That Angi page belongs to '.($scraped['business_name'] ?: 'another business').', not '.AngiReviews::brandName().'.',
                'brand_not_configured' => 'This site has no business name configured, so the Angi page could not be checked against one.',
                default => 'Angi’s profile page carried no review data.',
            };
            $this->warn($message);
            if (! $dryRun) {
                AngiReviews::recordRun(['scraped' => 0, 'created' => 0], $message);
            }

            return self::FAILURE;
        }

        // The scraper checks this too. Repeated here because the profile URL is
        // operator-supplied, and importing another business's reviews would be
        // worse than importing none.
        $businessName = (string) ($scraped['business_name'] ?? '');
        if ($businessName !== '' && ! AngiReviews::pageBelongsToBrand($businessName, AngiReviews::brandName())) {
            $message = 'That Angi page belongs to '.$businessName.', not '.AngiReviews::brandName().'.';
            $this->warn($message);
            if (! $dryRun) {
                AngiReviews::recordRun(['scraped' => 0, 'created' => 0], $message);
            }

            return self::FAILURE;
        }

        $payloads = collect($scraped['reviews'] ?? [])
            ->map(fn (array $review) => $this->normalize($review))
            ->filter()
            ->values();

        $this->info('Scraped '.$payloads->count().' review(s) from '.($scraped['business_name'] ?: 'the profile').'.');

        $stats = ['created' => 0, 'matched' => 0, 'linked' => 0, 'failed_parse' => count($scraped['reviews'] ?? []) - $payloads->count()];
        $existing = Testimonial::with('reviewUrls')->get();
        $seen = [];

        foreach ($payloads as $payload) {
            $key = $this->payloadKey($payload);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($match = $this->matchExisting($existing, $payload)) {
                $stats['matched']++;

                // We already hold this review from another site. Its text
                // stays as it is, but it IS on Angi, so it should cite Angi:
                // these rows are what the Platforms count and the public
                // citations read.
                if (! $match->reviewUrls->contains(fn (ReviewUrl $url) => $url->platform === 'angi')) {
                    $stats['linked']++;
                    if ($dryRun) {
                        $this->line("[DRY RUN] Cite Angi on: {$match->reviewer_name}");
                    } else {
                        $match->reviewUrls()->create(['platform' => 'angi', 'url' => $profileUrl]);
                        $match->load('reviewUrls');
                        $this->line("Cited Angi on: #{$match->id} {$match->reviewer_name}");
                    }
                }

                continue;
            }

            $label = $payload['reviewer_name'].' ('.($payload['review_date']?->toDateString() ?? 'no date').')';

            if ($dryRun) {
                $this->line("[DRY RUN] Create: {$label}");
                $stats['created']++;

                continue;
            }

            $testimonial = Testimonial::create([
                'reviewer_name' => $payload['reviewer_name'],
                'review_description' => $payload['review_description'],
                'review_date' => $payload['review_date'],
                'star_rating' => $payload['star_rating'],
            ]);
            // Angi has no per-review permalink; the profile page is the citation.
            $testimonial->reviewUrls()->create(['platform' => 'angi', 'url' => $profileUrl]);
            $existing->push($testimonial->load('reviewUrls'));
            $stats['created']++;
            $this->line("Created: #{$testimonial->id} {$label}");
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Summary');
        $this->line('  Scraped: '.$payloads->count());
        $this->line('  Created: '.$stats['created']);
        $this->line('  Already had it: '.$stats['matched'].' ('.$stats['linked'].' newly cited to Angi)');
        $this->line('  Unusable cards: '.$stats['failed_parse']);

        if (! $dryRun) {
            AngiReviews::recordRun([
                'scraped' => $payloads->count(),
                'created' => $stats['created'],
                'matched' => $stats['matched'],
                'linked' => $stats['linked'],
                'parse_failures' => $stats['failed_parse'],
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Same review, already stored? Angi gives no permalink, so this is text
     * first (a review syndicated from another site is word for word), then
     * reviewer and date.
     *
     * @param  Collection<int, Testimonial>  $existing
     * @param  array<string, mixed>  $payload
     */
    private function matchExisting($existing, array $payload): ?Testimonial
    {
        $byText = $existing->first(fn (Testimonial $t) => $this->comparable($t->review_description) === $this->comparable($payload['review_description']));
        if ($byText) {
            return $byText;
        }

        return $existing->first(function (Testimonial $t) use ($payload) {
            $sameName = mb_strtolower(trim((string) $t->reviewer_name)) === mb_strtolower(trim($payload['reviewer_name']));
            $sameDate = ($t->review_date?->toDateString() ?? null) === ($payload['review_date']?->toDateString() ?? null);

            return $sameName && $sameDate && $payload['review_date'] !== null;
        });
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>|null
     */
    private function normalize(array $review): ?array
    {
        $name = trim($this->decodeEntities((string) ($review['reviewer_name'] ?? '')));
        $description = trim($this->decodeEntities((string) ($review['review_description'] ?? '')));
        if ($name === '' || $description === '') {
            return null;
        }

        $date = null;
        $rawDate = trim((string) ($review['review_date_raw'] ?? ''));
        if ($rawDate !== '') {
            try {
                $date = Carbon::parse($rawDate)->startOfDay();
            } catch (\Throwable) {
                $date = null;
            }
        }

        $rating = isset($review['star_rating']) ? (int) $review['star_rating'] : null;
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            $rating = null;
        }

        return [
            'reviewer_name' => $name,
            'review_description' => $description,
            'review_date' => $date,
            'star_rating' => $rating,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function payloadKey(array $payload): string
    {
        return mb_strtolower(trim($payload['reviewer_name']))
            .'|'.($payload['review_date']?->toDateString() ?? 'no-date')
            .'|'.$this->comparable($payload['review_description']);
    }

    /**
     * HTML entities decoded, in case a stored review kept them: "couldn&#39;t"
     * and "couldn't" are the same review and must compare equal.
     */
    private function decodeEntities(string $value): string
    {
        for ($pass = 0; $pass < 3; $pass++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return $value;
    }

    /** Letters, digits and single spaces only — punctuation and encoding differ between sites. */
    private function comparable(?string $text, int $length = 160): string
    {
        $text = $this->decodeEntities((string) $text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = (string) preg_replace('/[^a-zA-Z0-9 ]/', '', $text);
        $text = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($text)));

        return mb_substr($text, 0, $length);
    }

    /**
     * Read a scraper payload captured earlier, so the matching can be
     * exercised without a browser.
     *
     * @return array<string, mixed>
     */
    private function readScrapedJson(string $path): array
    {
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded + ['reviews' => []] : ['error' => 'scraper_failed', 'reviews' => []];
    }

    /** Chromium's profile, per site: two tenants importing at once must not share one. */
    private function chromeProfileDir(): string
    {
        return storage_path('app/angi/chrome-profile/'.Site::current()->slug);
    }

    /**
     * Run the scraper. Angi refuses headless Chromium, so the browser runs
     * headed on a virtual display (xvfb-run) unless --headless is passed.
     *
     * @return array<string, mixed>
     */
    private function scrape(string $profileUrl): array
    {
        $script = base_path('scripts/scrape-angi-reviews.mjs');
        if (! is_file($script)) {
            return ['error' => 'scraper_missing', 'reviews' => []];
        }

        $headless = (bool) $this->option('headless');
        // xvfb-run merges the child's stderr into stdout, so the result comes
        // back through a file and stdout carries only progress.
        $resultFile = tempnam(sys_get_temp_dir(), 'angi-reviews-');
        // Cloudflare refuses some addresses outright. The scraper tries this
        // server first and falls back to residential sessions.
        $proxy = (string) ($this->option('direct-only')
            ? ''
            : ($this->option('proxy') ?: config('services.scraper.proxy', '')));

        $node = sprintf(
            'node %s --url=%s --brand=%s --out=%s --max-pages=%d --timeout-ms=%d --profile-dir=%s %s %s',
            escapeshellarg($script),
            escapeshellarg($profileUrl),
            escapeshellarg(AngiReviews::brandName()),
            escapeshellarg($resultFile),
            max(1, (int) $this->option('max-pages')),
            max(10000, (int) $this->option('timeout-ms')),
            escapeshellarg($this->chromeProfileDir()),
            $headless ? '--headless' : '',
            $proxy !== '' ? '--proxy='.escapeshellarg($proxy) : '',
        );

        $command = $headless
            ? $node
            : sprintf('%s -a --server-args=%s %s', escapeshellarg((string) config('services.scraper.xvfb', 'xvfb-run')), escapeshellarg('-screen 0 '.config('services.scraper.screen', '1440x2400x24')), $node);

        @mkdir($this->chromeProfileDir(), 0775, true);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            @unlink($resultFile);

            return ['error' => 'scraper_failed', 'reviews' => []];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $open = [$pipes[1], $pipes[2]];

        while ($open) {
            $read = $open;
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($pipe)) {
                        $key = array_search($pipe, $open, true);
                        if ($key !== false) {
                            unset($open[$key]);
                        }
                    }

                    continue;
                }

                foreach (explode("\n", trim($chunk)) as $line) {
                    if ($line !== '') {
                        $this->line('  <comment>[scraper]</comment> '.$line);
                    }
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode((string) @file_get_contents($resultFile), true);
        @unlink($resultFile);

        return is_array($decoded) ? $decoded + ['reviews' => []] : ['error' => 'scraper_failed', 'reviews' => []];
    }
}
