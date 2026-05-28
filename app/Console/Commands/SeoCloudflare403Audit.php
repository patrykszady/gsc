<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\GscCoverageState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Probe priority URLs with the Googlebot user-agent and flag 403 responses so
 * we can spot Cloudflare WAF / Bot Fight Mode rules that are silently blocking
 * Google. GSC's "Blocked due to access forbidden (403)" bucket is usually CF.
 *
 * Outputs a markdown report listing every URL that returned 4xx/5xx when
 * spoofing Googlebot, plus a Cloudflare WAF rule template to allow verified
 * Googlebot through.
 */
class SeoCloudflare403Audit extends Command
{
    protected $signature = 'seo:cloudflare-403-audit
        {--limit=120 : Max URLs to probe (priority pages + areas)}
        {--ua=googlebot : User-agent persona: googlebot|bingbot|browser}
        {--markdown : Save markdown report}';

    protected $description = 'Probe priority URLs as Googlebot to detect Cloudflare 403/blocking issues';

    private const UAS = [
        'googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'bingbot'   => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'browser'   => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    ];

    public function handle(): int
    {
        $ua = self::UAS[(string) $this->option('ua')] ?? self::UAS['googlebot'];
        $limit = max(10, (int) $this->option('limit'));

        $base = rtrim((string) config('app.url'), '/');
        $urls = [
            $base . '/',
            $base . '/about',
            $base . '/contact',
            $base . '/testimonials',
            $base . '/projects',
            $base . '/areas-served',
            $base . '/services/kitchen-remodeling',
            $base . '/services/bathroom-remodeling',
            $base . '/services/home-remodeling',
            $base . '/services/basement-remodeling',
            $base . '/services/home-additions',
            $base . '/sitemap.xml',
            $base . '/robots.txt',
        ];

        AreaServed::query()->orderBy('city')->limit($limit - count($urls))->get()
            ->each(function ($a) use (&$urls, $base) {
                $urls[] = $base . '/areas-served/' . $a->slug;
                $urls[] = $base . '/areas-served/' . $a->slug . '/services/kitchen-remodeling';
            });

        $urls = array_slice(array_values(array_unique($urls)), 0, $limit);
        $this->info('Probing ' . count($urls) . " URLs as: {$ua}");

        $blocked = [];
        $ok = 0;
        foreach ($urls as $u) {
            try {
                $resp = Http::withHeaders([
                    'User-Agent' => $ua,
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cache-Control' => 'no-cache',
                ])->timeout(20)->withoutRedirecting()->get($u);
                $code = $resp->status();
            } catch (\Throwable $e) {
                $code = 0;
            }
            $cfRay = $resp->header('cf-ray') ?? '';
            $cfMit = $resp->header('cf-mitigated') ?? '';
            $server = $resp->header('server') ?? '';

            if ($code >= 400) {
                $blocked[] = compact('u', 'code', 'cfRay', 'cfMit', 'server');
                $this->line(sprintf('  BLOCK %3d %s %s', $code, $cfMit ?: '-', $u));
            } else {
                $ok++;
            }
            usleep(150_000);
        }

        $this->info("\nResults: OK={$ok}  BLOCKED=" . count($blocked));

        if ($this->option('markdown')) {
            $this->writeReport($urls, $blocked, $ua, $ok);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int,string> $urls
     * @param array<int,array{u:string,code:int,cfRay:string,cfMit:string,server:string}> $blocked
     */
    protected function writeReport(array $urls, array $blocked, string $ua, int $ok): void
    {
        $md = "# Cloudflare / WAF 403 Audit\n\n";
        $md .= '_Generated: ' . now()->toIso8601String() . "_\n\n";
        $md .= "User-Agent: `{$ua}`\n\n";
        $md .= '- URLs probed: ' . count($urls) . "\n";
        $md .= "- 2xx/3xx: {$ok}\n";
        $md .= '- 4xx/5xx: ' . count($blocked) . "\n\n";

        if (empty($blocked)) {
            $md .= "**No bot-targeted blocks detected.** GSC's 403 bucket is likely historical or from URLs not in this probe list.\n\n";
        } else {
            $md .= "## Blocked URLs\n\n";
            $md .= "| Status | CF-Mitigated | URL |\n|---|---|---|\n";
            foreach ($blocked as $b) {
                $md .= "| {$b['code']} | " . ($b['cfMit'] ?: '–') . " | {$b['u']} |\n";
            }
            $md .= "\n";
        }

        // Cross-reference with persisted GSC coverage states (last known forbidden).
        $gscForbidden = GscCoverageState::query()
            ->where(function ($q) {
                $q->where('coverage_state', 'like', '%forbidden%')
                  ->orWhere('page_fetch_state', 'ACCESS_DENIED');
            })
            ->orderByDesc('last_changed_at')
            ->limit(50)
            ->get(['url', 'coverage_state', 'page_fetch_state', 'last_changed_at']);

        if ($gscForbidden->isNotEmpty()) {
            $md .= "## GSC URL Inspection: forbidden / access-denied\n\n";
            $md .= "| URL | Coverage | Fetch | Last change |\n|---|---|---|---|\n";
            foreach ($gscForbidden as $g) {
                $md .= "| {$g->url} | " . ($g->coverage_state ?: '–') . " | " . ($g->page_fetch_state ?: '–') . " | " . ($g->last_changed_at?->toDateString() ?: '–') . " |\n";
            }
            $md .= "\n";
        }

        $md .= <<<'TXT'
## Cloudflare WAF: allow verified Googlebot

If blocks are present, add a **Skip** rule in **Security → WAF → Custom rules**:

- Field: `Verified Bot`  Operator: `equals`  Value: `True`
- Action: **Skip** → All remaining custom rules + Bot Fight Mode + Super Bot Fight Mode

Or equivalent expression:

```
(cf.client.bot) or (cf.verified_bot_category in {"Search Engine Crawler" "Search Engine Optimization"})
```

Then re-run this audit and request validation in Search Console → "Why pages aren't indexed" → "Blocked due to access forbidden (403)".
TXT;

        Storage::disk('local')->put('reports/cloudflare-403-audit.md', $md);
        $this->info('Saved: storage/app/private/reports/cloudflare-403-audit.md');
    }
}
