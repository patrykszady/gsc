<?php

namespace Tests\Unit;

use App\Support\Seo\DataForSeoBudget;
use PHPUnit\Framework\TestCase;

/**
 * The bug this guard exists to close: every seo:* command used to write
 * `$balance !== null && $balance < $estimate`, which skips the guard
 * entirely when balance() fails (returns null) instead of refusing the run.
 */
class DataForSeoBudgetTest extends TestCase
{
    public function test_precheck_passes_when_the_balance_covers_the_estimate(): void
    {
        $guard = new DataForSeoBudget;
        $this->assertNull($guard->precheck(1.0, 2.0, 5.0));
    }

    public function test_precheck_fails_closed_when_balance_is_null(): void
    {
        $guard = new DataForSeoBudget;
        $msg = $guard->precheck(1.0, 2.0, null, 'HTTP 500: outage');

        $this->assertNotNull($msg, 'a failed balance() must refuse the run, not wave it through');
        $this->assertStringContainsString('outage', $msg);
    }

    public function test_precheck_fails_closed_on_null_even_with_no_error_message(): void
    {
        $guard = new DataForSeoBudget;
        $this->assertNotNull($guard->precheck(1.0, 2.0, null));
    }

    public function test_precheck_refuses_before_the_balance_is_even_relevant_when_the_estimate_exceeds_budget(): void
    {
        $guard = new DataForSeoBudget;
        $msg = $guard->precheck(5.0, 1.0, 100.0); // balance is plenty; --budget is not
        $this->assertNotNull($msg);
        $this->assertStringContainsString('--budget', $msg);
    }

    public function test_precheck_refuses_when_the_balance_is_below_the_estimate(): void
    {
        $guard = new DataForSeoBudget;
        $msg = $guard->precheck(1.0, 2.0, 0.50);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('cannot cover this run', $msg);
    }

    public function test_balance_check_alone_ignores_budget_and_only_fails_closed_on_balance(): void
    {
        $guard = new DataForSeoBudget;
        // seo:intel's --budget is a soft per-family cap, not a hard refusal.
        $this->assertNull($guard->balanceCheck(1.0, 5.0));
        $this->assertNotNull($guard->balanceCheck(1.0, null));
    }

    public function test_all_failed_is_false_until_every_attempted_item_has_failed(): void
    {
        $guard = new DataForSeoBudget;
        $this->assertFalse($guard->allFailed(), 'nothing attempted yet');

        $guard->record(true);
        $guard->record(false);
        $this->assertFalse($guard->allFailed(), 'one item still succeeded');

        $guard->record(false);
        $this->assertFalse($guard->allFailed(), 'the first success still counts');
    }

    public function test_all_failed_is_true_once_every_attempt_failed(): void
    {
        $guard = new DataForSeoBudget;
        $guard->record(false);
        $guard->record(false);

        $this->assertTrue($guard->allFailed());
        $this->assertSame(2, $guard->failedCount());
        $this->assertSame(2, $guard->attemptedCount());
    }

    public function test_all_failed_is_false_when_nothing_was_ever_attempted(): void
    {
        // A run with zero competitors/keywords/prompts configured is not a
        // DataForSEO outage; it must not be reported as one.
        $guard = new DataForSeoBudget;
        $this->assertFalse($guard->allFailed());
    }
}
