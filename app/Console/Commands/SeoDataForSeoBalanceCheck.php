<?php

namespace App\Console\Commands;

use App\Services\DataForSeoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily DataForSEO account balance check.
 *
 * Every seo:* command's own --budget guard already refuses a run it can't
 * afford (see App\Support\Seo\DataForSeoBudget) — that protects against
 * overspend. It says nothing on its own about the account slowly running
 * dry between runs. The account has been funded exactly once ($51 total, no
 * auto-reload), so the failure mode that actually matters is silent
 * depletion: a season of weekly runs quietly refusing to spend, with no
 * signal until someone happens to check app.dataforseo.com. This logs the
 * balance daily and warns well before it hits zero.
 */
class SeoDataForSeoBalanceCheck extends Command
{
    /** Cache key the admin UI can read to surface the last known balance. */
    public const CACHE_KEY = 'seo.dataforseo.balance';

    protected $signature = 'seo:dataforseo-balance-check';

    protected $description = 'Log the DataForSEO account balance and warn when it drops below seo.dataforseo.min_balance';

    public function handle(DataForSeoService $dfs): int
    {
        if (! $dfs->isConfigured()) {
            $this->comment('DataForSEO not configured — skipping.');

            return self::SUCCESS;
        }

        $balance = $dfs->balance();
        if ($balance === null) {
            // Fails closed like the spend guard does: "we couldn't check" is
            // itself worth surfacing, not a reason to stay quiet.
            $this->error('DataForSEO balance check failed: '.($dfs->getLastError() ?? 'unknown error'));
            Log::warning('seo:dataforseo-balance-check failed', ['error' => $dfs->getLastError()]);

            return self::FAILURE;
        }

        $min = (float) config('seo.dataforseo.min_balance', 10.0);
        $low = $balance < $min;
        Cache::put(self::CACHE_KEY, [
            'balance' => $balance,
            'min_balance' => $min,
            'low' => $low,
            'checked_at' => now()->toIso8601String(),
        ], now()->addDays(2));

        Log::info('seo:dataforseo-balance-check', ['balance' => $balance, 'min_balance' => $min]);
        $this->info(sprintf('DataForSEO balance: $%.2f (floor $%.2f).', $balance, $min));

        if ($low) {
            Log::warning('DataForSEO balance is below the configured floor', ['balance' => $balance, 'min_balance' => $min]);
            $this->warn(sprintf('Balance $%.2f is below the $%.2f floor — top up at app.dataforseo.com.', $balance, $min));
        }

        return self::SUCCESS;
    }
}
