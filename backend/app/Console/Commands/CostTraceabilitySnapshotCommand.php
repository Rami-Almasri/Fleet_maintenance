<?php

namespace App\Console\Commands;

use App\Models\TraceabilitySnapshot;
use App\Services\CostVerificationService;
use Illuminate\Console\Command;

/**
 * Take today's measurement of how much of the fleet's cost can be proved, and store it.
 *
 * Run on a schedule, this turns "0% traceable" from a verdict into a trend — which is the only form in
 * which the legacy cleanup can actually be managed. It prints the movement since the last measurement, so
 * the answer to "is this getting better?" is the first thing on screen.
 *
 *     php artisan cost:traceability-snapshot
 */
class CostTraceabilitySnapshotCommand extends Command
{
    protected $signature = 'cost:traceability-snapshot {--dry : Measure and print without storing}';

    protected $description = 'Measure verified vs legacy vs unverified cost across the fleet and record it';

    public function handle(CostVerificationService $verification): int
    {
        $previous = TraceabilitySnapshot::orderByDesc('taken_on')->first();

        $this->info('Measuring how much of the fleet’s cost traces to a document…');

        if ($this->option('dry')) {
            $s = $verification->fleetSummary();
        } else {
            $snapshot = $verification->snapshot();
            $s = [
                'tickets'         => $snapshot->tickets_total,
                'total_cost'      => (float) $snapshot->total_cost,
                'verified_cost'   => (float) $snapshot->verified_cost,
                'legacy_cost'     => (float) $snapshot->legacy_cost,
                'unverified_cost' => (float) $snapshot->unverified_cost,
                'coverage_pct'    => (float) $snapshot->coverage_pct,
                'by_source'       => $snapshot->by_source ?: [],
            ];
        }

        $this->newLine();
        $this->table(['', 'Amount'], [
            ['Total repair cost', 'AED ' . number_format($s['total_cost'], 2)],
            ['Verified',          'AED ' . number_format($s['verified_cost'], 2)],
            ['Legacy unverified', 'AED ' . number_format($s['legacy_cost'], 2)],
            ['Unverified (post-rules)', 'AED ' . number_format($s['unverified_cost'], 2)],
            ['Coverage',          $s['coverage_pct'] . '%'],
        ]);

        // Movement is the point of storing these at all.
        if ($previous && ! $this->option('dry')) {
            $dv = round($s['verified_cost'] - (float) $previous->verified_cost, 2);
            $dc = round($s['coverage_pct'] - (float) $previous->coverage_pct, 2);
            $this->line('Since ' . $previous->taken_on->toDateString() . ': '
                . ($dv >= 0 ? '<fg=green>+' : '<fg=red>') . 'AED ' . number_format($dv, 2) . ' verified</>'
                . ' · coverage ' . ($dc >= 0 ? '+' : '') . $dc . ' pts');
        }

        // A post-rules unverified figure is a live problem, not a historical one — say so loudly.
        if ($s['unverified_cost'] > 0) {
            $this->newLine();
            $this->error('AED ' . number_format($s['unverified_cost'], 2)
                . ' was recorded AFTER the document rules and still has no source. Investigate — the '
                . 'closure gate should make this impossible.');
        }

        if ($s['legacy_cost'] > 0) {
            $this->newLine();
            $this->line('<options=bold>Legacy backlog</> — migrate with `php artisan cost:trace-audit` to '
                . 'find the biggest offenders, then attach the real document or record an approved adjustment.');
        }

        return self::SUCCESS;
    }
}
