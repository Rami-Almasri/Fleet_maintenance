<?php

namespace Tests\Unit;

use App\Services\Garage\ForecastCalibration;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the forecast accuracy metric — the arithmetic that decides whether the engine is
 * telling the truth about itself. The DB-backed backtests are exercised by
 * `php artisan intelligence:forecast-calibration`; what matters to lock here is that the metric cannot
 * flatter the model.
 */
class ForecastCalibrationTest extends TestCase
{
    public function test_a_perfect_prediction_scores_one(): void
    {
        $this->assertSame(1.0, ForecastCalibration::accuracy(4.0, 4.0));
    }

    public function test_predicting_nothing_when_nothing_happened_is_correct_not_undefined(): void
    {
        // A 0-day repair predicted as 0 days is a perfect call. MAPE would divide by zero here, which is
        // exactly why this metric is symmetric-relative rather than percentage error.
        $this->assertSame(1.0, ForecastCalibration::accuracy(0.0, 0.0));
    }

    public function test_the_metric_is_symmetric(): void
    {
        // "Predicted 2, got 1" must be penalised exactly as much as "predicted 1, got 2" — otherwise the
        // engine could game its own score by always guessing low.
        $this->assertSame(
            ForecastCalibration::accuracy(1.0, 2.0),
            ForecastCalibration::accuracy(2.0, 1.0),
        );
    }

    public function test_the_score_never_goes_negative(): void
    {
        // One wild miss must not drag an average below the scale and hide dozens of good calls.
        $this->assertGreaterThanOrEqual(0.0, ForecastCalibration::accuracy(1000.0, 0.1));
        $this->assertGreaterThanOrEqual(0.0, ForecastCalibration::accuracy(10.0, 0.0));
    }

    public function test_a_sub_day_error_on_a_short_repair_is_not_scored_as_total_failure(): void
    {
        // Fleet turnaround is small discrete integers. Without smoothing, "predicted 0, got 1" scores a
        // flat 0 — the same as predicting 0 for a 40-day repair — which is not a useful ruler.
        $this->assertGreaterThan(0.3, ForecastCalibration::accuracy(1.0, 0.0));
        $this->assertLessThan(
            ForecastCalibration::accuracy(1.0, 0.0),
            ForecastCalibration::accuracy(40.0, 0.0),
            'a one-day miss must still score better than a forty-day miss',
        );
    }

    public function test_the_metric_is_not_degenerate_on_a_realistic_short_repair_distribution(): void
    {
        // REGRESSION. This is the real YUKON turnaround series. Its leave-one-out median flips across
        // the 0/1 boundary, and the unsmoothed metric scored EVERY observation at exactly 0 — reporting
        // "0% accuracy" for a forecaster whose mean error was a quarter of a day.
        $days = [0, 0, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 4, 4, 4, 4, 4, 0, 0, 0, 0, 1, 1, 1];
        $scores = [];
        foreach ($days as $i => $actual) {
            $others = $days;
            unset($others[$i]);
            $others = array_values($others);
            sort($others);
            $mid = intdiv(count($others), 2);
            $predicted = count($others) % 2
                ? (float) $others[$mid]
                : ((float) $others[$mid - 1] + (float) $others[$mid]) / 2;
            $scores[] = ForecastCalibration::accuracy((float) $actual, $predicted);
        }
        $mean = array_sum($scores) / count($scores);

        $this->assertGreaterThan(0.3, $mean, 'the metric must not collapse to zero on short discrete repairs');
        $this->assertLessThan(1.0, $mean, '…nor flatter a forecaster that is genuinely imprecise');
    }

    public function test_the_tolerance_floor_is_adjustable_and_tightening_it_is_stricter(): void
    {
        // The floor is a stated tolerance, not a hidden fudge — a caller measuring something with a
        // larger natural scale (cost in dirhams) can shrink it and get a harsher score.
        $lenient = ForecastCalibration::accuracy(1.0, 0.0, 1.0);
        $strict = ForecastCalibration::accuracy(1.0, 0.0, 0.0);
        $this->assertGreaterThan($strict, $lenient);
    }

    public function test_accuracy_degrades_monotonically_with_error(): void
    {
        $actual = 10.0;
        $closer = ForecastCalibration::accuracy($actual, 9.0);
        $further = ForecastCalibration::accuracy($actual, 5.0);
        $wild = ForecastCalibration::accuracy($actual, 1.0);

        $this->assertGreaterThan($further, $closer);
        $this->assertGreaterThan($wild, $further);
    }

    public function test_relative_error_not_absolute_error(): void
    {
        // Being 1 day out on a 1-day repair is a much worse call than being 1 day out on a 30-day one.
        $this->assertLessThan(
            ForecastCalibration::accuracy(30.0, 31.0),
            ForecastCalibration::accuracy(1.0, 2.0),
        );
    }
}
