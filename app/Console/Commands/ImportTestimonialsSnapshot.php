<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ImportTestimonialsSnapshot extends Command
{
    protected $signature = 'testimonials:import-snapshot
        {--path=database/data/testimonials_snapshot.json : Snapshot input path relative to project root}
        {--dry-run : Show what would change without writing to DB}';

    protected $description = 'Import testimonials snapshot and upsert testimonials + review URLs (for production deploy sync).';

    public function handle(): int
    {
        $relativePath = trim((string) $this->option('path'));
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $absolutePath = base_path($relativePath);
        if (! is_file($absolutePath)) {
            $this->error("Snapshot file not found: {$relativePath}");

            return self::FAILURE;
        }

        $raw = file_get_contents($absolutePath);
        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded) || ! isset($decoded['testimonials']) || ! is_array($decoded['testimonials'])) {
            $this->error('Invalid snapshot format. Missing testimonials array.');

            return self::FAILURE;
        }

        $items = $decoded['testimonials'];
        $stats = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'urls_created' => 0,
            'urls_updated' => 0,
            'failed' => 0,
        ];

        foreach ($items as $index => $payload) {
            if (! is_array($payload)) {
                $stats['failed']++;
                $this->warn("{$prefix}Skip malformed item at index {$index}");
                continue;
            }

            $result = $this->upsertOne($payload, $dryRun);
            foreach ($stats as $key => $value) {
                $stats[$key] += $result[$key] ?? 0;
            }
        }

        $this->newLine();
        $this->info($prefix . 'Import summary');
        $this->line('  Created testimonials: ' . $stats['created']);
        $this->line('  Updated testimonials: ' . $stats['updated']);
        $this->line('  Unchanged testimonials: ' . $stats['unchanged']);
        $this->line('  Created review URLs: ' . $stats['urls_created']);
        $this->line('  Updated review URLs: ' . $stats['urls_updated']);
        $this->line('  Failed items: ' . $stats['failed']);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{created:int,updated:int,unchanged:int,urls_created:int,urls_updated:int,failed:int}
     */
    private function upsertOne(array $payload, bool $dryRun): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'urls_created' => 0,
            'urls_updated' => 0,
            'failed' => 0,
        ];

        $reviewerName = trim((string) ($payload['reviewer_name'] ?? ''));
        $description = trim((string) ($payload['review_description'] ?? ''));
        if ($reviewerName === '' || $description === '') {
            $result['failed']++;
            return $result;
        }

        $reviewUrls = is_array($payload['review_urls'] ?? null) ? $payload['review_urls'] : [];

        $testimonial = $this->findExisting($payload, $reviewUrls);

        $mapped = [
            'reviewer_name' => $reviewerName,
            'project_location' => $payload['project_location'] ?? null,
            'project_type' => $payload['project_type'] ?? null,
            'review_description' => $description,
            'review_date' => ! empty($payload['review_date']) ? Carbon::parse((string) $payload['review_date'])->toDateString() : null,
            'star_rating' => $payload['star_rating'] ?? null,
        ];

        $action = 'unchanged';

        if (! $testimonial) {
            if (! $dryRun) {
                $testimonial = Testimonial::create($mapped);
            } else {
                $testimonial = new Testimonial($mapped);
            }
            $result['created']++;
            $action = 'created';
        } else {
            $needsUpdate = false;
            foreach ($mapped as $field => $value) {
                $existing = $testimonial->{$field};
                if ($field === 'review_date') {
                    $existing = $testimonial->review_date?->toDateString();
                }

                if ((string) ($existing ?? '') !== (string) ($value ?? '')) {
                    $needsUpdate = true;
                    break;
                }
            }

            if ($needsUpdate) {
                if (! $dryRun) {
                    $testimonial->update($mapped);
                }
                $result['updated']++;
                $action = 'updated';
            } else {
                $result['unchanged']++;
            }
        }

        if ($testimonial->exists || ! $dryRun) {
            $urlResults = $this->upsertUrls($testimonial, $reviewUrls, $dryRun);
            $result['urls_created'] += $urlResults['created'];
            $result['urls_updated'] += $urlResults['updated'];
        }

        $this->line(strtoupper($action) . ': ' . $reviewerName);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $reviewUrls
     */
    private function findExisting(array $payload, array $reviewUrls): ?Testimonial
    {
        foreach ($reviewUrls as $u) {
            $platform = strtolower(trim((string) ($u['platform'] ?? '')));
            $externalId = trim((string) ($u['external_id'] ?? ''));
            $url = trim((string) ($u['url'] ?? ''));

            if ($platform === '') {
                continue;
            }

            if ($externalId !== '') {
                $match = Testimonial::query()
                    ->whereHas('reviewUrls', function ($q) use ($platform, $externalId) {
                        $q->where('platform', $platform)->where('external_id', $externalId);
                    })
                    ->first();

                if ($match) {
                    return $match;
                }
            }

            if ($url !== '') {
                $normalizedUrl = $this->normalizeUrl($url);
                $match = Testimonial::query()
                    ->whereHas('reviewUrls', function ($q) use ($platform, $normalizedUrl) {
                        $q->where('platform', $platform)->whereRaw('LOWER(url) = ?', [strtolower($normalizedUrl)]);
                    })
                    ->first();

                if ($match) {
                    return $match;
                }
            }
        }

        $name = mb_strtolower(trim((string) ($payload['reviewer_name'] ?? '')));
        $date = ! empty($payload['review_date']) ? Carbon::parse((string) $payload['review_date'])->toDateString() : null;
        $normContent = $this->normalizeForComparison((string) ($payload['review_description'] ?? ''));

        return Testimonial::query()->get()->first(function (Testimonial $t) use ($name, $date, $normContent) {
            $nameMatch = mb_strtolower(trim((string) $t->reviewer_name)) === $name;
            $dateMatch = ($t->review_date?->toDateString() ?? null) === $date;
            $contentMatch = $this->normalizeForComparison((string) $t->review_description) === $normContent;

            return ($nameMatch && $dateMatch) || $contentMatch;
        });
    }

    /**
     * @param array<int, array<string, mixed>> $urls
     * @return array{created:int,updated:int}
     */
    private function upsertUrls(Testimonial $testimonial, array $urls, bool $dryRun): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($urls as $u) {
            $platform = strtolower(trim((string) ($u['platform'] ?? '')));
            $url = trim((string) ($u['url'] ?? ''));
            $externalId = trim((string) ($u['external_id'] ?? ''));

            if ($platform === '' || $url === '') {
                continue;
            }

            $existing = $testimonial->reviewUrls()->where('platform', $platform)->first();

            if (! $existing) {
                if (! $dryRun) {
                    $testimonial->reviewUrls()->create([
                        'platform' => $platform,
                        'url' => $this->normalizeUrl($url),
                        'external_id' => $externalId !== '' ? $externalId : null,
                    ]);
                }
                $stats['created']++;
                continue;
            }

            $next = [
                'url' => $this->normalizeUrl($url),
                'external_id' => $externalId !== '' ? $externalId : $existing->external_id,
            ];

            $changed = (string) $existing->url !== (string) $next['url']
                || (string) ($existing->external_id ?? '') !== (string) ($next['external_id'] ?? '');

            if ($changed) {
                if (! $dryRun) {
                    $existing->update($next);
                }
                $stats['updated']++;
            }
        }

        return $stats;
    }

    private function normalizeForComparison(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = preg_replace('/[^a-zA-Z0-9 ]/', '', $text);
        $text = mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));

        return (string) $text;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$path.$query;
    }
}
