<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAssignment;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The TASK-LEVEL engine — the per-fault tracking layer on top of the ticket lifecycle.
 *
 * SINGLE-GARAGE model: a ticket ([[maintenance-workflow-engine]]) is a CONTAINER of faults, but the car
 * is always at exactly ONE garage at a time. Every still-open fault belongs to the active stint of the
 * ticket's current garage; faults are never scattered across garages simultaneously. The Supervisor sets
 * the primary garage at dispatch and, when work is still needed, performs a whole-car Transfer that
 * closes every open stint at the current garage and opens fresh ones at the next — a sequential hand-off,
 * not concurrent routing. This service owns: promote findings → tasks, route the whole ticket to a garage
 * (assign / transfer), and mark an individual fault's status. The garage "stints"
 * (maintenance_task_assignments) still form the per-fault timeline + per-garage performance feed. Cost
 * roll-ups to the parent ticket happen automatically via the model events; this service never touches money.
 */
class MaintenanceTaskService
{
    /** Lifecycle states in which the car is physically committed to a garage — a new fault found here
     *  joins the active stint immediately, and the ticket carries a single current garage. */
    public const GARAGE_PHASES = [
        Maintenance::WF_AWAITING_DISPATCH,
        Maintenance::WF_IN_TRANSIT,
        Maintenance::WF_UNDER_REPAIR,
        Maintenance::WF_REPAIR_REVIEW,
        Maintenance::WF_READY_REINSPECTION,
    ];

    public function __construct(private VehicleLogService $log)
    {
    }

    /**
     * Promote the ticket's findings JSON into first-class MaintenanceTask rows. Idempotent: a finding
     * whose symptom already has a task is left untouched, so it is safe to call after every findings
     * write (submitReport, addGarageFindings). New faults are created Pending, unassigned — UNLESS the
     * car is already at a garage (a fault discovered mid-repair), in which case the new fault joins that
     * garage's active stint at once so the single-garage invariant holds.
     *
     * @return int how many tasks were newly created
     */
    public function syncFromFindings(Maintenance $ticket, ?User $actor = null): int
    {
        $findings = is_array($ticket->findings) ? $ticket->findings : [];
        if (! $findings) {
            return 0;
        }

        $existing = $ticket->tasks()->get()->keyBy(fn (MaintenanceTask $t) => $this->key($t->symptom));
        $created  = 0;
        $newTasks = [];

        foreach ($findings as $f) {
            $text = trim((string) ($f['text'] ?? ''));
            if ($text === '' || $existing->has($this->key($text))) {
                continue;
            }

            $task = new MaintenanceTask([
                'maintenance_id' => $ticket->id,
                'vehicle_id'     => $ticket->vehicle_id,
                'symptom'        => $text,
                'category_key'   => $f['category_key'] ?? null,
                'source'         => in_array(($f['source'] ?? null), Maintenance::FINDING_SOURCES, true) ? $f['source'] : Maintenance::FINDING_INSPECTOR,
                'severity'       => $this->normSeverity($f['severity'] ?? null) ?? $ticket->fault_severity,
                'root_cause'     => $f['root_cause'] ?? null,
                'root_cause_id'  => $f['root_cause_id'] ?? null,
                'repair_hours'   => $f['repair_hours'] ?? null,
                'status'         => MaintenanceTask::STATUS_PENDING,
                'identified_by'  => $ticket->inspected_by,
                'identified_at'  => $this->parseDate($f['at'] ?? null) ?? Carbon::now(),
            ]);
            $task->save();
            $existing->put($this->key($text), $task);
            $newTasks[] = $task;
            $created++;

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_IDENTIFIED, $actor, [
                'description' => 'Fault identified: ' . $text,
                'source_tag'  => $task->source,
            ]);
        }

        // Single-garage invariant: a fault discovered after the car is already at a garage joins that
        // garage's active stint right away — the ticket is always tracked at exactly one place.
        if ($actor && $ticket->vendor_id && in_array($ticket->workflow_status, self::GARAGE_PHASES, true)) {
            foreach ($newTasks as $task) {
                if (! $task->openAssignment()->exists()) {
                    $this->assign($task, (int) $ticket->vendor_id, $actor);
                }
            }
        }

        return $created;
    }

    /**
     * Open a garage stint for a single fault and put it In Progress — the FIRST assignment (the task has
     * no garage yet). INTERNAL primitive: callers route the WHOLE ticket via routeTicketToGarage() so the
     * single-garage invariant is preserved; moving an already-assigned fault goes through transfer().
     *
     * @param array{started_at?:?\DateTimeInterface} $opts
     */
    public function assign(MaintenanceTask $task, int $vendorId, User $actor, array $opts = [], bool $log = true): MaintenanceTask
    {
        return DB::transaction(function () use ($task, $vendorId, $actor, $opts, $log) {
            if ($task->isTerminal()) {
                throw new WorkflowTransitionException('This fault is already ' . $task->status . ' and cannot be assigned.', ['field' => 'status']);
            }
            if ($task->openAssignment()->exists()) {
                throw new WorkflowTransitionException('This fault is already at a garage — use Transfer to move it.', ['field' => 'vendor_id']);
            }

            MaintenanceTaskAssignment::create([
                'maintenance_task_id' => $task->id,
                'vendor_id'           => $vendorId,
                'assigned_at'         => Carbon::now(),
                'assigned_by'         => $actor->id,
            ]);

            $task->current_vendor_id = $vendorId;
            $task->status            = MaintenanceTask::STATUS_IN_PROGRESS;
            $task->started_at        = $task->started_at ?? ($opts['started_at'] ?? Carbon::now());
            $task->save();

            // The per-fault "assigned to garage" entry is optional: at DISPATCH (garage pick) it's suppressed,
            // because the car hasn't arrived and open faults can still be transferred elsewhere before then —
            // so a per-fault log there would be premature. It's emitted at the In-Workshop check-in instead
            // (see MaintenanceWorkflowService::markUnderRepair). Other callers (a fault found mid-repair) log.
            if ($log) {
                $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_ASSIGNED, $actor, [
                    'description' => 'Fault "' . $task->symptom . '" assigned to ' . $this->vendorName($vendorId),
                    'meta'        => ['vendor_id' => $vendorId],
                ]);
            }

            return $task->fresh(['assignments', 'currentVendor']);
        });
    }

    /**
     * Move a single fault to a DIFFERENT garage: close the current stint as transferred_out (with the
     * reason), open a fresh stint at the new garage, and land the task back at Pending there. If the fault
     * has no open stint yet this behaves as a first assignment. INTERNAL primitive driven by
     * routeTicketToGarage() — the whole car moves together, never one fault at a time.
     */
    public function transfer(MaintenanceTask $task, int $newVendorId, ?string $reason, User $actor, ?int $odometer = null): MaintenanceTask
    {
        return DB::transaction(function () use ($task, $newVendorId, $reason, $actor, $odometer) {
            if ($task->isTerminal()) {
                throw new WorkflowTransitionException('This fault is already ' . $task->status . ' and cannot be transferred.', ['field' => 'status']);
            }

            $open = $task->openAssignment()->first();
            if ($open && (int) $open->vendor_id === $newVendorId) {
                throw new WorkflowTransitionException('The fault is already at that garage.', ['field' => 'vendor_id']);
            }

            $fromVendorId = $open?->vendor_id;
            if ($open) {
                $open->update([
                    'released_at' => Carbon::now(),
                    'outcome'     => MaintenanceTaskAssignment::OUTCOME_TRANSFERRED_OUT,
                    'reason'      => $reason,
                    'released_by' => $actor->id,
                ]);
            }

            MaintenanceTaskAssignment::create([
                'maintenance_task_id' => $task->id,
                'vendor_id'           => $newVendorId,
                'assigned_at'         => Carbon::now(),
                'assigned_by'         => $actor->id,
                'reason'              => $reason,
            ]);

            $task->current_vendor_id = $newVendorId;
            // The car is EN ROUTE to the new garage — it hasn't arrived, so the fault is In Transit, NOT
            // workable. It becomes Pending (workable) only when the car is checked in ("Now at Garage").
            $task->status            = MaintenanceTask::STATUS_TRANSFERRED;
            $task->save();

            // Context for the hand-off: name the sibling faults ALREADY FIXED at the garage we're leaving
            // (completed faults keep their garage in current_vendor_id), so the transfer log reads
            // "moved on — and here's what that garage actually resolved" instead of just the move.
            $fixedHere = ($fromVendorId && $task->maintenance_id)
                ? MaintenanceTask::where('maintenance_id', $task->maintenance_id)
                    ->where('status', MaintenanceTask::STATUS_COMPLETED)
                    ->where('current_vendor_id', $fromVendorId)
                    ->orderBy('id')
                    ->pluck('symptom')
                : collect();

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_TRANSFERRED, $actor, [
                'description' => 'Fault "' . $task->symptom . '" transferred from '
                    . ($fromVendorId ? $this->vendorName($fromVendorId) : 'unassigned')
                    . ' to ' . $this->vendorName($newVendorId)
                    . ($reason ? ' — ' . $reason : '')
                    . ($fixedHere->isNotEmpty()
                        ? ' · Fixed at ' . $this->vendorName($fromVendorId) . ': ' . $fixedHere->implode(', ')
                        : ''),
                'meta'        => ['from_vendor_id' => $fromVendorId, 'to_vendor_id' => $newVendorId, 'reason' => $reason, 'odometer' => $odometer, 'fixed_here' => $fixedHere->all()],
            ]);

            return $task->fresh(['assignments', 'currentVendor']);
        });
    }

    /**
     * SPLIT-DISPATCH — assign a chosen SUBSET of the ticket's open faults to a garage, leaving the rest as
     * Pending Assignment (no garage stint). This is the delegate's fault-to-garage distribution control:
     * at dispatch (or later, from the Dispatch Queue) they pick which faults go to THIS garage. Passing
     * $faultIds = null routes EVERY open fault (the classic whole-ticket dispatch). Faults already at a
     * garage are skipped (idempotent). Unselected faults simply keep their Pending status — they surface in
     * the Dispatch Queue (see MaintenanceTask::scopePendingAssignment) to be assigned when the delegate
     * chooses, at whichever garage the car is at then.
     *
     * @param int[]|null $faultIds  the faults to route now; null = all open faults
     * @return int how many faults were freshly assigned
     */
    public function dispatchFaults(Maintenance $ticket, int $vendorId, ?array $faultIds, User $actor, bool $log = true): int
    {
        $query = $ticket->tasks()->whereNotIn('status', MaintenanceTask::TERMINAL);
        if ($faultIds !== null) {
            $query->whereIn('id', $faultIds);
        }

        $assigned = 0;
        foreach ($query->get() as $task) {
            if ($task->openAssignment()->exists()) {
                continue; // already at a garage — not a fresh assignment
            }
            $this->assign($task, $vendorId, $actor, [], $log);
            $assigned++;
        }

        return $assigned;
    }

    /**
     * Whole-car TRANSFER — move every fault CURRENTLY AT the garage to the next one (close each open stint
     * transferred_out with the reason, open a fresh one at the new garage; they ride In Transit until the
     * arrival check-in). The single-garage invariant in one call: the car and its in-progress work move
     * together. Faults already at the target garage, and terminal (resolved/cancelled) faults, are left
     * alone. IMPORTANT (split-dispatch): a Pending-Assignment fault (never routed — no open stint) is NOT
     * swept along; it stays Pending so the delegate assigns it deliberately at the car's next stop. The
     * ticket's vendor_id (its single current garage) is owned by the caller (transferGarage).
     *
     * @return int how many faults were actually moved
     */
    public function routeTicketToGarage(Maintenance $ticket, int $vendorId, ?string $reason, User $actor, ?int $odometer = null): int
    {
        $moved = 0;

        foreach ($ticket->tasks()->whereNotIn('status', MaintenanceTask::TERMINAL)->get() as $task) {
            $open = $task->openAssignment()->first();
            if (! $open) {
                continue; // Pending-Assignment fault — not at this garage; leave it Pending for the delegate.
            }
            if ((int) $open->vendor_id === $vendorId) {
                continue; // already at this garage — nothing to do
            }
            // A garage→garage move is always a physical transit → transfer() lands it In Transit.
            $this->transfer($task, $vendorId, $reason, $actor, $odometer);
            $moved++;
        }

        return $moved;
    }

    /**
     * Move a fault's status directly (pending ↔ in_progress, or to completed / cancelled). Resolving a
     * fault (completed/cancelled) also closes its open garage stint with the matching outcome and stamps
     * resolved_at — which lets the parent ticket's tasksProgress() know when every fault is done.
     */
    public function setStatus(MaintenanceTask $task, string $status, User $actor, ?string $note = null, ?int $serviceOdometer = null): MaintenanceTask
    {
        if (! in_array($status, MaintenanceTask::STATUSES, true)) {
            throw new WorkflowTransitionException('Unknown task status: ' . $status, ['field' => 'status']);
        }

        $note = $note !== null ? (trim($note) ?: null) : null;

        // A fault can only be WORKED (in_progress) or FIXED (completed) once the car has reached the
        // garage stage (dispatched onward). Marking it fixed while the ticket is still awaiting dispatch
        // is nonsensical — the car hasn't even left yet. Reopening (→ pending) and dropping a non-issue
        // (→ cancelled) stay allowed at any stage. The frontend hides the button too; this re-guards it.
        $needsGarage = in_array($status, [MaintenanceTask::STATUS_IN_PROGRESS, MaintenanceTask::STATUS_COMPLETED], true);
        if ($needsGarage && ! optional($task->maintenance)->hasReachedGarage()) {
            throw new WorkflowTransitionException(
                'The car has not been dispatched to the garage yet — a fault can be marked fixed only from the garage stage.',
                ['field' => 'status', 'from' => $task->maintenance?->workflow_status, 'to' => $status],
            );
        }

        return DB::transaction(function () use ($task, $status, $actor, $note, $serviceOdometer) {
            $resolving = in_array($status, MaintenanceTask::TERMINAL, true);

            // The "what was done to fix it" note, kept distinct from the fault's inspection `notes`.
            if ($note !== null) {
                $task->resolution_note = $note;
            }

            if ($resolving) {
                $open = $task->openAssignment()->first();
                $open?->update([
                    'released_at' => Carbon::now(),
                    'outcome'     => $status === MaintenanceTask::STATUS_CANCELLED
                        ? MaintenanceTaskAssignment::OUTCOME_CANCELLED
                        : MaintenanceTaskAssignment::OUTCOME_RESOLVED,
                    'released_by' => $actor->id,
                ]);
                $task->resolved_at = Carbon::now();
                $task->resolved_by = $actor->id;
            } elseif ($status === MaintenanceTask::STATUS_IN_PROGRESS) {
                $task->started_at = $task->started_at ?? Carbon::now();
                $task->resolved_at = null;
                $task->resolved_by = null;
            } else { // back to pending — clear the resolution stamp
                $task->resolved_at = null;
                $task->resolved_by = null;
            }

            // Reopening a fault (→ pending/in_progress) also clears any "marked incorrect" dispute: the
            // work is back on, so the delegate's mis-diagnosis ruling no longer stands.
            if (! $resolving && $task->marked_incorrect_at !== null) {
                $task->marked_incorrect_by = null;
                $task->marked_incorrect_at = null;
                $task->incorrect_reason    = null;
            }

            $task->status = $status;
            $task->save();

            if ($resolving) {
                $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_RESOLVED, $actor, [
                    'description' => 'Fault "' . $task->symptom . '" ' . ($status === MaintenanceTask::STATUS_CANCELLED ? 'cancelled' : 'resolved') . ' by ' . $actor->name
                        . ($note ? ' — ' . $note : ''),
                    'meta'        => ['status' => $status, 'note' => $note],
                ]);
            }

            // Closed-loop for a SCHEDULED routine service: when an oil-change / battery fault is FIXED
            // (not cancelled), roll its recurring Service Reminder forward from the odometer at change,
            // so the next reminder fires on schedule. A no-op for ordinary faults.
            if ($status === MaintenanceTask::STATUS_COMPLETED) {
                $this->advanceRoutineService($task, $actor, $serviceOdometer);
            }

            return $task->fresh(['assignments', 'currentVendor']);
        });
    }

    /**
     * Roll a completed routine-service fault's recurring Service Reminder forward. If the fault's symptom
     * maps to a routine service_type (oil_change / battery / …), re-anchor that reminder to the odometer at
     * the moment of the change: the explicit reading if the client sent one, otherwise the ticket's most
     * recent odometer capture, otherwise the car's current odometer. Without any reading we can't anchor,
     * so we skip silently (the fault is still resolved). Best-effort + logged for traceability; never throws
     * so it can't sink the resolve it rides on.
     */
    private function advanceRoutineService(MaintenanceTask $task, User $actor, ?int $odometer): void
    {
        $type = Maintenance::routineServiceTypeFor($task->symptom);
        if (! $type) {
            return; // ordinary fault — nothing to schedule
        }

        try {
            $ticket  = $task->maintenance;
            $vehicle = $task->vehicle ?: $ticket?->vehicle;
            if (! $vehicle) {
                return;
            }

            $odo = $odometer
                ?? $ticket?->return_odometer
                ?? $ticket?->receive_odometer
                ?? $vehicle->odometer;
            if ($odo === null) {
                return; // no reading to anchor to — leave the reminder as it is
            }

            $reminder = $vehicle->recordServiceDone($type, (int) $odo, Carbon::now()->toDateString());

            $this->log->recordTask($task, VehicleLogEvent::EVENT_SERVICE_LOGGED, $actor, [
                'description' => $reminder->displayName() . ' logged @ ' . number_format((int) $odo) . ' km'
                    . ($reminder->next_due_odometer ? ' — next due at ' . number_format((int) $reminder->next_due_odometer) . ' km' : '')
                    . ' (by ' . $actor->name . ')',
                'meta'        => [
                    'service_type'      => $type,
                    'odometer'          => (int) $odo,
                    'next_due_odometer' => $reminder->next_due_odometer,
                    'next_due_at'       => optional($reminder->next_due_at)->toDateString(),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e); // scheduling the next service must never break marking a fault fixed
        }
    }

    /**
     * Quality-Control verdict — this fault FLUNKED the closing re-inspection: the garage returned the
     * car claiming it fixed, but the inspector found the problem is still there. We:
     *   - close the fault's open garage stint with outcome `failed_reinspection` + the inspector's note,
     *     so the per-garage feed forever shows THIS garage handed the car back unfixed;
     *   - stamp the blame on the fault itself — bump `reinspection_failures` (the blacklist signal: a
     *     fault that keeps failing at the same garage) and record `last_failed_vendor_id` / `last_failed_at`;
     *   - drop the fault back to PENDING and detach it from the garage (current_vendor_id = null), so it
     *     re-enters the Supervisor's dispatch decision — they choose the same garage again or a new one.
     * Unlike a normal transfer (which the Supervisor drives), this is the inspector's call at sign-off;
     * the actual re-dispatch happens next when the Supervisor assigns a garage on the returned ticket.
     */
    public function failReinspection(MaintenanceTask $task, ?string $note, User $actor): MaintenanceTask
    {
        $note = $note !== null ? trim($note) : null;
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($task, $note, $actor) {
            $open           = $task->openAssignment()->first();
            $failedVendorId = $open?->vendor_id ?? $task->current_vendor_id;

            $open?->update([
                'released_at' => Carbon::now(),
                'outcome'     => MaintenanceTaskAssignment::OUTCOME_FAILED_REINSPECTION,
                'reason'      => $note,
                'released_by' => $actor->id,
            ]);

            $task->reinspection_failures = (int) $task->reinspection_failures + 1;
            if ($failedVendorId) {
                $task->last_failed_vendor_id = $failedVendorId;
            }
            $task->last_failed_at    = Carbon::now();
            $task->status            = MaintenanceTask::STATUS_PENDING; // back in the queue for re-dispatch
            $task->current_vendor_id = null;                            // no longer at a garage
            $task->resolved_at       = null;
            $task->resolved_by       = null;
            $task->save();

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_REINSPECTION_FAILED, $actor, [
                'description' => 'Re-inspection FAILED for "' . $task->symptom . '" — still not fixed'
                    . ($failedVendorId ? ' at ' . $this->vendorName($failedVendorId) : '')
                    . ' (×' . $task->reinspection_failures . ')'
                    . ($note ? ' — ' . $note : '') . ' (by ' . $actor->name . ')',
                'meta'        => [
                    'failed_vendor_id' => $failedVendorId,
                    'failures'         => $task->reinspection_failures,
                    'note'             => $note,
                ],
            ]);

            return $task->fresh(['assignments', 'currentVendor', 'lastFailedVendor']);
        });
    }

    /**
     * Delegate DISPUTE — a supervisor (Waleed / Abdullah) overrules the inspector (Abo Marouf), ruling
     * that a fault he flagged at inspection was NOT a real fault. This is only offered while the car is
     * actually In Workshop (under_repair), where the garage has eyes on the car, and only on faults the
     * inspector raised (source = inspector) — a garage-discovered fault isn't the inspector's call.
     *
     * FAULT-LEVEL ONLY — this cancels exactly ONE task and closes only THAT task's own garage stint
     * (maintenance_task_assignments is scoped per task). Every OTHER fault at the same garage keeps its
     * own open stint and stays Under Repair; the parent ticket's workflow_status and current garage are
     * untouched (recalcFromTasks deliberately never derives either — see Maintenance::recalcFromTasks),
     * and NO notification of a total cancellation is fired — only this one item is flagged in the audit log.
     *
     * We drop the fault out of the "all-resolved / must-fix" gate exactly like a cancel (status →
     * cancelled, close its own stint), but ALSO stamp who overruled the inspector + why, so the override
     * is auditable and can be counted against the inspector's diagnostic accuracy later. It's a distinct
     * outcome from a plain cancel — surfaced as a red "Rejected" badge (struck through), not "Cancelled".
     */
    public function markIncorrect(MaintenanceTask $task, string $reason, User $actor): MaintenanceTask
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new WorkflowTransitionException('Give a reason why this fault is not a real fault.', ['field' => 'reason']);
        }

        if (optional($task->maintenance)->workflow_status !== Maintenance::WF_UNDER_REPAIR) {
            throw new WorkflowTransitionException(
                'A fault can only be marked incorrect while the car is In Workshop.',
                ['field' => 'status', 'from' => $task->maintenance?->workflow_status],
            );
        }

        if ($task->source !== Maintenance::FINDING_INSPECTOR) {
            throw new WorkflowTransitionException(
                'Only a fault raised by the inspector can be marked incorrect.',
                ['field' => 'source', 'source' => $task->source],
            );
        }

        if ($task->isTerminal()) {
            throw new WorkflowTransitionException('This fault is already closed.', ['field' => 'status']);
        }

        return DB::transaction(function () use ($task, $reason, $actor) {
            // Close ONLY THIS fault's own garage stint (per-task assignment) — the garage stops working
            // this one item; its sibling faults' stints are separate rows and stay open Under Repair.
            $task->openAssignment()->first()?->update([
                'released_at' => Carbon::now(),
                'outcome'     => MaintenanceTaskAssignment::OUTCOME_CANCELLED,
                'reason'      => $reason,
                'released_by' => $actor->id,
            ]);

            $task->status              = MaintenanceTask::STATUS_CANCELLED; // out of the all-resolved gate
            $task->current_vendor_id   = null;                             // no longer at a garage
            $task->resolved_at         = Carbon::now();
            $task->resolved_by         = $actor->id;
            $task->marked_incorrect_by = $actor->id;
            $task->marked_incorrect_at = Carbon::now();
            $task->incorrect_reason    = $reason;
            $task->save();

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_MARKED_INCORRECT, $actor, [
                'description' => 'Fault "' . $task->symptom . '" marked INCORRECT (not a real fault) by '
                    . $actor->name . ' — ' . $reason,
                'meta'        => ['reason' => $reason, 'inspector_id' => $task->identified_by],
            ]);

            return $task->fresh(['assignments', 'currentVendor', 'markedIncorrectBy']);
        });
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────

    private function vendorName(int $vendorId): string
    {
        return \App\Models\Vendor::whereKey($vendorId)->value('name') ?: ('Garage #' . $vendorId);
    }

    private function key(?string $s): string
    {
        return strtolower(trim((string) $s));
    }

    private function normSeverity(?string $s): ?string
    {
        $map = ['high' => 'critical', 'critical' => 'critical', 'medium' => 'moderate', 'moderate' => 'moderate', 'low' => 'routine', 'routine' => 'routine'];
        return $s ? ($map[strtolower(trim($s))] ?? null) : null;
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
