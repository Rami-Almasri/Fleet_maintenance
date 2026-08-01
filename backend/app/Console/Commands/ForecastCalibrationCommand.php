<?php

namespace App\Console\Commands;

use App\Services\Garage\ForecastCalibration;
use Illuminate\Console\Command;

/**
 * How accurate is the garage recommendation engine's forecasting?
 *
 * `--score` runs the live loop: judge recorded dispatch decisions against what the repair actually did.
 * Idempotent (a scored decision is never re-scored), so it is safe on a schedule.
 * `--report` (the default) prints calibration — per-metric accuracy plus the garages we are most
 * biased about, which is the half anyone can act on.
 *
 * The report is a leave-one-out BACKTEST of the forecasting method against completed history, because
 * the live loop only covers decisions made after forecasting shipped and will read empty for a while.
 * The output says which it is; never quote a number from here without that context.
 */
class ForecastCalibrationCommand extends Command
{
    protected $signature = 'intelligence:forecast-calibration
                            {--score : Judge recorded dispatch decisions against actual outcomes}
                            {--report : Print the calibration report (default when no flag is given)}
                            {--dry-run : With --score, compute but write nothing}
                            {--window=90 : Comeback window in days — must match the KPI baseline}';

    protected $description = 'Compare predicted repair outcomes against reality and report calibration';

    public function handle(ForecastCalibration $calibration): int
    {
        $window = (int) $this->option('window');

        if ($this->option('score')) {
            $r = $calibration->score($window, (bool) $this->option('dry-run'));
            $this->info(($this->option('dry-run') ? '[dry run] ' : '') . "Scored {$r['scored']} decision(s).");
            $this->line("  skipped (nothing measurable yet): {$r['skipped']}");
            $this->line("  awaiting the {$window}-day comeback window: {$r['pending_window']}");

            if (! $this->option('report')) {
                return self::SUCCESS;
            }
        }

        $report = $calibration->report();

        $this->newLine();
        $this->info('Prediction accuracy (leave-one-out backtest against completed repairs)');
        $rows = [];
        foreach ($report['metrics'] as $key => $m) {
            $rows[] = [
                ucfirst($key),
                $m['accuracy'] === null ? '—' : $m['accuracy'] . '%',
                number_format($m['n']),
                $m['insufficient'] ?? ($m['calibration'] ?? null
                    ? "predicted {$m['calibration']['predicted_mean']}% vs observed {$m['calibration']['observed_rate']}%"
                    : ''),
            ];
        }
        $this->table(['Metric', 'Accuracy', 'Samples', 'Note'], $rows);

        // A fleet-wide number hides where the engine is actually blind — segment before trusting it.
        foreach ($calibration->backtestBySegment() as $dimension => $segments) {
            if (empty($segments)) {
                continue;
            }
            $this->newLine();
            $this->info('Duration accuracy by ' . $dimension . ' (worst first)');
            $this->table(
                [ucfirst($dimension), 'Accuracy', 'Repairs', 'Bias (days)'],
                array_map(fn ($s) => [$s['segment'], $s['accuracy'] . '%', number_format($s['n']), $s['bias_days']], array_slice($segments, 0, 8)),
            );
        }

        $suggestions = $calibration->suggestions();
        if (! empty($suggestions)) {
            $this->newLine();
            $this->info('Proposed improvements (review required — nothing is applied automatically)');
            $this->table(
                ['Scope', 'Target', 'Action', 'Evidence', 'Confidence'],
                array_map(fn ($s) => [$s['scope'], $s['target'], $s['action'], $s['evidence'], $s['confidence']], array_slice($suggestions, 0, 10)),
            );
        }

        if (! empty($report['duration_bias'])) {
            $this->newLine();
            $this->info('Where we are most wrong (turnaround bias)');
            $this->table(
                ['Garage', 'Repairs', 'Bias (days)', 'Reading'],
                array_map(fn ($g) => [$g['garage'], $g['n'], $g['bias_days'], $g['note']], $report['duration_bias']),
            );
        }

        $live = $report['live'];
        $this->newLine();
        $this->info("Live loop: {$live['scored']} of {$live['decisions_with_forecast']} recorded decisions scored");
        if ($live['note']) {
            $this->warn('  ' . $live['note']);
        }

        return self::SUCCESS;
    }
}
