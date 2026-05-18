<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Competitor schema-gap analysis.
 *
 * Fetches each competitor's homepage (and an inferred top service page) and
 * extracts all JSON-LD blocks. Compares the set of @type values they expose
 * against ours, producing:
 *
 *   - Schema types competitors emit that we don't (potential rich-result gap)
 *   - Schema types we emit that competitors don't (positive differentiation)
 *
 * No paid API. Read-only HTTP fetches with a generic User-Agent.
 */
class SeoCompetitorSchemaGap extends Command
{
    protected $signature = 'seo:competitor-schema-gap
        {--our-urls=/,/services/kitchen-remodeling : CSV of paths on our site to fingerprint}
        {--service-paths=/kitchen-remodeling,/services/kitchen-remodeling : Candidate paths to probe on each competitor site}
        {--markdown : Save markdown report to storage/app/reports/competitor-schema-gap.md}';

    protected $description = 'Compare JSON-LD @type coverage between gs.construction and configured competitors.';

    public function handle(): int
    {
        $competitors = (array) config('competitors.competitors', []);
        if (empty($competitors)) {
            $this->error('No competitors configured in config/competitors.php.');
            return self::FAILURE;
        }

        $ourBase = rtrim((string) config('app.url'), '/');
        $ourPaths = $this->csv($this->option('our-urls'));
        $ourTypes = $this->typesForBase($ourBase, $ourPaths);

        $this->info('Our schema types (' . count($ourTypes) . '): ' . implode(', ', $ourTypes));

        $servicePaths = $this->csv($this->option('service-paths'));
        $report = [];

        foreach ($competitors as $c) {
            $website = (string) ($c['website'] ?? '');
            if ($website === '') {
                continue;
            }
            $base = rtrim($website, '/');
            // Probe homepage + first reachable service path.
            $paths = array_merge(['/'], $servicePaths);
            $types = $this->typesForBase($base, $paths);

            $missingOnUs = array_values(array_diff($types, $ourTypes));
            $weHave = array_values(array_diff($ourTypes, $types));

            $report[] = [
                'name' => (string) $c['name'],
                'website' => $website,
                'types' => $types,
                'missing_on_us' => $missingOnUs,
                'we_lead' => $weHave,
            ];

            $this->line(sprintf(
                '  %s — types: %s',
                $c['name'],
                empty($types) ? '(none / fetch blocked)' : implode(', ', $types),
            ));
        }

        $this->newLine();
        $this->table(
            ['Competitor', 'Types they have we lack', 'Types we have they lack'],
            array_map(fn ($r) => [
                $r['name'],
                empty($r['missing_on_us']) ? '—' : implode(', ', $r['missing_on_us']),
                empty($r['we_lead']) ? '—' : implode(', ', $r['we_lead']),
            ], $report)
        );

        if ($this->option('markdown')) {
            $this->saveMarkdown($ourTypes, $report);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    protected function typesForBase(string $base, array $paths): array
    {
        $types = [];
        foreach ($paths as $p) {
            $url = $base . $p;
            $html = $this->fetch($url);
            if ($html === '') {
                continue;
            }
            foreach ($this->extractJsonLdTypes($html) as $t) {
                $types[$t] = true;
            }
        }
        $out = array_keys($types);
        sort($out);
        return $out;
    }

    protected function fetch(string $url): string
    {
        try {
            $resp = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);
            return $resp->successful() ? (string) $resp->body() : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<int, string>
     */
    protected function extractJsonLdTypes(string $html): array
    {
        $types = [];
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            // Strip HTML comments occasionally wrapping JSON-LD.
            $raw = preg_replace('/^<!--|-->\z/', '', $raw);
            $data = json_decode((string) $raw, true);
            if (! is_array($data)) {
                continue;
            }
            $this->collectTypes($data, $types);
        }
        return array_values(array_unique($types));
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $data
     * @param array<int, string> $bag
     */
    protected function collectTypes(array $data, array &$bag): void
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $this->collectTypes($node, $bag);
                }
            }
        }
        if (isset($data['@type'])) {
            $t = $data['@type'];
            if (is_array($t)) {
                foreach ($t as $one) {
                    $bag[] = (string) $one;
                }
            } else {
                $bag[] = (string) $t;
            }
        }
        // Nested entities (mainEntity, hasPart, author, etc.)
        foreach ($data as $v) {
            if (is_array($v) && (isset($v['@type']) || isset($v['@graph']) || array_is_list($v))) {
                if (array_is_list($v)) {
                    foreach ($v as $item) {
                        if (is_array($item)) {
                            $this->collectTypes($item, $bag);
                        }
                    }
                } else {
                    $this->collectTypes($v, $bag);
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function csv(mixed $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }

    /**
     * @param array<int, string> $ourTypes
     * @param array<int, array<string, mixed>> $report
     */
    protected function saveMarkdown(array $ourTypes, array $report): void
    {
        $md = "# Competitor schema-gap report\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= '## Our JSON-LD @types (' . count($ourTypes) . ")\n\n";
        $md .= empty($ourTypes) ? "_None detected._\n\n" : ('- ' . implode("\n- ", $ourTypes) . "\n\n");

        $md .= "## Per-competitor diff\n\n";
        $md .= "| Competitor | Their @types | They have, we lack | We have, they lack |\n|---|---|---|---|\n";
        foreach ($report as $r) {
            $md .= sprintf(
                "| [%s](%s) | %s | %s | %s |\n",
                str_replace('|', '\\|', $r['name']),
                $r['website'],
                empty($r['types']) ? '_blocked / none_' : implode(', ', $r['types']),
                empty($r['missing_on_us']) ? '—' : implode(', ', $r['missing_on_us']),
                empty($r['we_lead']) ? '—' : implode(', ', $r['we_lead']),
            );
        }

        $md .= "\n## Action\n\n";
        $md .= "For each entry in _They have, we lack_, evaluate whether the schema applies to gs.construction. ";
        $md .= "Common additions worth considering: `Product`, `OfferCatalog`, `ItemList`, `VideoObject`, `Article`, `Event`. ";
        $md .= "Avoid `Review` schema for content not actually present on the page.\n";

        Storage::disk('local')->put('reports/competitor-schema-gap.md', $md);
        $this->info('Saved: storage/app/reports/competitor-schema-gap.md');
    }
}
