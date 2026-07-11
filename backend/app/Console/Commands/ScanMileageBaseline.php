<?php

namespace App\Console\Commands;

use App\Services\MileageBaselineService;
use Illuminate\Console\Command;

/**
 * Global Mileage Baseline scanner.
 *
 * Anchors every active car to the EARLIEST valid out_milage in its contract history, validates
 * the reading chain chronologically (drops + implausible jumps surface on /anomalies), and heals
 * the live odometer from history so the car card stops relying on hand-typed numbers.
 *
 * Safe by default: a bare `mileage:scan` is a DRY-RUN preview (nothing is written). Pass --apply
 * to persist the baseline + odometer corrections. The nightly schedule runs it with --apply after
 * om:sync, so corrections reflect freshly imported contracts.
 */
class ScanMileageBaseline extends Command
{
    protected $signature = 'mileage:scan
        {--apply : Persist the baseline + odometer corrections (default is a dry-run preview)}';

    protected $description = 'Anchor each car to its earliest contract reading, validate the mileage chain, and heal the odometer from history.';

    public function handle(MileageBaselineService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'Applying' : 'Previewing (dry-run)') . ' Global Mileage Baseline…');

        $r = $svc->apply(dryRun: ! $apply);

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Vehicles scanned', $r['vehicles_scanned']],
                ['Baselines set / updated', $r['baseline_set']],
                ['Odometers ' . ($apply ? 'corrected' : 'to correct'), $r['odometer_corrected']],
                ['Cars with mileage rollbacks', $r['rollback_cars'] . ' (' . $r['rollback_events'] . ' readings)'],
                ['Cars with mileage jumps', $r['jump_cars'] . ' (' . $r['jump_events'] . ' readings)'],
            ],
        );

        if ($r['samples']) {
            $this->newLine();
            $this->line('Sample odometer corrections (current → healed):');
            $this->table(
                ['Plate', 'From', 'To'],
                array_map(fn ($s) => [
                    $s['plate'] ?: '—',
                    $s['from'] === null ? 'empty' : number_format($s['from']),
                    number_format($s['to']),
                ], $r['samples']),
            );
        }

        if (($r['rollback_cars'] + $r['jump_cars']) > 0) {
            $this->newLine();
            $this->warn(($r['rollback_cars'] + $r['jump_cars']) . ' cars flagged with mileage anomalies — review them on the /mileage-chain-audit dashboard.');
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry-run only — re-run with --apply to persist these changes.');
        }

        return self::SUCCESS;
    }
}
