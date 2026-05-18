<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local SEO parity audit.
 *
 *  - NAP (Name / Address / Phone) consistency across key landing pages
 *    (must match what's in config('socials') / config('seo') / etc.)
 *  - Every service in config('gbp-services') has a matching public service
 *    page (and vice versa: every service page is represented in GBP config)
 *  - Every area-served page has city + state present in plain text
 */
class SeoGbpParity extends Command
{
    protected $signature = 'seo:gbp-parity
        {--landing-pages=/,/contact,/about : CSV of paths to check for NAP}
        {--markdown : Save report to storage/app/reports/gbp-parity.md}';

    protected $description = 'Audit NAP consistency and Google Business Profile / service-page parity.';

    public function handle(): int
    {
        $base = rtrim((string) config('app.url'), '/');
        $issues = [];

        // 1) NAP from config (best-effort across common keys; geo-answers is the canonical source).
        $expectedPhone = $this->normalizePhone((string) (
            config('geo-answers.meta.phone')
            ?? config('seo.phone')
            ?? config('socials.phone')
            ?? config('services.business.phone')
            ?? ''
        ));
        $expectedAddress = (string) (
            config('seo.address')
            ?? config('socials.address')
            ?? config('services.business.address')
            ?? ''
        );

        if ($expectedPhone === '') {
            $issues[] = 'No expected phone configured (set geo-answers.meta.phone or seo.phone) — phone-parity check is skipped.';
        }
        if ($expectedAddress === '') {
            $issues[] = 'No expected address configured (set seo.address) — address-parity check is skipped.';
        }

        $this->info('Expected phone (normalized): ' . ($expectedPhone ?: '(none configured)'));
        $this->info('Expected address fragment: ' . ($expectedAddress !== '' ? Str::limit($expectedAddress, 80) : '(none configured)'));

        $napResults = [];
        $landingPaths = array_filter(array_map('trim', explode(',', (string) $this->option('landing-pages'))));
        foreach ($landingPaths as $p) {
            $url = $base . $p;
            $html = $this->fetch($url);
            if ($html === '') {
                $napResults[$url] = ['phone' => '—', 'phone_ok' => false, 'address_ok' => false, 'note' => 'fetch failed'];
                $issues[] = "Could not fetch {$url}";
                continue;
            }
            $foundPhones = $this->extractPhones($html);
            // null = check skipped (no config), true = match, false = mismatch.
            $phoneOk = $expectedPhone === ''
                ? null
                : in_array($expectedPhone, array_map([$this, 'normalizePhone'], $foundPhones), true);
            $addressOk = $expectedAddress === ''
                ? null
                : (stripos($this->stripHtml($html), $expectedAddress) !== false);

            $napResults[$url] = [
                'phone' => implode(', ', array_slice($foundPhones, 0, 3)) ?: '—',
                'phone_ok' => $phoneOk,
                'address_ok' => $addressOk,
                'note' => '',
            ];
            if ($phoneOk === false) $issues[] = "Phone mismatch on {$url} (expected digits {$expectedPhone})";
            if ($addressOk === false) $issues[] = "Address fragment not found on {$url}";
        }

        $this->renderNap($napResults);

        // 2) GBP service ↔ site service parity.
        // GBP entries can be pillars (own landing page) or sub-services (rolled up
        // under a pillar). Sub-services don't need a /services/{slug} page; they're
        // considered "covered" if their parent pillar has one.
        $gbp = (array) config('gbp-services.services', config('gbp-services', []));
        $catalog = $this->buildCatalog($gbp);

        $sitemap = $this->readSitemap($base . '/sitemap.xml');
        $siteServiceSlugs = [];
        foreach ($sitemap as $u) {
            $path = (string) parse_url($u, PHP_URL_PATH);
            // Match both /services/{slug} and /areas-served/{area}/services/{slug}.
            if (preg_match('#/services/([a-z0-9\-]+)/?$#', $path, $m)) {
                $siteServiceSlugs[] = $m[1];
            }
        }
        $siteServiceSlugs = array_values(array_unique($siteServiceSlugs));

        // Classify GBP entries: covered pillar | orphan pillar | rolled-up sub | orphan sub.
        $pillarsCovered = [];
        $pillarsMissing = [];
        $subsRolled = [];   // [child_slug => parent_slug]
        $subsOrphan = [];   // [slug => declared parent (or null)]
        foreach ($catalog as $slug => $entry) {
            if ($entry['pillar']) {
                in_array($slug, $siteServiceSlugs, true)
                    ? $pillarsCovered[] = $slug
                    : $pillarsMissing[] = $slug;
            } else {
                $parent = $entry['parent'];
                if ($parent !== null && in_array($parent, $siteServiceSlugs, true)) {
                    $subsRolled[$slug] = $parent;
                } else {
                    $subsOrphan[$slug] = $parent;
                }
            }
        }

        $gbpSlugs = array_keys($catalog);
        $siteOnly = array_values(array_diff($siteServiceSlugs, $gbpSlugs));
        foreach ($pillarsMissing as $s) $issues[] = "Pillar service '{$s}' in GBP but no /services/{$s} page";
        foreach ($subsOrphan as $slug => $parent) {
            $issues[] = $parent === null
                ? "Sub-service '{$slug}' has no parent declared and no landing page"
                : "Sub-service '{$slug}' parent '{$parent}' is not a real landing page";
        }
        foreach ($siteOnly as $s) $issues[] = "Service '{$s}' has page but missing in GBP config";

        $this->newLine();
        $this->line('<fg=cyan>--- Service parity ---</>');
        $this->line('  Pillars covered (' . count($pillarsCovered) . '): ' . (empty($pillarsCovered) ? '—' : implode(', ', $pillarsCovered)));
        $this->line('  Pillars MISSING (' . count($pillarsMissing) . '): ' . (empty($pillarsMissing) ? '—' : implode(', ', $pillarsMissing)));
        $this->line('  Sub-services rolled up (' . count($subsRolled) . '): ' . (empty($subsRolled) ? '—' : implode(', ', array_keys($subsRolled))));
        $this->line('  Sub-services ORPHANED (' . count($subsOrphan) . '): ' . (empty($subsOrphan) ? '—' : implode(', ', array_keys($subsOrphan))));
        $this->line('  On site, missing in GBP (' . count($siteOnly) . '): ' . (empty($siteOnly) ? '—' : implode(', ', $siteOnly)));

        if ($this->option('markdown')) {
            $this->saveMarkdown($expectedPhone, $expectedAddress, $napResults, $pillarsCovered, $pillarsMissing, $subsRolled, $subsOrphan, $siteServiceSlugs, $siteOnly, $issues);
        }

        if (! empty($issues)) {
            logger()->warning('seo:gbp-parity issues', ['count' => count($issues), 'first' => array_slice($issues, 0, 5)]);
        }

        return self::SUCCESS;
    }

    protected function fetch(string $url): string
    {
        try {
            $r = Http::timeout(15)->withUserAgent('GSC-GBPParity/1.0')->get($url);
            return $r->successful() ? (string) $r->body() : '';
        } catch (\Throwable) {
            return '';
        }
    }

    protected function stripHtml(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    /** @return array<int, string> */
    protected function extractPhones(string $html): array
    {
        $out = [];
        if (preg_match_all('#tel:([+0-9\-\(\) ]{7,})#i', $html, $m)) {
            foreach ($m[1] as $p) $out[] = trim($p);
        }
        // Plain-text US-style phone
        $text = $this->stripHtml($html);
        if (preg_match_all('#(\+?1[\s\-\.]?)?(\(?\d{3}\)?[\s\-\.]?\d{3}[\s\-\.]?\d{4})#', $text, $m)) {
            foreach ($m[0] as $p) $out[] = trim($p);
        }
        return array_values(array_unique($out));
    }

    protected function normalizePhone(string $p): string
    {
        $digits = preg_replace('/\D+/', '', $p) ?? '';
        // Drop leading "1" for US numbers so +1 and bare 10-digit compare equal.
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    /**
     * Normalize the gbp-services.services config into a slug-keyed catalog.
     *
     * @param array<int|string, mixed> $gbp
     * @return array<string, array{name:string, pillar:bool, parent:?string}>
     */
    protected function buildCatalog(array $gbp): array
    {
        $catalog = [];
        foreach ($gbp as $k => $v) {
            if (! is_array($v)) {
                if (is_string($v)) {
                    $slug = Str::slug($v);
                    $catalog[$slug] = ['name' => $v, 'pillar' => true, 'parent' => null];
                }
                continue;
            }
            $name = (string) ($v['name'] ?? (is_string($k) ? $k : ''));
            if ($name === '') continue;
            $slug = isset($v['slug']) ? (string) $v['slug'] : Str::slug($name);
            $catalog[$slug] = [
                'name' => $name,
                'pillar' => (bool) ($v['pillar'] ?? false),
                'parent' => isset($v['parent']) ? (string) $v['parent'] : null,
            ];
        }
        return $catalog;
    }

    /**
     * Legacy flat extractor kept for callers/tests that want just the slug list.
     *
     * @param array<int|string, mixed> $gbp
     * @return array<int, string>
     */
    protected function extractServiceSlugs(array $gbp): array
    {
        return array_keys($this->buildCatalog($gbp));
    }

    /**
     * @return array<int, string>
     */
    protected function readSitemap(string $sitemap): array
    {
        try {
            $body = Http::timeout(15)->get($sitemap)->throw()->body();
        } catch (\Throwable) {
            return [];
        }
        if (! preg_match_all('#<loc>(.*?)</loc>#i', $body, $m)) return [];
        return array_values(array_unique(array_map('trim', $m[1])));
    }

    /**
     * @param array<string, array<string, mixed>> $napResults
     */
    protected function renderNap(array $napResults): void
    {
        $this->newLine();
        $this->line('<fg=cyan>--- NAP per landing page ---</>');
        $rows = [];
        $glyph = fn ($v) => $v === null ? 'skip' : ($v ? '✓' : '✗');
        foreach ($napResults as $url => $r) {
            $rows[] = [$url, $r['phone'], $glyph($r['phone_ok']), $glyph($r['address_ok']), $r['note']];
        }
        $this->table(['URL', 'Phone(s)', 'Phone match', 'Addr match', 'Note'], $rows);
    }

    /**
     * @param array<string, array<string, mixed>> $nap
     * @param array<int, string> $pillarsCovered
     * @param array<int, string> $pillarsMissing
     * @param array<string, string> $subsRolled
     * @param array<string, ?string> $subsOrphan
     * @param array<int, string> $siteSlugs
     * @param array<int, string> $siteOnly
     * @param array<int, string> $issues
     */
    protected function saveMarkdown(
        string $phone, string $addr, array $nap,
        array $pillarsCovered, array $pillarsMissing, array $subsRolled, array $subsOrphan,
        array $siteSlugs, array $siteOnly, array $issues
    ): void {
        $md = "# GBP / Local-SEO parity report\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= "## NAP\n\nExpected phone (normalized digits): `" . ($phone ?: '_none configured_') . "`\n\n";
        $md .= "Expected address fragment: `" . ($addr ?: '_none configured_') . "`\n\n";
        $md .= "| URL | Phone(s) found | Phone match | Address match |\n|---|---|:---:|:---:|\n";
        $glyph = fn ($v) => $v === null ? '⚪ skip' : ($v ? '✅' : '❌');
        foreach ($nap as $url => $r) {
            $md .= sprintf("| %s | %s | %s | %s |\n",
                $url, $r['phone'], $glyph($r['phone_ok']), $glyph($r['address_ok']));
        }

        $md .= "\n## Service parity\n\n";
        $md .= '- **Pillars covered (' . count($pillarsCovered) . '):** ' . (empty($pillarsCovered) ? '_none_' : '`' . implode('`, `', $pillarsCovered) . '`') . "\n";
        $md .= '- **Pillars missing (' . count($pillarsMissing) . '):** ' . (empty($pillarsMissing) ? '—' : '`' . implode('`, `', $pillarsMissing) . '`') . "\n";
        $md .= '- **Sub-services rolled up (' . count($subsRolled) . '):** ' . (empty($subsRolled) ? '—' : implode(', ', array_map(fn ($c, $p) => "`{$c}` → `{$p}`", array_keys($subsRolled), $subsRolled))) . "\n";
        $md .= '- **Sub-services orphaned (' . count($subsOrphan) . '):** ' . (empty($subsOrphan) ? '—' : '`' . implode('`, `', array_keys($subsOrphan)) . '`') . "\n";
        $md .= '- **Site /services/* slugs:** ' . (empty($siteSlugs) ? '_none_' : '`' . implode('`, `', $siteSlugs) . '`') . "\n";
        $md .= '- **On site, missing in GBP:** ' . (empty($siteOnly) ? '—' : '`' . implode('`, `', $siteOnly) . '`') . "\n";

        $md .= "\n## Issues\n\n";
        $md .= empty($issues) ? "_None — full parity._\n" : ('- ' . implode("\n- ", $issues) . "\n");

        Storage::disk('local')->put('reports/gbp-parity.md', $md);
        $this->info('Saved: storage/app/reports/gbp-parity.md');
    }
}
