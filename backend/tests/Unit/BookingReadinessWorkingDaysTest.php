<?php

namespace Tests\Unit;

use App\Services\BookingReadinessService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Booking Readiness — the working-days-to-pickup maths. DB-free (like ContractEligibilityServiceTest):
 * this is the novel piece the board's urgency rests on. It locks the rule that an excluded holiday
 * falling between now and pickup is NOT a day the team can prep, so it's subtracted — meaning a booking
 * still earns its full N working-days of warning (the urgency threshold trips a day earlier per holiday).
 */
class BookingReadinessWorkingDaysTest extends TestCase
{
    private function days(string $today, string $pickup, array $excluded = []): int
    {
        return BookingReadinessService::workingDaysLeft(
            Carbon::parse($today),
            Carbon::parse($pickup),
            $excluded,
        );
    }

    public function test_plain_calendar_gap_when_no_holidays(): void
    {
        $this->assertSame(2, $this->days('2026-07-08', '2026-07-10'));
        $this->assertSame(7, $this->days('2026-07-08', '2026-07-15'));
    }

    public function test_pickup_today_is_zero(): void
    {
        $this->assertSame(0, $this->days('2026-07-08', '2026-07-08'));
    }

    public function test_a_holiday_in_the_window_subtracts_a_working_day(): void
    {
        // 4 calendar days out, but one holiday in between → only 3 working days of runway.
        $this->assertSame(3, $this->days('2026-07-08', '2026-07-12', ['2026-07-10']));
    }

    public function test_multiple_holidays_all_subtract(): void
    {
        $this->assertSame(2, $this->days('2026-07-08', '2026-07-12', ['2026-07-10', '2026-07-11']));
    }

    public function test_holiday_today_or_after_pickup_is_ignored(): void
    {
        // Today (start) and dates beyond pickup are outside the (today, pickup] window.
        $this->assertSame(4, $this->days('2026-07-08', '2026-07-12', ['2026-07-08', '2026-07-20']));
    }

    public function test_holiday_on_pickup_day_counts(): void
    {
        // The pickup day itself is inside the window — a holiday there still costs a working day.
        $this->assertSame(3, $this->days('2026-07-08', '2026-07-12', ['2026-07-12']));
    }

    public function test_never_negative(): void
    {
        $this->assertSame(0, $this->days('2026-07-08', '2026-07-10', ['2026-07-09', '2026-07-10', '2026-07-11']));
    }
}
