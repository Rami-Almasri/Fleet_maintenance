<?php

namespace App\Console\Commands;

use App\Models\MaintenanceTaskAssignment;
use App\Models\MaintenanceTaskWorkSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ONE-OFF reconstruction of the work-session ledger for stints written BEFORE it existed.
 *
 * What it can honestly do: a stint that carries a `work_started_at` and a `released_at` has one known
 * span of "this fault was being worked". It becomes ONE work session covering that span, stamped
 * `source = backfill` so no analysis ever mistakes it for a clocked interval.
 *
 * What it deliberately does NOT do:
 *   • invent sessions for stints with no work_started_at — there is no evidence of when work happened,
 *     and a fabricated interval is worse than an honest gap. Those faults stay unmeasured and their
 *     labor keeps the `declared` basis.
 *   • split the span into work/waiting — nobody recorded the pause, so the reconstructed session is an
 *     UPPER BOUND on active work, not a measurement of it. This is exactly why `source` exists.
 *   • touch an OPEN stint — its work is still running and belongs to the live clock, not to history.
 *
 * Idempotent: a stint that already has any session is skipped, so it is safe to re-run.
 */
class BackfillFaultWorkSessions extends Command
{
    protected $signature = 'faults:backfill-work-sessions
                            {--dry : Report what would change without writing}';

    protected $description = 'Reconstruct one work session per legacy stint that has work_started_at → released_at (idempotent)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $written = $skipped = $noSignal = 0;

        MaintenanceTaskAssignment::query()
            ->whereNotNull('work_started_at')
            ->whereNotNull('released_at')
            ->orderBy('id')
            ->chunkById(200, function ($stints) use ($dry, &$written, &$skipped, &$noSignal) {
                foreach ($stints as $stint) {
                    $exists = MaintenanceTaskWorkSession::where('maintenance_task_assignment_id', $stint->id)->exists();
                    if ($exists) {
                        $skipped++;
                        continue;
                    }
                    // Defensive: a released_at before work_started_at is corrupt, not reconstructable.
                    if ($stint->released_at->lt($stint->work_started_at)) {
                        $noSignal++;
                        $this->warn("stint {$stint->id}: released before work started — skipped");
                        continue;
                    }

                    if (! $dry) {
                        MaintenanceTaskWorkSession::create([
                            'maintenance_task_id'            => $stint->maintenance_task_id,
                            'maintenance_task_assignment_id' => $stint->id,
                            'kind'                           => MaintenanceTaskWorkSession::KIND_WORK,
                            'started_at'                     => $stint->work_started_at,
                            'ended_at'                       => $stint->released_at,
                            'started_by'                     => $stint->assigned_by,
                            'ended_by'                       => $stint->released_by,
                            'note'                           => 'Reconstructed from the stint work window — an upper bound on active work, not a clocked measurement.',
                            'source'                         => MaintenanceTaskWorkSession::SOURCE_BACKFILL,
                        ]);
                    }
                    $written++;
                }
            });

        // Stamp a basis on every EXISTING labor value so nothing is left with a null basis pretending to
        // be measured. Anything already carrying a basis is left alone.
        $laborStamped = 0;
        if (! $dry) {
            foreach (MaintenanceTaskAssignment::whereNotNull('labor_hours')->whereNull('labor_basis')->get() as $stint) {
                $stint->forceFill([
                    'labor_basis' => $stint->work_started_at !== null
                        ? MaintenanceTaskAssignment::LABOR_LEGACY_WINDOW
                        : MaintenanceTaskAssignment::LABOR_DECLARED,
                ])->save();
                $laborStamped++;
            }
        } else {
            $laborStamped = MaintenanceTaskAssignment::whereNotNull('labor_hours')->whereNull('labor_basis')->count();
        }

        $this->info(($dry ? '[DRY] ' : '') . "Work sessions reconstructed: {$written} · already had one: {$skipped} · unusable: {$noSignal}");
        $this->info(($dry ? '[DRY] ' : '') . "Legacy labor values stamped with a basis: {$laborStamped}");

        // Name the rows that now VIOLATE the ceiling. The backfill deliberately does not "fix" them —
        // changing a human's recorded hours without them knowing is precisely the silent rewrite this
        // whole change exists to prevent. They are reported so a supervisor can correct or override them.
        $violations = DB::table('maintenance_task_assignments')
            ->whereNotNull('labor_hours')
            ->whereNotNull('work_started_at')
            ->whereNotNull('released_at')
            ->whereRaw('labor_hours > TIMESTAMPDIFF(SECOND, work_started_at, released_at) / 3600 + ?', [
                \App\Services\FaultRepairTimeService::LABOR_TOLERANCE_SECONDS / 3600,
            ])
            ->get(['id', 'maintenance_task_id', 'labor_hours', 'work_started_at', 'released_at']);

        if ($violations->isEmpty()) {
            $this->info('No existing labor value exceeds its recorded work window.');
        } else {
            $this->warn("{$violations->count()} existing labor value(s) EXCEED their recorded work window — correct or override them:");
            foreach ($violations as $v) {
                $secs = strtotime($v->released_at) - strtotime($v->work_started_at);
                $this->warn(sprintf(
                    '  stint %d (fault %d): %.2fh booked against a %s work window',
                    $v->id, $v->maintenance_task_id, $v->labor_hours,
                    $secs < 60 ? $secs . 's' : round($secs / 3600, 2) . 'h',
                ));
            }
        }

        return self::SUCCESS;
    }
}
