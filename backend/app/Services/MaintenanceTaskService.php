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

    public function __construct(
        private VehicleLogService $log,
        private RecurringFaultService $recurring,
        private FaultWorkSessionService $workSessions,
    ) {
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
            if ($text === '') {
                continue;
            }

            // HELD OR REFUSED — this finding is not work yet, and may never be. A finding the car's own
            // data disagrees with (an oil change on a car with most of its interval left, or the same
            // fault raised twice) waits on an approver; one that was rejected never becomes work at all.
            // Skipped rather than filtered upstream so EVERY promotion path is covered by one line, and
            // approving it later simply calls this method again. See [[FindingApprovalService]].
            if (FindingApprovalService::blocksPromotion($f)) {
                continue;
            }

            // WHERE + HOW MANY, carried on the finding since the intake screen. Applied to an ALREADY
            // promoted fault too (the branch below), because re-filing a report with the location
            // corrected must fix the fault, not silently keep the first answer — the finding is the
            // inspector's live statement and the task is its promotion, not a frozen copy.
            if ($existing->has($this->key($text))) {
                $this->applyLocationAndQuantity($existing->get($this->key($text)), $f);

                continue;
            }

            // TYPE FROM THE CATALOG, not from a guess. When the finding names a catalog row (or carries an
            // explicit kind from a type-first intake) the event is born authoritatively classified and
            // `classification_source` says `catalog`. Only findings the catalogs do not recognise fall
            // through to the shield in MaintenanceTask::creating. See audit H5.
            $classification = app(EventClassificationService::class)->classifyFromFinding($f + ['text' => $text]);

            $task = new MaintenanceTask(array_merge([
                'maintenance_id' => $ticket->id,
                'vehicle_id'     => $ticket->vehicle_id,
                'symptom'        => $text,
                // HOW MANY physical occurrences this one routable fault covers. Absent on every
                // pre-existing finding, and 1 is what a finding without a count has always meant.
                'quantity'       => app(FaultLocationService::class)->normalizeQuantity($f['quantity'] ?? 1),
                'category_key'   => $f['category_key'] ?? null,
                'source'         => in_array(($f['source'] ?? null), Maintenance::FINDING_SOURCES, true) ? $f['source'] : Maintenance::FINDING_INSPECTOR,
                'severity'       => $this->normSeverity($f['severity'] ?? null) ?? $ticket->fault_severity,
                'root_cause'     => $f['root_cause'] ?? null,
                'root_cause_id'  => $f['root_cause_id'] ?? null,
                'repair_hours'   => $f['repair_hours'] ?? null,
                'status'         => MaintenanceTask::STATUS_PENDING,
                'identified_by'  => $ticket->inspected_by,
                'identified_at'  => $this->parseDate($f['at'] ?? null) ?? Carbon::now(),
            ], $classification ?? []));
            $task->save();
            $this->applyLocationAndQuantity($task, $f);
            $existing->put($this->key($text), $task);
            $newTasks[] = $task;
            $created++;

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_IDENTIFIED, $actor, [
                // Name the event by its own type. The trail used to read "Fault identified: Oil Change"
                // for a service, which is the audit log asserting the very thing the type layer exists
                // to deny (audit H5).
                //
                // The identification is written with its count and its places — "Fault identified:
                // 2 scratches — rims and body" — through the same formatter every screen uses, so the
                // audit trail and the board say the same sentence about the same fault. A fault with
                // neither renders exactly as it always did.
                'description' => $task->kindMeta()['label'] . ' identified: ' . $task->describe(),
                'source_tag'  => $task->source,
            ]);

            // REPORT-TIME background check (non-blocking): if this car already had the SAME fault FIXED,
            // silently raise a "possible recurring fault" flag. It is only acted on later, once the
            // workshop CONFIRMS the fault — a fresh report is just a claim until then.
            $this->recurring->flagPossibleRecurrence($task);
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

        // The inspector's required parts were recorded against FINDINGS (the fault rows did not exist yet).
        // Now that they do, bind each line to its fault. Hooked here rather than at the call sites so every
        // path that promotes findings closes the traceability loop automatically.
        $this->bindRequiredParts($ticket);

        // Same reasoning, same place: a system check the inspector answered "replace → approved" has
        // been waiting for the fault its keyword produces. Now that the fault exists, the obligation
        // is bound to it and will resolve when that repair does — closing the chain
        // recommendation → check → result → decision → action → completion without a second creation
        // path for faults. See [[VehicleCheckService]].
        try {
            app(VehicleCheckService::class)->bindActions($ticket, $actor);
        } catch (\Throwable $e) {
            report($e);   // check bookkeeping must never sink the promotion of a real fault
        }

        return $created;
    }

    /**
     * Carry a finding's WHERE and HOW MANY onto its promoted fault.
     *
     * Both are OPTIONAL and absence is meaningful, so each is applied only when the finding actually
     * carries it: a payload with no `locations` key means "this writer has nothing to say about
     * places", not "clear the places". An explicit empty array DOES clear them — that is a person
     * removing a location they had picked, and it must stick.
     *
     * Best-effort by design. A location vocabulary hiccup must never sink the promotion of a real
     * fault an inspector just reported; the fault is the evidence, the place is an attribute of it.
     *
     * @param array $finding one entry of the ticket's findings JSON
     */
    private function applyLocationAndQuantity(MaintenanceTask $task, array $finding): void
    {
        try {
            $locations = app(FaultLocationService::class);

            if (array_key_exists('quantity', $finding)) {
                $quantity = $locations->normalizeQuantity($finding['quantity']);
                if ((int) $task->quantity !== $quantity) {
                    $task->quantity = $quantity;
                    $task->save();
                }
            }

            if (array_key_exists('locations', $finding)) {
                $locations->sync($task, (array) $finding['locations']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Resolve the ticket's still-unbound {@see MaintenanceRequiredPart} lines to the faults they belong to,
     * matching on the same normalised symptom key this service promotes findings by. Idempotent, and
     * best-effort: a failure here must never break fault promotion.
     *
     * @return int how many lines were newly bound
     */
    public function bindRequiredParts(Maintenance $ticket): int
    {
        try {
            $unbound = $ticket->requiredParts()
                ->whereNull('maintenance_task_id')->whereNotNull('finding_key')->get();
            if ($unbound->isEmpty()) {
                return 0;
            }

            $tasks = $ticket->tasks()->get()
                ->keyBy(fn (MaintenanceTask $t) => \App\Models\MaintenanceRequiredPart::findingKey($t->symptom));

            $bound = 0;
            foreach ($unbound as $line) {
                if ($task = $tasks->get($line->finding_key)) {
                    $line->maintenance_task_id = $task->id;
                    $line->save();
                    $bound++;
                }
            }

            return $bound;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
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
                // The car is leaving this garage — nobody is working the fault during the move, so the
                // running interval closes here. Garage B's work opens a fresh session against its own
                // stint, which is what keeps per-garage active hours separable after a transfer.
                $this->workSessions->closeOpen($task, $actor, 'transferred');

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
    /**
     * WORKSHOP CONFIRMATION GATE — the technician records a verdict on a reported fault while the car is
     * "In Workshop". A reported fault is only a claim until it is physically checked here; the verdict is
     * one of MaintenanceTask::CONFIRMATION_STATUSES. Only `confirmed` triggers the recurring-fault
     * intelligence (via RecurringFaultService) — which is exactly what stops false duplicate alerts on
     * unconfirmed reports. This is independent of the repair status (a fault can be confirmed, then fixed).
     */
    public function confirmFault(MaintenanceTask $task, string $status, User $actor, ?string $note = null): MaintenanceTask
    {
        if (! in_array($status, MaintenanceTask::CONFIRMATION_STATUSES, true)) {
            throw new WorkflowTransitionException('Unknown confirmation verdict: ' . $status, ['field' => 'confirmation_status']);
        }

        // Confirmation is a workshop act — only meaningful once the car has physically reached the garage
        // (or is being serviced on-site). Reviewing a fault before the car is even there is nonsensical.
        if (! optional($task->maintenance)->hasReachedGarage() && ! optional($task->maintenance)->isOnSite()) {
            throw new WorkflowTransitionException(
                'A fault can be reviewed only once the car is at the workshop.',
                ['field' => 'confirmation_status', 'from' => $task->maintenance?->workflow_status, 'to' => $status],
            );
        }

        $task->forceFill([
            'confirmation_status' => $status,
            'confirmation_note'   => $note !== null ? (trim($note) ?: null) : null,
            'confirmed_by'        => $actor->id,
            'confirmed_at'        => Carbon::now(),
        ])->save();

        // THE PER-FAULT WORK CLOCK STARTS HERE. The technician has physically looked at THIS fault and
        // ruled on it, so this is the first moment attributable to this fault alone — unlike the stint's
        // assigned_at, which is the shared dispatch instant every fault on the ticket carries. Fill-if-
        // null so re-confirming never restarts the clock. See FaultRepairTimeService.
        $this->markWorkStarted($task);

        // Only a CONFIRMED fault is allowed to open a recurring-fault review case. (Confirmed is now the
        // only accepted verdict — the "not a real fault" outcome is the separate Incorrect path.)
        if ($status === MaintenanceTask::CONFIRM_CONFIRMED) {
            $review = $this->recurring->onFaultConfirmed($task, $actor);

            // Recurrence detected → BLOCK the repair until a manager approves it (the money guard against
            // paying twice for the same recently-fixed fault). Leaves any already-resolved gate untouched.
            if ($review && empty($task->repair_gate)) {
                $task->forceFill(['repair_gate' => MaintenanceTask::GATE_PENDING])->save();
            }
        }

        return $task->fresh(['confirmedBy', 'currentVendor', 'repairGateBy', 'recurrencePreviousTask.currentVendor']);
    }

    /**
     * Approve or REJECT a recurring fault's repair gate (the review before a second repair). Only meaningful
     * while the gate is pending. Approve → repair may proceed. Reject → the fault is cancelled (it will not
     * be repaired again on this ticket) so we never pay twice for a fault a garage just fixed.
     */
    public function resolveRepairGate(MaintenanceTask $task, User $actor, bool $approve, ?string $note = null): MaintenanceTask
    {
        if ($task->repair_gate !== MaintenanceTask::GATE_PENDING) {
            throw new WorkflowTransitionException('No repair approval is pending for this fault.', ['field' => 'repair_gate']);
        }
        $note = $note !== null ? (trim($note) ?: null) : null;

        $task->forceFill([
            'repair_gate'      => $approve ? MaintenanceTask::GATE_APPROVED : MaintenanceTask::GATE_REJECTED,
            'repair_gate_by'   => $actor->id,
            'repair_gate_at'   => Carbon::now(),
            'repair_gate_note' => $note,
        ])->save();

        // Reject means "do not re-repair" — cancel the fault (evidence not required for a cancel).
        if (! $approve) {
            $this->setStatus($task, MaintenanceTask::STATUS_CANCELLED, $actor, $note ?? 'Repair rejected — recurring fault not re-repaired.');
        }

        return $task->fresh(['repairGateBy', 'currentVendor', 'recurrencePreviousTask.currentVendor']);
    }

    public function setStatus(MaintenanceTask $task, string $status, User $actor, ?string $note = null, ?int $serviceOdometer = null, ?float $laborHours = null): MaintenanceTask
    {
        if (! in_array($status, MaintenanceTask::STATUSES, true)) {
            throw new WorkflowTransitionException('Unknown task status: ' . $status, ['field' => 'status']);
        }

        $note = $note !== null ? (trim($note) ?: null) : null;

        // A fault can only be WORKED (in_progress) or FIXED (completed) once the car has reached the
        // garage stage (dispatched onward). Marking it fixed while the ticket is still awaiting dispatch
        // is nonsensical — the car hasn't even left yet. Reopening (→ pending) and dropping a non-issue
        // (→ cancelled) stay allowed at any stage. The frontend hides the button too; this re-guards it.
        // EXCEPTION: an on-site (mobile) job is fixed where the car is parked and never reaches a garage,
        // so "Mark as Serviced" legitimately resolves its faults without a garage stint.
        $needsGarage = in_array($status, [MaintenanceTask::STATUS_IN_PROGRESS, MaintenanceTask::STATUS_COMPLETED], true);
        if ($needsGarage && ! optional($task->maintenance)->hasReachedGarage() && ! optional($task->maintenance)->isOnSite()) {
            throw new WorkflowTransitionException(
                'The car has not been dispatched to the garage yet — a fault can be marked fixed only from the garage stage.',
                ['field' => 'status', 'from' => $task->maintenance?->workflow_status, 'to' => $status],
            );
        }

        // Recurring-fault REPAIR GATE: a confirmed fault that recurred within the window is frozen until a
        // manager approves the repair. No work may start/finish on it until then — the guard against paying
        // twice for the same recently-fixed fault. Reopen (→ pending) and cancel stay allowed.
        if ($needsGarage && $task->repair_gate === MaintenanceTask::GATE_PENDING) {
            throw new WorkflowTransitionException(
                'This fault recurred recently and is awaiting repair approval — it cannot be repaired until approved.',
                ['field' => 'status', 'gate' => MaintenanceTask::GATE_PENDING],
            );
        }

        // Terminal ticket guard: once a ticket is CLOSED or signed-off-awaiting-invoice, its faults
        // must not be RE-OPENED (→ pending / in_progress). A terminal correction (resolve / cancel a
        // lingering fault) stays allowed, but re-opening would strand a "closed" ticket carrying live
        // open faults while the car is already back in service, and silently rewrite the closed
        // ticket's historical fault_severity (recalcFromTasks) with no audit trail.
        $parentTerminal = in_array(
            optional($task->maintenance)->workflow_status,
            [Maintenance::WF_CLOSED, Maintenance::WF_AWAITING_INVOICE],
            true,
        );
        $reopening = in_array($status, [MaintenanceTask::STATUS_PENDING, MaintenanceTask::STATUS_IN_PROGRESS], true);
        if ($parentTerminal && $reopening) {
            throw new WorkflowTransitionException(
                'This maintenance ticket is already closed — a fault on it can no longer be re-opened.',
                ['field' => 'status', 'from' => $task->maintenance?->workflow_status, 'to' => $status],
            );
        }

        return DB::transaction(function () use ($task, $status, $actor, $note, $serviceOdometer, $laborHours) {
            $resolving = in_array($status, MaintenanceTask::TERMINAL, true);

            // The "what was done to fix it" note, kept distinct from the fault's inspection `notes`.
            if ($note !== null) {
                $task->resolution_note = $note;
            }

            if ($resolving) {
                // The fault is leaving the bench: close whatever work/blocked interval is still running
                // BEFORE the stint closes, so the ledger ends with the repair instead of accruing
                // forever. Doing it first also means the labor ceiling below sees the final total.
                $this->workSessions->closeOpen($task, $actor, $status);

                $open = $task->openAssignment()->first();
                $open?->update([
                    'released_at' => Carbon::now(),
                    'outcome'     => in_array($status, MaintenanceTask::NON_REPAIR_TERMINAL, true)
                        ? MaintenanceTaskAssignment::OUTCOME_CANCELLED
                        : MaintenanceTaskAssignment::OUTCOME_RESOLVED,
                    'released_by' => $actor->id,
                ]);

                // The CURRENT attempt's manual labor time. It goes through FaultRepairTimeService — the
                // ONE writer — so it is validated against this attempt's recorded active work and can
                // never be booked above it. Writing it inline here (as this method used to) was how a
                // 3-hour entry landed on an 11-second work window: there was nothing to check it.
                if ($laborHours !== null) {
                    // A FRESH copy, not $task->refresh() — $task already carries unsaved changes
                    // (resolution_note, and the status/resolved stamps set below) and refreshing here
                    // would silently discard them.
                    app(\App\Services\FaultRepairTimeService::class)
                        ->recordAttemptLabor($task->fresh(), (float) $laborHours, $actor);
                    // recordAttemptLabor owns the repair_hours cache; re-read what it wrote rather than
                    // recomputing a second, divergent version of the same number here.
                    $task->repair_hours = MaintenanceTask::whereKey($task->id)->value('repair_hours');
                }
                $task->resolved_at = Carbon::now();
                $task->resolved_by = $actor->id;
            } elseif ($status === MaintenanceTask::STATUS_IN_PROGRESS) {
                $task->started_at = $task->started_at ?? Carbon::now();
                $task->resolved_at = null;
                $task->resolved_by = null;
                // Second per-fault work signal (the first is the confirmation verdict): someone
                // deliberately put THIS fault into work. Fill-if-null — an earlier confirmation wins.
                $this->markWorkStarted($task);
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
                $verb = match ($status) {
                    MaintenanceTask::STATUS_CANCELLED => 'cancelled',
                    MaintenanceTask::STATUS_NOT_FOUND => 'closed — not found',
                    default                           => 'resolved',
                };
                $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_RESOLVED, $actor, [
                    'description' => 'Fault "' . $task->symptom . '" ' . $verb . ' by ' . $actor->name
                        . ($note ? ' — ' . $note : ''),
                    'meta'        => ['status' => $status, 'note' => $note],
                ]);

                // A fault born of a system check discharges that check when it ends — and records HOW
                // it ended, because a cancelled or mis-diagnosed fault repaired nothing and must not
                // count as a recommendation that led to a fix. Best-effort inside the service, so a
                // check bookkeeping failure can never block a repair sign-off.
                app(VehicleCheckService::class)->completeActionFor($task, $actor);
            }

            // Routine service (oil / battery) confirmation is DEFERRED to ticket close. Performing the
            // fault here only marks it done — it is Pending Confirmation, and the vehicle master record
            // (service history, last-service anchor, battery date, odometer, Service Status) is NOT
            // touched. That sync happens exactly once, when the ticket is officially closed, in
            // MaintenanceWorkflowService::confirmRoutineServices — the ticket is the single source of
            // truth, so no maintenance ever reaches the vehicle outside a completed ticket workflow.

            return $task->fresh(['assignments', 'currentVendor']);
        });
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

            // Attempt #1 is over. Close its running interval so attempt #1's active work total is
            // final and immutable — attempt #2 will clock against its own new stint.
            $this->workSessions->closeOpen($task, $actor, 'failed_reinspection');

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
     * INCORRECT — the single "this is not a real fault" outcome (merges the former "Not found" verdict and
     * the delegate "mark incorrect" override into one path). A supervisor (Waleed / Abdullah) rules, while
     * the car is In Workshop (under_repair) with the garage's eyes on it, that a reported fault does not
     * exist. Applies to a fault from ANY source; when the fault was inspector-raised it doubles as an
     * override of the inspector's diagnosis (surfaced on the mis-diagnosis oversight report, which filters
     * to inspector-source faults). Reason is mandatory and the who/why is always stamped for the audit.
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

        if ($task->isTerminal()) {
            throw new WorkflowTransitionException('This fault is already closed.', ['field' => 'status']);
        }

        return DB::transaction(function () use ($task, $reason, $actor) {
            // The fault was never real, so nothing more is being worked on it — close the running
            // interval. Whatever time WAS clocked stays on record: someone did spend it establishing
            // that the reported fault does not exist, and that is a real diagnostic cost.
            $this->workSessions->closeOpen($task, $actor, 'marked_incorrect');

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

    /**
     * Start this fault's work clock on its CURRENT attempt (the open stint), if it hasn't started yet.
     *
     * Atomic fill-if-null: the first genuine per-fault work signal of the attempt wins and is never
     * moved, so re-confirming a fault or toggling it through in_progress cannot restart or extend its
     * measured time. A fault with no open stint (on-site job, not yet dispatched) is a silent no-op —
     * the read layer then reports that fault on its clearly-labelled fallback basis instead.
     */
    private function markWorkStarted(MaintenanceTask $task): void
    {
        $open = $task->openAssignment()->first();
        if (! $open) {
            return;
        }

        MaintenanceTaskAssignment::whereKey($open->id)
            ->whereNull('work_started_at')
            ->update(['work_started_at' => Carbon::now()]);
    }

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
