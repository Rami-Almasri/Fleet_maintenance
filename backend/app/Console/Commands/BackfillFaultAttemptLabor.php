<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAssignment;
use Illuminate\Console\Command;

/**
 * ONE-OFF migration of the legacy per-fault labor values out of the findings JSON and onto the
 * per-attempt stint ledger (maintenance_task_assignments.labor_hours) — the authoritative home the
 * Make-Ready path now writes to.
 *
 * LIVE data source (respects SoftDeletes via the Eloquent models — this reads live tickets, not
 * historical raw rows). Fill-if-null only: a stint that already carries a labor value is never
 * touched, so the command is idempotent and can be re-run safely. The findings JSON itself is left
 * as-is (legacy closing summaries still read it); only analytics moved off it.
 *
 * The legacy value was a single mutable number per fault (last write wins across attempts), so it is
 * placed on the LATEST attempt-ending stint — the best-supported attribution the old data allows.
 */
class BackfillFaultAttemptLabor extends Command
{
    protected $signature = 'faults:backfill-attempt-labor {--dry : Report what would change without writing}';

    protected $description = 'Copy legacy findings-JSON repair_hours onto each fault\'s latest attempt stint (fill-if-null, idempotent)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $written = $skippedFilled = $noStint = $noTask = 0;

        Maintenance::query()
            ->whereNotNull('findings')
            ->with(['tasks.assignments'])
            ->chunkById(200, function ($tickets) use ($dry, &$written, &$skippedFilled, &$noStint, &$noTask) {
                foreach ($tickets as $ticket) {
                    $findings = is_array($ticket->findings) ? $ticket->findings : [];
                    foreach ($findings as $f) {
                        $hours = $f['repair_hours'] ?? null;
                        $text  = trim((string) ($f['text'] ?? ''));
                        if ($text === '' || $hours === null || ! is_numeric($hours)) {
                            continue;
                        }

                        $task = $ticket->tasks->first(
                            fn (MaintenanceTask $t) => mb_strtolower(trim((string) $t->symptom)) === mb_strtolower($text)
                        );
                        if (! $task) {
                            $noTask++;
                            continue;
                        }

                        $stint = $task->assignments
                            ->whereNotNull('released_at')
                            ->whereIn('outcome', MaintenanceTaskAssignment::ATTEMPT_ENDING_OUTCOMES)
                            ->sortByDesc('released_at')
                            ->first();
                        if (! $stint) {
                            $noStint++; // on-site / legacy no-ledger fault — repair_hours on the task row already covers it
                            continue;
                        }
                        if ($stint->labor_hours !== null) {
                            $skippedFilled++;
                            continue;
                        }

                        if (! $dry) {
                            // Attribute the record to the moment the attempt actually ended — this is a
                            // data migration, not a fresh human entry, so no labor_recorded_by is claimed.
                            MaintenanceTaskAssignment::whereKey($stint->id)
                                ->whereNull('labor_hours')
                                ->update([
                                    'labor_hours'       => round((float) $hours, 2),
                                    'labor_recorded_at' => $stint->released_at,
                                ]);
                            // Keep the task's derived cache = Σ(stint labor) consistent.
                            $sum = $task->assignments()->whereNotNull('labor_hours')->sum('labor_hours');
                            MaintenanceTask::whereKey($task->id)->update([
                                'repair_hours' => $sum > 0 ? round((float) $sum, 2) : null,
                            ]);
                        }
                        $written++;
                    }
                }
            });

        $this->info(($dry ? '[DRY RUN] ' : '')
            . "Stint labor written: {$written} · already filled: {$skippedFilled} · "
            . "no attempt stint (on-site/legacy): {$noStint} · no matching fault: {$noTask}");

        return self::SUCCESS;
    }
}
