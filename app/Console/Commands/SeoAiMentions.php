<?php

namespace App\Console\Commands;

use App\Services\DataForSeoService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * GEO measurement: ask the AI answer engines (ChatGPT, Gemini, Perplexity,
 * Claude — via DataForSEO, web search on) the questions a homeowner asks,
 * and record whether the business is named, where in the list, and who is
 * named instead. Twice a month; ~$1.50 a run for 6 towns × 2 services × 4
 * engines. The GEO card on the SEO page reads seo_ai_mentions.
 */
class SeoAiMentions extends Command
{
    protected $signature = 'seo:ai-mentions {--towns= : CSV of towns (default: config seo.ai_mentions.towns)} {--budget=2} {--platforms=chat_gpt,gemini,perplexity,claude}';

    protected $description = 'Ask AI answer engines for contractors per town/service and record whether we are named (DataForSEO AI Optimization)';

    private const MODELS = ['chat_gpt' => 'gpt-4o-mini', 'gemini' => 'gemini-2.5-flash', 'perplexity' => 'sonar', 'claude' => 'claude-sonnet-4-5'];

    private const COST = ['chat_gpt' => 0.03, 'gemini' => 0.04, 'perplexity' => 0.01, 'claude' => 0.055];

    public function handle(DataForSeoService $dfs): int
    {
        if (! $dfs->isConfigured() || ! Schema::hasTable('seo_ai_mentions')) {
            $this->comment('DataForSEO not configured or table missing — skipping.');

            return self::SUCCESS;
        }
        $towns = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('towns') ?: implode(',', (array) config('seo.ai_mentions.towns', [])))))));
        $services = (array) config('seo.ai_mentions.services', ['kitchen remodeling' => 'kitchen-remodeling', 'bathroom remodeling' => 'bathroom-remodeling']);
        $platforms = array_values(array_intersect(array_map('trim', explode(',', (string) $this->option('platforms'))), array_keys(self::MODELS)));
        $brand = (string) config('brand.name');
        $brandKey = mb_strtolower(preg_replace('/\s*&.*$/', '', $brand) ?: $brand); // "GS Construction"
        $host = preg_replace('#^https?://(www\.)?#', '', rtrim((string) config('app.url'), '/'));

        $prompts = [];
        foreach ($towns as $town) {
            foreach ($services as $label => $slug) {
                $prompts[] = ['town' => $town, 'service' => $slug, 'text' => "Who are the best {$label} contractors in {$town}, Illinois? Name specific companies."];
            }
        }
        $estimate = 0.0;
        foreach ($platforms as $pf) {
            $estimate += count($prompts) * self::COST[$pf];
        }
        $balance = $dfs->balance();
        $this->line(sprintf('%d prompts × %d engines — estimated $%.2f · balance %s', count($prompts), count($platforms), $estimate, $balance === null ? '?' : '$' . number_format($balance, 2)));
        if ($estimate > (float) $this->option('budget')) {
            $this->error('Estimated cost exceeds --budget.');

            return self::FAILURE;
        }
        if ($balance !== null && $balance < $estimate) {
            $this->error('DataForSEO balance cannot cover this run.');

            return self::FAILURE;
        }

        $siteId = \App\Models\Site::current()?->id;
        $today = now()->toDateString();
        $asked = 0;
        $mentioned = 0;
        foreach ($prompts as $p) {
            foreach ($platforms as $pf) {
                if ($dfs->spent() >= (float) $this->option('budget')) {
                    $this->warn('Budget reached.');
                    break 2;
                }
                $answer = $dfs->llmAnswer($pf, self::MODELS[$pf], $p['text']);
                if ($answer === null) {
                    $this->line("  {$pf} / {$p['town']} {$p['service']}: no answer (" . ($dfs->getLastError() ?? '?') . ')');
                    continue;
                }
                $names = self::businessesNamed($answer);
                $lower = mb_strtolower($answer);
                $isMentioned = str_contains($lower, $brandKey) || ($host && str_contains($lower, $host));
                $rank = null;
                foreach ($names as $i => $n) {
                    if (str_contains(mb_strtolower($n), $brandKey)) {
                        $rank = $i + 1;
                        break;
                    }
                }
                Tenancy::table('seo_ai_mentions')->insert([
                    'site_id' => $siteId, 'platform' => $pf, 'model' => self::MODELS[$pf], 'prompt' => mb_substr($p['text'], 0, 255),
                    'town' => $p['town'], 'service' => $p['service'], 'mentioned' => $isMentioned, 'mention_rank' => $rank,
                    'businesses_named' => json_encode(array_slice($names, 0, 12)), 'answer_excerpt' => Str::limit($answer, 1500), 'asked_on' => $today,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $asked++;
                $mentioned += $isMentioned ? 1 : 0;
                $this->line(sprintf('  %-10s %-18s %-20s %s  named: %s', $pf, $p['town'], $p['service'], $isMentioned ? 'MENTIONED #' . ($rank ?? '?') : 'not named', implode(', ', array_slice($names, 0, 3))));
            }
        }
        Cache::forget(Tenancy::cacheKey('seo_reports_dataforseo_v1'));
        $this->info(sprintf('%d answers, named in %d. Spent $%.3f.', $asked, $mentioned, $dfs->spent()));

        return self::SUCCESS;
    }

    /** Business names as the engines format them — bold, markdown links, or "1. Name" — in document order. */
    public static function businessesNamed(string $answer): array
    {
        $found = [];
        foreach ([
            '/\*\*\[?([^*\]\n]{3,70})\]?(?:\([^)]*\))?\*\*/u',
            '/\[([^\]\n]{3,70})\]\(https?:[^)]+\)/u',
            '/^\s*\d+\.\s+\**([^*:\n]{3,70})/mu',
        ] as $re) {
            preg_match_all($re, $answer, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$name, $offset]) {
                $found[] = [$offset, $name];
            }
        }
        usort($found, fn ($a, $b) => $a[0] <=> $b[0]);

        $names = [];
        foreach ($found as [, $n]) {
            $n = trim(preg_replace('/\s+/', ' ', $n) ?? '');
            $n = preg_replace('/\s*[:\-–—]\s*$/', '', $n) ?? $n;
            if ($n === '' || preg_match('/^(open now|website|phone|address|rating|reviews?|note|top|best)\b/i', $n)) {
                continue;
            }
            if (! in_array($n, $names, true)) {
                $names[] = $n;
            }
        }

        return $names;
    }
}
