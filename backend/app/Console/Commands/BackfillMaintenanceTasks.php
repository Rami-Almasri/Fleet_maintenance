<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Migrate historical maintenance tickets from the flat `findings` JSON into first-class
 * maintenance_tasks (one routable fault per finding), seed each fault's garage "stint", and
 * re-attribute existing part/labor cost lines to the fault they repair — so NO repair record or
 * cost association is lost in the transition to the container model.
 *
 * Scope: WORKFLOW TICKETS only (workflow_status not null). Legacy sheet/manual workshop events that
 * never entered the workflow keep their existing representation untouched (they are not faults to route).
 *
 * Safety:
 *   --dry-run        run the full migration inside a transaction and roll it back, printing the plan
 *   --ticket=ID      backfill a single ticket (used for the go-live validation pass)
 *   --force          re-backfill tickets that already have tasks (drops & rebuilds their tasks first)
 * Idempotent by default: a ticket that already has tasks is skipped.
 *
 * Cost preservation: itemised line items are matched to their fault by `finding_text`; unmatched lines
 * and legacy lump-sum `cost` stay at the ticket level (a general, fault-less charge) and still count in
 * the ticket total — the grand total never changes, it only gains a per-fault breakdown where possible.
 */
class BackfillMaintenanceTasks extends Command
{
    protected $signature = 'maintenance:backfill-tasks
                            {--ticket= : Only backfill this maintenance (ticket) id}
                            {--dry-run : Run inside a rolled-back transaction and report, writing nothing}
                            {--force : Rebuild tasks for tickets that already have them}';

    protected $description = 'Explode historical findings JSON into maintenance_tasks (+ stints, + cost re-attribution)';

    /** Legacy finding-severity words → the canonical per-fault grade. */
    private const SEVERITY_MAP = [
        'high' => Maintenance::FAULT_SEVERITY_CRITICAL, 'critical' => Maintenance::FAULT_SEVERITY_CRITICAL,
        'medium' => Maintenance::FAULT_SEVERITY_MODERATE, 'moderate' => Maintenance::FAULT_SEVERITY_MODERATE,
        'low' => Maintenance::FAULT_SEVERITY_ROUTINE, 'routine' => Maintenance::FAULT_SEVERITY_ROUTINE,
    ];

    /** Ticket lifecycle → the status each of its newly-created faults inherits. */
    private const STATUS_MAP = [
        Maintenance::WF_INSPECTION_REQUESTED  => MaintenanceTask::STATUS_PENDING,
        Maintenance::WF_INSPECTION_DIAGNOSTIC => MaintenanceTask::STATUS_PENDING,
        Maintenance::WF_INSPECTION_PENDING    => MaintenanceTask::STATUS_PENDING,
        Maintenance::WF_AWAITING_DISPATCH     => MaintenanceTask::STATUS_PENDING,
        Maintenance::WF_IN_TRANSIT            => MaintenanceTask::STATUS_PENDING,
        Maintenance::WF_UNDER_REPAIR          => MaintenanceTask::STATUS_IN_PROGRESS,
        Maintenance::WF_READY_REINSPECTION    => MaintenanceTask::STATUS_IN_PROGRESS,
        Maintenance::WF_CLOSED                => MaintenanceTask::STATUS_COMPLETED,
        Maintenance::WF_DIAGNOSTIC_CLEARED    => MaintenanceTask::STATUS_CANCELLED,
    ];

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Maintenance::query()->whereNotNull('workflow_status')->orderBy('id');
        if ($id = $this->option('ticket')) {
            $query->whereKey($id);
        }
        $tickets = $query->get();

        if ($tickets->isEmpty()) {
            $this->warn('No workflow tickets matched — nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info(sprintf('%s %d workflow ticket(s)%s…', $dry ? '[DRY RUN] Would process' : 'Processing',
            $tickets->count(), $force ? ' (force rebuild)' : ''));

        $stats = ['tickets' => 0, 'skipped' => 0, 'tasks' => 0, 'stints' => 0, 'lines_linked' => 0];

        DB::beginTransaction();
        try {
            foreach ($tickets as $ticket) {
                $existing = $ticket->tasks()->count();
                if ($existing > 0 && ! $force) {
                    $stats['skipped']++;
                    $this->line(sprintf('  · #%d already has %d task(s) — skipped (use --force to rebuild).', $ticket->id, $existing));
                    continue;
                }
                if ($existing > 0 && $force) {
                    $ticket->tasks()->each(fn (MaintenanceTask $t) => $t->delete()); // cascades stints; lines revert to null
                }

                $result = $this->backfillTicket($ticket);
                $stats['tickets']++;
                $stats['tasks']        += $result['tasks'];
                $stats['stints']       += $result['stints'];
                $stats['lines_linked'] += $result['lines_linked'];

                $this->line(sprintf('  ✓ #%d → %d task(s), %d stint(s), %d cost line(s) linked%s',
                    $ticket->id, $result['tasks'], $result['stints'], $result['lines_linked'],
                    $result['cost_check']));
            }

            if ($dry) {
                DB::rollBack();
                $this->warn('[DRY RUN] rolled back — no changes were written.');
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Backfill aborted, rolled back: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('Done. %d ticket(s) backfilled, %d skipped — %d task(s), %d stint(s), %d cost line(s) linked.',
            $stats['tickets'], $stats['skipped'], $stats['tasks'], $stats['stints'], $stats['lines_linked']));

        return self::SUCCESS;
    }

    /**
     * Build the faults (+ stints + cost links) for one ticket. Returns counts and a cost-reconciliation
     * note so the operator can confirm the grand total survived unchanged.
     *
     * @return array{tasks:int, stints:int, lines_linked:int, cost_check:string}
     */
    private function backfillTicket(Maintenance $ticket): array
    {
        $costBefore = (float) $ticket->cost;
        $faults     = $this->faultsFor($ticket);
        $status     = self::STATUS_MAP[$ticket->workflow_status] ?? MaintenanceTask::STATUS_PENDING;
        $isResolved = in_array($status, MaintenanceTask::TERMINAL, true);

        $identifiedAt = $ticket->inspected_at ?? $ticket->created_at ?? Carbon::now();
        $startedAt    = $ticket->repair_started_at;
        $resolvedAt   = $ticket->wf_closed_at ?? $ticket->returned_at;

        $stints      = 0;
        $linesLinked = 0;

        foreach ($faults as $f) {
            $task = new MaintenanceTask([
                'maintenance_id'    => $ticket->id,
                'vehicle_id'        => $ticket->vehicle_id,
                'symptom'           => $f['symptom'],
                'category_key'      => $f['category_key'],
                'source'            => $f['source'],
                'severity'          => $f['severity'] ?? $ticket->fault_severity,
                'root_cause_id'     => $f['root_cause_id'],
                'root_cause'        => $f['root_cause'],
                'repair_hours'      => $f['repair_hours'],
                'status'            => $status,
                'current_vendor_id' => $ticket->vendor_id,
                'identified_by'     => $ticket->inspected_by,
                'identified_at'     => $this->parseDate($f['at']) ?? $identifiedAt,
                'started_at'        => $isResolved || $status === MaintenanceTask::STATUS_IN_PROGRESS ? $startedAt : null,
                'resolved_at'       => $isResolved ? $resolvedAt : null,
                'resolved_by'       => $isResolved ? $ticket->wf_closed_by : null,
            ]);
            $task->save();

            // Seed the garage stint — historically every fault on a ticket went to its single garage.
            if ($ticket->vendor_id) {
                MaintenanceTaskAssignment::create([
                    'maintenance_task_id' => $task->id,
                    'vendor_id'           => $ticket->vendor_id,
                    'assigned_at'         => $ticket->dispatched_at ?? $ticket->out_date ?? $identifiedAt,
                    'released_at'         => $isResolved ? ($resolvedAt ?? $ticket->actual_in_date) : null,
                    'outcome'             => $isResolved
                        ? ($status === MaintenanceTask::STATUS_CANCELLED
                            ? MaintenanceTaskAssignment::OUTCOME_CANCELLED
                            : MaintenanceTaskAssignment::OUTCOME_RESOLVED)
                        : null,
                    'assigned_by'         => $ticket->dispatched_by,
                    'released_by'         => $isResolved ? $ticket->wf_closed_by : null,
                ]);
                $stints++;
            }

            // Re-attribute itemised cost: a line whose finding_text matches this fault belongs to it.
            $key = $this->norm($f['symptom']);
            $linesLinked += MaintenanceLineItem::where('maintenance_id', $ticket->id)
                ->whereNull('maintenance_task_id')
                ->whereRaw('LOWER(TRIM(finding_text)) = ?', [$key])
                ->get()
                ->each(function (MaintenanceLineItem $line) use ($task) {
                    $line->maintenance_task_id = $task->id;
                    $line->save(); // saved-hook rolls the money up to the fault + ticket
                })->count();
        }

        // Re-derive ticket roll-ups from the fresh tasks (severity/primary garage; total is line-owned).
        $ticket->refresh()->recalcFromTasks(true);

        $costAfter = (float) $ticket->fresh()->cost;
        $check = abs($costAfter - $costBefore) < 0.01
            ? ' [cost ✓ '.number_format($costAfter, 2).']'
            : ' [⚠ COST DRIFT '.number_format($costBefore, 2).' → '.number_format($costAfter, 2).']';

        return ['tasks' => count($faults), 'stints' => $stints, 'lines_linked' => $linesLinked, 'cost_check' => $check];
    }

    /**
     * Normalise a ticket's faults from its sources, in priority order:
     *   1. the findings[] audit array (the canonical per-fault record),
     *   2. else the inspector's test_drive_report.symptoms[],
     *   3. else a single fallback fault so the ticket's cost/history still gets a home.
     *
     * @return list<array{symptom:string, category_key:?string, source:string, severity:?string, root_cause:?string, root_cause_id:?int, repair_hours:mixed, at:?string}>
     */
    private function faultsFor(Maintenance $ticket): array
    {
        $findings = is_array($ticket->findings) ? $ticket->findings : [];
        $out = [];

        foreach ($findings as $f) {
            $text = trim((string) ($f['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'symptom'       => $text,
                'category_key'  => $f['category_key'] ?? null,
                'source'        => in_array(($f['source'] ?? null), Maintenance::FINDING_SOURCES, true)
                                    ? $f['source'] : Maintenance::FINDING_INSPECTOR,
                'severity'      => $this->normSeverity($f['severity'] ?? null),
                'root_cause'    => $f['root_cause'] ?? null,
                'root_cause_id' => $f['root_cause_id'] ?? null,
                'repair_hours'  => $f['repair_hours'] ?? null,
                'at'            => $f['at'] ?? null,
            ];
        }

        if ($out) {
            return $out;
        }

        // No findings — fall back to the inspector's reported symptoms.
        $report   = is_array($ticket->test_drive_report) ? $ticket->test_drive_report : [];
        $symptoms = array_filter(array_map('trim', (array) ($report['symptoms'] ?? [])));
        foreach ($symptoms as $s) {
            $out[] = [
                'symptom' => $s, 'category_key' => null, 'source' => Maintenance::FINDING_INSPECTOR,
                'severity' => $ticket->fault_severity, 'root_cause' => null, 'root_cause_id' => null,
                'repair_hours' => null, 'at' => null,
            ];
        }

        if ($out) {
            return $out;
        }

        // Nothing recorded — one catch-all fault so the ticket's cost & garage history is never orphaned.
        $label = Maintenance::MAINTENANCE_TYPES[$ticket->maintenance_type] ?? $ticket->service_main ?? 'General maintenance';
        return [[
            'symptom' => $label, 'category_key' => null, 'source' => Maintenance::FINDING_INSPECTOR,
            'severity' => $ticket->fault_severity, 'root_cause' => null, 'root_cause_id' => null,
            'repair_hours' => null, 'at' => null,
        ]];
    }

    private function normSeverity(?string $s): ?string
    {
        return $s ? (self::SEVERITY_MAP[strtolower(trim($s))] ?? null) : null;
    }

    private function norm(?string $s): string
    {
        return strtolower(trim((string) $s));
    }

    private function parseDate($v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
