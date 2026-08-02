<?php

namespace App\Services;

use App\Evidence\EventRecorder;
use App\Evidence\Provenance;
use App\Models\ActionCatalog;
use App\Models\DomainEvent;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAction;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tier 1 capture — the four answers that turn a closed ticket into a learnable repair.
 *
 *      what was wrong      root cause          (already captured at inspection)
 *      what was done       repair actions      ← new
 *      did it work         claimed outcome     ← new
 *      how do we know      verification        ← new
 *
 * WHY ONLY FOUR. The full canonical model runs to roughly forty fields, and a forty-field form is
 * how you get 22-out-of-26,838 all over again. Every field here earns its place against the agreed
 * test: each one either changes a workflow decision or is required for future intelligence, and each
 * is answerable in seconds by someone standing next to the car. Everything else is optional and
 * stays out of the way.
 *
 * NO FAULT FOUND SHORT-CIRCUITS. If the workshop looked and the reported fault was not there, then
 * demanding repair actions is asking someone to invent them — and invented actions are worse than
 * none, because they enter the dataset indistinguishable from real work.
 *
 * THE CLAIM IS RECORDED AS A CLAIM. `claimed_outcome` is the technician's own judgement, attributed
 * to them, never treated as fact. Whether the repair actually held is derived from recurrence months
 * later. Keeping the two apart is the only way to ever measure who over-claims.
 */
class RepairCaptureService
{
    public const OUTCOME_COMPLETE       = 'complete';
    public const OUTCOME_PARTIAL        = 'partial';
    public const OUTCOME_TEMPORARY      = 'temporary';
    public const OUTCOME_NO_IMPROVEMENT = 'no_improvement';

    /**
     * An honest "we don't know yet".
     *
     * Offered deliberately, even though it weakens the gate. A technician who genuinely cannot tell —
     * an intermittent fault, a car leaving before anyone could confirm — must have somewhere truthful
     * to put that. Without this option the only way past the screen is to claim `complete`, and the
     * dataset silently fills with false successes. Unknown beats invented, here as everywhere.
     */
    public const OUTCOME_UNKNOWN = 'unknown';

    public const OUTCOMES = [
        self::OUTCOME_COMPLETE, self::OUTCOME_PARTIAL,
        self::OUTCOME_TEMPORARY, self::OUTCOME_NO_IMPROVEMENT,
        self::OUTCOME_UNKNOWN,
    ];

    public const VERIFY_ROAD_TEST      = 'road_test';
    public const VERIFY_SCAN_TOOL      = 'scan_tool';
    public const VERIFY_PRESSURE_TEST  = 'pressure_test';
    public const VERIFY_MEASUREMENT    = 'measurement';
    public const VERIFY_VISUAL         = 'visual';
    public const VERIFY_CUSTOMER       = 'customer_confirm';
    public const VERIFY_OTHER          = 'other';

    public const VERIFICATION_METHODS = [
        self::VERIFY_ROAD_TEST, self::VERIFY_VISUAL, self::VERIFY_SCAN_TOOL,
        self::VERIFY_MEASUREMENT, self::VERIFY_PRESSURE_TEST, self::VERIFY_CUSTOMER,
        self::VERIFY_OTHER,
    ];

    public function __construct(private readonly EventRecorder $recorder)
    {
    }

    /**
     * Record what was done to a fault, and what the technician concluded.
     *
     * @param  array{
     *     actions?: array<int,array{action_catalog_id:int,note?:string|null,line_item_id?:int|null}>,
     *     claimed_outcome?: string|null,
     *     verification_method?: string|null,
     *     no_fault_found?: bool,
     *     note?: string|null,
     *     duration_ms?: int|null,
     *     skipped_fields?: array<int,string>
     * }  $data
     */
    public function capture(MaintenanceTask $task, array $data, User $actor): MaintenanceTask
    {
        $noFaultFound = (bool) ($data['no_fault_found'] ?? false);
        $actions      = array_values($data['actions'] ?? []);
        $outcome      = $data['claimed_outcome'] ?? null;
        $verification = $data['verification_method'] ?? null;

        $this->validate($actions, $outcome, $verification, $noFaultFound);

        // Read BEFORE the write. Asking afterwards would always say "yes", because the transaction
        // below stamps the very column the question is about — which would have made every first
        // capture look like a correction and rendered the correction-rate metric useless.
        $isCorrection = $task->claimed_outcome_at !== null
            || MaintenanceTaskAction::where('maintenance_task_id', $task->id)->exists();

        $catalog = ActionCatalog::query()
            ->whereIn('id', array_column($actions, 'action_catalog_id'))
            ->get()
            ->keyBy('id');

        $task = DB::transaction(function () use ($task, $actions, $catalog, $outcome, $verification, $noFaultFound, $data, $actor) {
            // Re-capture replaces the previous action list rather than appending. A technician
            // correcting a mistake must not silently double the repair — and the domain events keep
            // the superseded version, so nothing is actually lost by rewriting the read model here.
            MaintenanceTaskAction::where('maintenance_task_id', $task->id)->delete();

            foreach ($actions as $i => $line) {
                MaintenanceTaskAction::create([
                    'maintenance_task_id' => $task->id,
                    'maintenance_id'      => $task->maintenance_id,
                    'vehicle_id'          => $task->vehicle_id,
                    'action_catalog_id'   => (int) $line['action_catalog_id'],
                    'sequence'            => $i + 1,
                    'performed_by'        => $actor->id,
                    'performed_by_name'   => $actor->name,
                    'vendor_id'           => $task->current_vendor_id,
                    'performed_at'        => now(),
                    'note'                => $line['note'] ?? null,
                    'line_item_id'        => $line['line_item_id'] ?? null,
                    'recorded_via'        => MaintenanceTaskAction::VIA_WORKFLOW,
                ]);
            }

            $task->forceFill([
                'claimed_outcome'     => $noFaultFound ? null : $outcome,
                'claimed_outcome_by'  => $noFaultFound ? null : $actor->id,
                'claimed_outcome_at'  => $noFaultFound ? null : now(),
                'verification_method' => $verification,
                'no_fault_found'      => $noFaultFound,
            ]);

            if (filled($data['note'] ?? null)) {
                $task->resolution_note = $data['note'];
            }

            $task->save();

            return $task;
        });

        $this->recordEvents($task, $actions, $catalog, $outcome, $verification, $noFaultFound, $actor);
        $this->recordFriction($task, $data, $actions, $actor, $isCorrection);
        $this->recordComponents($task, $catalog, $actor);

        return $task->fresh();
    }

    /**
     * Asset Layer bridge — a `replace` action is a part going onto a car, whether or not anyone
     * raised a purchase for it.
     *
     * OUTSIDE THE CAPTURE TRANSACTION, ON PURPOSE, AND NEVER ENFORCED. Every other asset-layer call
     * site honours the three-mode flag contract (off / shadow / enforced), where enforced makes the
     * component write atomic with the workflow write. This one is shadow-only even when the flag
     * says enforced, and the asymmetry is deliberate:
     *
     * The parts screen is a parts screen — asking it for a serial or a disposition is fair, and
     * failing the install when it cannot answer is a reasonable trade. The capture screen is a
     * technician standing next to a car answering four questions, and repair actions are the
     * scarcer evidence of the two by an enormous margin: the fleet has 26,838 tickets with 22
     * populated findings between them, which is the entire reason Tier-1 capture exists. Losing a
     * repair record because the asset ledger could not resolve a slot would be trading the rare
     * thing for the recoverable one. The component can always be reconciled later — from the
     * purchase, or by hand. The technician is not coming back.
     *
     * Reads the flag directly rather than taking a mode parameter, so `ASSET_LAYER_MODE=off` still
     * means byte-identical behaviour here, exactly as it does everywhere else.
     */
    private function recordComponents(MaintenanceTask $task, $catalog, User $actor): void
    {
        if (config('features.asset_layer', 'off') === 'off') {
            return;
        }

        // Re-read rather than reusing the submitted array: capture rewrites its action rows, and
        // what is in the table now is what actually happened.
        $actions = MaintenanceTaskAction::where('maintenance_task_id', $task->id)->orderBy('sequence')->get();
        if ($actions->isEmpty()) {
            return;
        }

        // Resolved once, outside the loop — the car's reading is the same for every action on one
        // capture, and looking it up per action would be an N+1 on the technician's submit.
        $odometer = Vehicle::whereKey($task->vehicle_id)->value('odometer');
        $service  = app(ComponentService::class);

        foreach ($actions as $action) {
            $entry = $catalog[$action->action_catalog_id] ?? null;
            if (! $entry) {
                continue;
            }

            try {
                $service->installFromAction($action, $entry, [
                    'installed_odometer' => $odometer,
                    'technician_name'    => $action->performed_by_name,
                ], $actor);
            } catch (Throwable $e) {
                // Reported, never rethrown — see the docblock. One unresolvable action must not
                // cost the capture, and must not stop the actions after it either.
                report($e);
                Log::warning('asset_layer.capture_component_failed', [
                    'task_id'   => $task->id,
                    'action_id' => $action->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The Tier 1 gate.
     *
     * Deliberately thin. Everything rejected here is a genuine contradiction rather than a missing
     * nicety — a repair with no actions, an outcome nobody chose, or a claim of success with nothing
     * to support it. Rejecting anything softer would push people to enter noise to get past the
     * screen, which produces worse data than a shorter form.
     *
     * @param  array<int,array>  $actions
     */
    private function validate(array $actions, ?string $outcome, ?string $verification, bool $noFaultFound): void
    {
        $errors = [];

        if ($noFaultFound) {
            // Nothing further is required: there was nothing to repair. Demanding actions here is
            // asking for fiction.
            if ($actions !== []) {
                $errors['actions'] = ['A fault recorded as "no fault found" cannot also have repair actions.'];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        if ($actions === []) {
            $errors['actions'] = ['Record what was done — at least one repair action, or mark it as "no fault found".'];
        }

        if (! in_array($outcome, self::OUTCOMES, true)) {
            $errors['claimed_outcome'] = ['Say whether this solved the complaint: completely, partially, temporarily, or not at all.'];
        }

        // VERIFICATION IS NO LONGER ASKED FOR HERE.
        //
        // It used to be, and the rule "a complete fix needs a verification" lived at this line. Both
        // are gone because the person performing a repair must never be the only person confirming
        // it — a check supplied by the claimant in the same breath as the claim is not a check. It is
        // now a separate act by an inspector, see verify().
        //
        // The workshop can therefore claim `complete` here without providing evidence, and that is
        // correct: an unverified claim is exactly what it is, and the dataset should say so rather
        // than extract a self-issued confirmation to satisfy a form.
        if ($verification !== null) {
            $errors['verification_method'] = ['Verification is recorded by an inspector, not with the repair.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * One capture step, several immutable statements — the technician sees none of this.
     *
     * @param  array<int,array>  $actions
     */
    private function recordEvents(
        MaintenanceTask $task,
        array $actions,
        $catalog,
        ?string $outcome,
        ?string $verification,
        bool $noFaultFound,
        User $actor,
    ): void {
        try {
            $this->recorder->session(function () use ($task, $actions, $catalog, $outcome, $verification, $noFaultFound, $actor) {
                $subject = [
                    'vehicle_id'          => $task->vehicle_id,
                    'maintenance_id'      => $task->maintenance_id,
                    'maintenance_task_id' => $task->id,
                ];

                // The person recording is ours; the work may be a garage's. Attributing the action to
                // whoever is at the keyboard would make every external repair look internally
                // verified, so the vendor drives the provenance.
                $who = $task->current_vendor_id
                    ? Provenance::garage((int) $task->current_vendor_id, $actor->name)
                    : Provenance::staff($actor);

                foreach ($actions as $i => $line) {
                    $entry = $catalog->get((int) $line['action_catalog_id']);

                    $this->recorder->record(DomainEvent::REPAIR_ACTION_PERFORMED, [
                        'action_id'     => (int) $line['action_catalog_id'],
                        'action'        => $entry?->slug,
                        'verb'          => $entry?->verb,
                        'target'        => $entry?->target,
                        'sequence'      => $i + 1,
                        'requires_part' => (bool) $entry?->requires_part,
                        'note'          => $line['note'] ?? null,
                    ], $who, $subject);
                }

                if ($noFaultFound) {
                    // Recorded as an outcome in its own right. NFF is how intermittent faults and
                    // customer-education cases become visible instead of vanishing into "closed".
                    $this->recorder->record(DomainEvent::OUTCOME_CLAIMED, [
                        'claimed_outcome' => 'no_fault_found',
                        'source'          => 'repair_capture',
                    ], $who, $subject);

                    return;
                }

                $this->recorder->record(DomainEvent::OUTCOME_CLAIMED, [
                    'claimed_outcome' => $outcome,
                    'source'          => 'repair_capture',
                    'action_count'    => count($actions),
                ], $who, $subject);

                // No VerificationCompleted is written here. See validate(): verification belongs to
                // the inspector and is recorded by verify().
            });
        } catch (Throwable $e) {
            // The repair is already saved. An event-log failure must never present itself to the
            // technician as their work having failed — see [[CaptureTranslator]].
            Log::error('Repair capture: event recording failed', [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rollout instrumentation.
     *
     * Recorded on every capture so adoption is measured from the first day rather than reconstructed
     * later. `skipped_fields` is the actionable column: a field skipped by everybody is one to remove
     * or default, and this turns that from an argument into a measurement.
     *
     * @param  array<string,mixed>  $data
     * @param  array<int,array>  $actions
     */
    private function recordFriction(MaintenanceTask $task, array $data, array $actions, User $actor, bool $isCorrection): void
    {
        // Offered is now the three workshop-side fields plus the optional note and the
        // no-fault-found escape — verification moved to the inspector and is measured in its own
        // session. Counted from what actually arrived, not from what the client claims it showed.
        $filled = 0;
        $filled += $actions !== [] ? 1 : 0;
        $filled += filled($data['claimed_outcome'] ?? null) ? 1 : 0;
        $filled += filled($data['note'] ?? null) ? 1 : 0;
        $filled += ! empty($data['no_fault_found']) ? 1 : 0;

        $payload = [
            'duration_ms'    => isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            'fields_filled'  => $filled,
            'skipped_fields' => array_values($data['skipped_fields'] ?? []),
            'last_step'      => $data['last_step'] ?? null,
            // A capture landing on a fault that already had one is a correction — friction that has
            // already cost somebody twice. Determined before the write; see capture().
            'was_corrected'  => $isCorrection,
        ];

        // Normal path: close the session the client opened when the form opened, so one attempt is
        // one row and abandonment is simply a session that never closed.
        if (filled($data['session_id'] ?? null)) {
            app(CaptureFrictionService::class)->resolve(
                $data['session_id'],
                CaptureFrictionService::STATUS_COMPLETED,
                $payload,
            );

            return;
        }

        // Fallback for a client that never opened one (an API caller, or an older build). Recorded
        // as completed so the capture is still counted — a missing start must not make a real
        // capture invisible.
        try {
            DB::table('capture_friction')->insert([
                'step'                => 'repair_capture',
                'status'              => CaptureFrictionService::STATUS_COMPLETED,
                'maintenance_id'      => $task->maintenance_id,
                'maintenance_task_id' => $task->id,
                'user_id'             => $actor->id,
                'duration_ms'         => $payload['duration_ms'],
                'fields_offered'      => 4,
                'fields_filled'       => $filled,
                'skipped_fields'      => $payload['skipped_fields'] === [] ? null : json_encode($payload['skipped_fields']),
                'was_corrected'       => $isCorrection,
                'occurred_at'         => now(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Repair capture: friction telemetry failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The actions a technician is most likely to need for this fault, best first.
     *
     * Ordered by what the ontology knows about the fault's category rather than alphabetically,
     * because a picker that opens on the right five entries is the difference between structured
     * capture and a free-text box people paste into.
     *
     * @return \Illuminate\Support\Collection<int,ActionCatalog>
     */
    public function suggestedActions(MaintenanceTask $task, int $limit = 12)
    {
        $category = $task->category_key;

        return ActionCatalog::query()
            ->active()
            ->when($category, fn ($q) => $q->forSystem($category))
            ->orderBy('is_verification')       // repairs first, verification actions after
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();
    }
}
