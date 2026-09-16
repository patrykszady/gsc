<?php

namespace App\Support\Seo;

/**
 * Shared spend guard for the DataForSEO-spending commands (map-pack grid,
 * domain overview, backlink gap, AI mentions, keyword research, intel).
 *
 * DataForSeoService::balance() returns null when the balance CALL itself
 * failed — a network blip, an expired credential, a DataForSEO outage — not
 * when the account has no limit. Every command used to write
 * `$balance !== null && $balance < $estimate`, which reads as "if we
 * couldn't check, go ahead anyway": the one condition the guard existed to
 * catch was exactly the one that switched it off. The run then made the
 * real API calls, every one of them failed the same way, and the command
 * still exited SUCCESS having written zero rows. This fails closed instead:
 * no balance reading means no run.
 *
 * The caller fetches balance() (and getLastError()) itself, as it already
 * did, and hands the reading to precheck()/balanceCheck() — this class
 * makes no HTTP calls of its own.
 */
final class DataForSeoBudget
{
    private int $attempted = 0;

    private int $failed = 0;

    /**
     * Refuses the run before any API call: first because the estimate
     * already exceeds --budget, then because the balance can't cover it —
     * including a failed balance() (null). Returns the message to print
     * and fail on, or null when the run may proceed.
     */
    public function precheck(float $estimate, float $budget, ?float $balance, ?string $balanceError = null): ?string
    {
        if ($estimate > $budget) {
            return sprintf('Estimated cost $%.2f exceeds --budget $%.2f; narrow the run or raise --budget.', $estimate, $budget);
        }

        return $this->balanceCheck($estimate, $balance, $balanceError);
    }

    /**
     * The balance half of precheck() alone, for a caller (seo:intel) whose
     * --budget is a soft per-item spending cap rather than a hard refusal.
     */
    public function balanceCheck(float $estimate, ?float $balance, ?string $balanceError = null): ?string
    {
        if ($balance === null) {
            return sprintf('DataForSEO balance unknown (%s) — refusing to spend blind.', $balanceError ?? 'balance check failed');
        }
        if ($balance < $estimate) {
            return sprintf('DataForSEO balance $%.2f cannot cover this run ($%.2f).', $balance, $estimate);
        }

        return null;
    }

    /** Record one attempted item's outcome (a domain/keyword/prompt/family/grid point that did or didn't come back with data). */
    public function record(bool $ok): void
    {
        $this->attempted++;
        $this->failed += $ok ? 0 : 1;
    }

    /** True once every attempted item failed — the run got past the precheck and still did nothing. */
    public function allFailed(): bool
    {
        return $this->attempted > 0 && $this->failed === $this->attempted;
    }

    public function attemptedCount(): int
    {
        return $this->attempted;
    }

    public function failedCount(): int
    {
        return $this->failed;
    }
}
