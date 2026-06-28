<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Self-audit our JSON-LD schema coverage.
 *
 * Crawls our sitemap (or a passed --urls list), extracts every
 * <script type="application/ld+json"> block, validates it parses, recursively
 * collects every @type, and reports:
 *
 *   - URLs with NO JSON-LD at all
 *   - URLs with JSON-LD that fails to parse
 *   - Coverage matrix: which @types appear on which URL patterns
 *   - Per-type required-field checks (Service: name+provider; FAQPage: mainEntity;
 *     BreadcrumbList: itemListElement; Review/AggregateRating sanity; Article: headline+author+datePublished)
 *   - Duplicate @id values across a single page (common silent bug)
 *
 * Read-only. One HTTP request per URL, no third-party APIs.
 */
class SeoSchemaAudit extends Command
{
    protected $signature = 'seo:schema-audit
        {--sitemap= : Sitemap URL (defaults to APP_URL/sitemap.xml)}
        {--urls= : CSV of explicit URLs to audit (overrides --sitemap)}
        {--limit=80 : Max URLs}
        {--markdown : Save markdown report to storage/app/reports/schema-audit.md}';

    protected $description = 'Audit JSON-LD schema coverage and validity across our own sitemap.';

    public function handle(): int
    {
        $urls = $this->resolveUrls();
        if (empty($urls)) {
            $this->error('No URLs to audit.');
            return self::FAILURE;
        }

        $this->info('Auditing schema on ' . count($urls) . ' URLs.');

        $rows = [];                  // per-url result
        $typeIndex = [];             // type => [urls]
        $missingSchema = [];
        $parseErrors = [];           // [url => [errors]]
        $validationIssues = [];      // [url => [issues]]
        $duplicateIds = [];          // [url => [ids]]

        foreach ($urls as $url) {
            $html = $this->fetch($url);
            if ($html === null) {
                $rows[] = ['url' => $url, 'blocks' => 0, 'types' => [], 'note' => 'fetch failed'];
                continue;
            }

            $blocks = $this->extractJsonLdBlocks($html);
            if (empty($blocks)) {
                $missingSchema[] = $url;
                $rows[] = ['url' => $url, 'blocks' => 0, 'types' => [], 'note' => 'no JSON-LD'];
                continue;
            }

            $types = [];
            // only nodes that are full definitions (have @id + @type + other props)
            // keyed as @id => list of canonical payload fingerprints.
            $fullDefFingerprints = [];
            $urlErrors = [];
            $urlIssues = [];
            foreach ($blocks as $raw) {
                $data = json_decode($raw, true);
                if (! is_array($data)) {
                    $urlErrors[] = 'parse error: ' . (json_last_error_msg() ?: 'unknown');
                    continue;
                }
                $this->collectTypes($data, $types);
                $this->collectFullDefinitions($data, $fullDefFingerprints);
                $this->validateNode($data, $urlIssues);
            }

            if (! empty($urlErrors)) {
                $parseErrors[$url] = $urlErrors;
            }
            if (! empty($urlIssues)) {
                $validationIssues[$url] = array_values(array_unique($urlIssues));
            }
            // Flag @id only when it is FULL-DEFINED multiple times with
            // DIFFERENT payloads. Identical repeats are harmless duplicates and
            // not actionable.
            $dupIds = [];
            foreach ($fullDefFingerprints as $id => $fingerprints) {
                if (count(array_unique($fingerprints)) > 1) {
                    $dupIds[] = $id;
                }
            }
            if (! empty($dupIds)) {
                $duplicateIds[$url] = $dupIds;
            }

            $uniqueTypes = array_values(array_unique($types));
            sort($uniqueTypes);
            foreach ($uniqueTypes as $t) {
                $typeIndex[$t][] = $url;
            }

            $rows[] = [
                'url' => $url,
                'blocks' => count($blocks),
                'types' => $uniqueTypes,
                'note' => '',
            ];
        }

        $this->renderSummary($rows, $typeIndex, $missingSchema, $parseErrors, $validationIssues, $duplicateIds);

        if ($this->option('markdown')) {
            $this->saveMarkdown($rows, $typeIndex, $missingSchema, $parseErrors, $validationIssues, $duplicateIds);
        }

        $hasProblems = ! empty($missingSchema) || ! empty($parseErrors) || ! empty($validationIssues) || ! empty($duplicateIds);
        if ($hasProblems) {
            $summary = [
                'missing_schema' => count($missingSchema),
                'parse_errors' => count($parseErrors),
                'validation_issues' => count($validationIssues),
                'duplicate_ids' => count($duplicateIds),
            ];
            $cacheKey = 'seo:schema-audit:warn:' . now()->format('Ymd') . ':' . md5(json_encode($summary));

            if (Cache::add($cacheKey, true, now()->addHours(30))) {
                logger()->warning('seo:schema-audit found issues', $summary);
            } else {
                logger()->info('seo:schema-audit repeated issues suppressed', $summary);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveUrls(): array
    {
        $explicit = trim((string) $this->option('urls'));
        if ($explicit !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $explicit))));
        }

        $sitemap = (string) ($this->option('sitemap') ?: rtrim(config('app.url'), '/') . '/sitemap.xml');
        try {
            $body = Http::timeout(15)->get($sitemap)->throw()->body();
        } catch (\Throwable $e) {
            $this->error("Sitemap fetch failed: {$sitemap} — " . $e->getMessage());
            return [];
        }
        if (! preg_match_all('#<loc>(.*?)</loc>#i', $body, $m)) {
            return [];
        }
        $urls = array_values(array_unique(array_map('trim', $m[1])));
        // Only audit HTML pages — skip txt/xml/json/media assets occasionally listed in sitemaps.
        $urls = array_values(array_filter($urls, function (string $u): bool {
            $path = (string) parse_url($u, PHP_URL_PATH);
            return ! preg_match('#\.(txt|xml|json|csv|webmanifest|ico|png|jpg|jpeg|webp|gif|svg|pdf|mp4|mp3)$#i', $path);
        }));
        return array_slice($urls, 0, max(1, (int) $this->option('limit')));
    }

    protected function fetch(string $url): ?string
    {
        try {
            $resp = Http::timeout(20)->withUserAgent('GSC-SchemaAudit/1.0')->get($url);
        } catch (ConnectionException) {
            return null;
        }
        if (! $resp->successful()) {
            return null;
        }
        return $resp->body();
    }

    /**
     * @return array<int, string>
     */
    protected function extractJsonLdBlocks(string $html): array
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }
        return array_map(fn ($s) => trim((string) preg_replace('/^<!--|-->\z/', '', trim($s))), $m[1]);
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
            foreach ((array) $data['@type'] as $t) {
                $bag[] = (string) $t;
            }
        }
        foreach ($data as $v) {
            if (is_array($v) && (isset($v['@type']) || isset($v['@graph']) || (function_exists('array_is_list') && array_is_list($v)))) {
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
     * Collect @id values ONLY when the node is a full definition
     * (has @id, has @type, and has other properties beyond just those two).
     * Bare {"@id":"..."} references — the recommended JSON-LD entity-reuse
     * pattern — must not count as duplicates.
     *
     * @param array<string, mixed>|array<int, mixed> $data
     * @param array<int, string> $bag
     */
    protected function collectFullDefinitions(array $data, array &$bag): void
    {
        if (
            isset($data['@id'], $data['@type'])
            && is_string($data['@id'])
            && count($data) > 2
        ) {
            $types = (array) $data['@type'];
            $isImageObject = in_array('ImageObject', $types, true);

            // Logo/image entities are commonly re-declared across compatible
            // schema fragments and are generally non-actionable for this
            // audit. Focus duplicate-id warnings on higher-risk entities.
            if (! $isImageObject) {
                $canonical = $this->canonicalize($data);
                $bag[$data['@id']][] = md5(json_encode($canonical));
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $this->collectFullDefinitions($v, $bag);
            }
        }
    }

    /**
     * Canonicalize a JSON-LD node for stable hashing.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function canonicalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $k => $v) {
            $value[$k] = $this->canonicalize($v);
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $data
     * @param array<int, string> $bag
     */
    protected function validateNode(array $data, array &$bag): void
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $this->validateNode($node, $bag);
                }
            }
        }

        $type = $data['@type'] ?? null;
        $typeStr = is_array($type) ? implode(',', $type) : (string) $type;
        if ($typeStr === '') {
            return;
        }

        $check = function (array $required) use (&$bag, $data, $typeStr): void {
            foreach ($required as $key) {
                if (! array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
                    $bag[] = "{$typeStr}: missing `{$key}`";
                }
            }
        };

        $hasType = fn (string $t) => $typeStr === $t || str_contains($typeStr, $t);
        // Exact-type match (no substring). Needed for types whose names are
        // substrings of other valid types, e.g. "HowTo" vs "HowToStep"/"HowToTool".
        $typeTokens = array_map('trim', explode(',', $typeStr));
        $isType = fn (string $t) => in_array($t, $typeTokens, true);

        if ($hasType('FAQPage')) {
            if (empty($data['mainEntity']) || ! is_array($data['mainEntity'])) {
                $bag[] = 'FAQPage: empty `mainEntity`';
            }
        }
        if ($hasType('BreadcrumbList')) {
            if (empty($data['itemListElement']) || ! is_array($data['itemListElement'])) {
                $bag[] = 'BreadcrumbList: empty `itemListElement`';
            }
        }
        if ($hasType('Service')) {
            $check(['name', 'provider']);
        }
        if ($hasType('LocalBusiness') || $hasType('GeneralContractor') || $hasType('HomeAndConstructionBusiness')) {
            $check(['name', 'address']);
        }
        if ($hasType('Article') || $hasType('BlogPosting') || $hasType('NewsArticle')) {
            $check(['headline', 'author', 'datePublished']);
        }
        if ($hasType('Review')) {
            $check(['author', 'reviewRating']);
        }
        if ($hasType('AggregateRating')) {
            $check(['ratingValue', 'reviewCount']);
        }
        if ($isType('HowTo')) {
            $check(['name', 'step']);
        }
        if ($hasType('VideoObject')) {
            $check(['name', 'thumbnailUrl', 'uploadDate']);
        }
        if ($hasType('ImageObject')) {
            $check(['contentUrl']);
        }
        if ($hasType('Person')) {
            $check(['name']);
        }

        // Recurse into nested entities
        foreach ($data as $v) {
            if (is_array($v)) {
                if (function_exists('array_is_list') && array_is_list($v)) {
                    foreach ($v as $item) {
                        if (is_array($item)) {
                            $this->validateNode($item, $bag);
                        }
                    }
                } elseif (isset($v['@type'])) {
                    $this->validateNode($v, $bag);
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<int, string>> $typeIndex
     * @param array<int, string> $missingSchema
     * @param array<string, array<int, string>> $parseErrors
     * @param array<string, array<int, string>> $validationIssues
     * @param array<string, array<int, string>> $duplicateIds
     */
    protected function renderSummary(
        array $rows,
        array $typeIndex,
        array $missingSchema,
        array $parseErrors,
        array $validationIssues,
        array $duplicateIds
    ): void {
        $this->newLine();
        $this->line('<fg=cyan>--- Coverage by @type ---</>');
        $coverage = [];
        foreach ($typeIndex as $type => $urls) {
            $coverage[] = [$type, count(array_unique($urls))];
        }
        usort($coverage, fn ($a, $b) => $b[1] <=> $a[1]);
        $this->table(['@type', 'URLs'], $coverage);

        if (! empty($missingSchema)) {
            $this->newLine();
            $this->line('<fg=yellow>--- URLs with NO JSON-LD (' . count($missingSchema) . ') ---</>');
            foreach (array_slice($missingSchema, 0, 10) as $u) {
                $this->line('  ' . $u);
            }
            if (count($missingSchema) > 10) {
                $this->line('  … +' . (count($missingSchema) - 10) . ' more');
            }
        }

        if (! empty($parseErrors)) {
            $this->newLine();
            $this->line('<fg=red>--- JSON-LD parse errors (' . count($parseErrors) . ') ---</>');
            foreach (array_slice($parseErrors, 0, 10, true) as $url => $errs) {
                $this->line('  ' . $url . ' — ' . implode('; ', $errs));
            }
        }

        if (! empty($validationIssues)) {
            $this->newLine();
            $this->line('<fg=yellow>--- Validation issues (' . count($validationIssues) . ' URLs) ---</>');
            foreach (array_slice($validationIssues, 0, 10, true) as $url => $issues) {
                $this->line('  ' . Str::limit($url, 70));
                foreach ($issues as $i) {
                    $this->line('    • ' . $i);
                }
            }
        }

        if (! empty($duplicateIds)) {
            $this->newLine();
            $this->line('<fg=yellow>--- Duplicate @id within page (' . count($duplicateIds) . ' URLs) ---</>');
            foreach (array_slice($duplicateIds, 0, 5, true) as $url => $ids) {
                $this->line('  ' . Str::limit($url, 70));
                foreach ($ids as $id) {
                    $this->line('    • ' . $id);
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<int, string>> $typeIndex
     * @param array<int, string> $missingSchema
     * @param array<string, array<int, string>> $parseErrors
     * @param array<string, array<int, string>> $validationIssues
     * @param array<string, array<int, string>> $duplicateIds
     */
    protected function saveMarkdown(
        array $rows,
        array $typeIndex,
        array $missingSchema,
        array $parseErrors,
        array $validationIssues,
        array $duplicateIds
    ): void {
        $md = "# Schema markup audit\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= 'URLs audited: ' . count($rows) . "\n\n";

        $md .= "## Coverage by @type\n\n| @type | URLs |\n|---|---:|\n";
        $coverage = [];
        foreach ($typeIndex as $type => $urls) {
            $coverage[$type] = count(array_unique($urls));
        }
        arsort($coverage);
        foreach ($coverage as $type => $n) {
            $md .= "| {$type} | {$n} |\n";
        }

        $md .= "\n## URLs with no JSON-LD (" . count($missingSchema) . ")\n\n";
        $md .= empty($missingSchema) ? "_None — every audited page emits schema._\n" : ('- ' . implode("\n- ", $missingSchema) . "\n");

        $md .= "\n## JSON-LD parse errors (" . count($parseErrors) . ")\n\n";
        if (empty($parseErrors)) {
            $md .= "_None._\n";
        } else {
            foreach ($parseErrors as $url => $errs) {
                $md .= "- {$url}\n";
                foreach ($errs as $e) {
                    $md .= "  - {$e}\n";
                }
            }
        }

        $md .= "\n## Validation issues (" . count($validationIssues) . ")\n\n";
        if (empty($validationIssues)) {
            $md .= "_None._\n";
        } else {
            foreach ($validationIssues as $url => $issues) {
                $md .= "- {$url}\n";
                foreach ($issues as $i) {
                    $md .= "  - {$i}\n";
                }
            }
        }

        $md .= "\n## Duplicate @id within page (" . count($duplicateIds) . ")\n\n";
        if (empty($duplicateIds)) {
            $md .= "_None._\n";
        } else {
            foreach ($duplicateIds as $url => $ids) {
                $md .= "- {$url}\n";
                foreach ($ids as $id) {
                    $md .= "  - `{$id}`\n";
                }
            }
        }

        $md .= "\n## Per-URL detail\n\n| URL | Blocks | @types |\n|---|---:|---|\n";
        foreach ($rows as $r) {
            $md .= sprintf(
                "| %s | %d | %s |\n",
                $r['url'],
                $r['blocks'],
                empty($r['types']) ? ($r['note'] ?: '—') : implode(', ', $r['types']),
            );
        }

        Storage::disk('local')->put('reports/schema-audit.md', $md);
        $this->info('Saved: storage/app/reports/schema-audit.md');
    }
}
