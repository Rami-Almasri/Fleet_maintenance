<?php

namespace Tests\Unit;

use App\Services\Expenses\ExcelVehicleExpenseProvider;
use PHPUnit\Framework\TestCase;

/**
 * The freshness verdict, exercised directly.
 *
 * This check exists because a frozen data source is the only failure mode in the cost layer that
 * produces NO symptom: nothing errors, nothing empties, and the medians keep computing over years of
 * history while describing a period that has ended. The verdict is the only thing standing between that
 * and someone quoting a stale price as current, so its boundaries are pinned here.
 */
class ExpenseFreshnessTest extends TestCase
{
    /** The verdict is private by design — it is an implementation detail of the provider's contract. */
    private function verdict(?int $days, int $lines30, int $lines90, int $prior90): array
    {
        $m = new \ReflectionMethod(ExcelVehicleExpenseProvider::class, 'verdict');
        $m->setAccessible(true);
        return $m->invoke(new ExcelVehicleExpenseProvider(), $days, $lines30, $lines90, $prior90);
    }

    public function test_a_maintained_ledger_reports_current(): void
    {
        [$status] = $this->verdict(2, 40, 120, 130);
        $this->assertSame('current', $status);
    }

    public function test_a_ledger_that_stopped_is_a_failure_not_a_warning(): void
    {
        // Past six months with nothing recorded: every cost figure derived from it describes history.
        // This has to read as broken, because it is — a warning would get lived with.
        [$status, $message] = $this->verdict(200, 0, 0, 0);
        $this->assertSame('frozen', $status);
        $this->assertStringContainsString('stopped', $message);
    }

    public function test_a_collapsing_ledger_is_caught_even_though_it_looks_recent(): void
    {
        // THE important case. Entries arrived days ago, so every "last updated" indicator says healthy —
        // but volume has fallen by 95%. Nobody has stopped maintaining the sheet; they have stopped
        // maintaining it properly, and that is exactly when cost estimates start drifting unnoticed.
        [$status, $message] = $this->verdict(1, 2, 42, 866);
        $this->assertSame('declining', $status);
        $this->assertStringContainsString('42', $message);
        $this->assertStringContainsString('866', $message);
    }

    public function test_a_healthy_volume_is_not_flagged_as_declining_by_normal_variation(): void
    {
        // Month-to-month noise must not trip the alarm, or the warning becomes background noise and
        // stops being read at all.
        [$status] = $this->verdict(3, 30, 100, 130);
        $this->assertSame('current', $status);
    }

    public function test_a_tiny_prior_period_cannot_trigger_a_decline(): void
    {
        // A source that has always been sparse is not "declining" — it is sparse. Reading a drop from
        // 8 lines to 3 as a collapse would produce a permanent false alarm on a low-volume ledger.
        [$status] = $this->verdict(5, 3, 3, 8);
        $this->assertSame('current', $status);
    }

    public function test_no_dated_lines_is_unknown_rather_than_healthy(): void
    {
        // The dangerous default. With nothing to judge, "current" would be a guess presented as a fact.
        [$status] = $this->verdict(null, 0, 0, 0);
        $this->assertSame('unknown', $status);
    }

    public function test_a_gap_in_recent_entries_is_flagged_even_when_the_last_one_is_recentish(): void
    {
        // 45 days is inside the 60-day tolerance, so the last-entry rule stays quiet — but nothing at
        // all in the past 30 days still means the import may have stopped, and that is worth saying.
        [$status, $message] = $this->verdict(45, 0, 10, 12);
        $this->assertSame('stale', $status);
        $this->assertStringContainsString('last 30 days', $message);
    }
}
