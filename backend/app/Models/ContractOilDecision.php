<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a person decided about a rental that will finish past its oil tolerance — Evidence class
 * **J** (a judgement; the numbers it was made against are class P, and are snapshotted here).
 *
 * Written by OilChangeProjectionService::decide() from the Oil Follow-up board, and closed out by
 * settleOnReturn() when the rental ends and the owed oil change becomes a real ticket.
 */
class ContractOilDecision extends Model
{
    use HasFactory;

    /** Get the car back before it exceeds the safe tolerance. */
    public const DECISION_RECALL = 'recall';

    /** Accept the overrun; service it the moment the contract closes. */
    public const DECISION_DEFER = 'defer';

    public const DECISIONS = [self::DECISION_RECALL, self::DECISION_DEFER];

    // ── The recall's operational stages ──────────────────────────────────────────────────────
    // A recall is not one act, it is a relay: Sales agrees the return, a driver is found, the car
    // is collected, it arrives, it is inspected, the oil is changed. NONE of these are new stored
    // states — each one is READ from the record that already owns it (this row's sales stamp, the
    // LogisticsTask's phase, the follow-up request's workflow_status, the service ticket). They
    // exist so the board can always answer "what happens next, and who does it".

    /** Recall ordered; nobody has agreed the return with the customer yet. Nothing is dispatched. */
    public const STAGE_WAITING_SALES = 'waiting_sales';

    /** Sales confirmed. The collection is raised and the Supervisors have been asked for a driver. */
    public const STAGE_READY_FOR_DRIVER = 'ready_for_driver';

    /** A driver owns the job and is on the way to the customer. */
    public const STAGE_DRIVER_ASSIGNED = 'driver_assigned';

    /** The driver has the car — custody has passed from the customer to us. */
    public const STAGE_VEHICLE_COLLECTED = 'vehicle_collected';

    /** The car is at the workshop; the follow-up request is now reviewable. */
    public const STAGE_AT_WORKSHOP = 'at_workshop';

    /** The inspection/test is running. */
    public const STAGE_INSPECTION = 'inspection';

    /** The oil change itself is open as a real ticket. */
    public const STAGE_OIL_SERVICE = 'oil_service';

    /** The oil is changed and the car is ours — it now owes the customer their rental back. */
    public const STAGE_RETURN_TO_CUSTOMER = 'return_to_customer';

    /** Everything the recall owed has been done. */
    public const STAGE_COMPLETED = 'completed';

    /** The recall was stood down (revised to "do it on return"). */
    public const STAGE_CANCELLED = 'cancelled';

    // ── WHERE the oil gets changed — and therefore WHO is told ───────────────────────────────
    // Not a preference and not derivable: it is Leen's call on the day, and it routes the job to a
    // different person. The driver is told in both cases; he is the one fetching the car.

    /** Our own parking — the Inspector (Abu Maroof) does the change. */
    public const LOCATION_PARKING = 'parking';

    /** An external garage — the Supervisors arrange it. */
    public const LOCATION_GARAGE = 'garage';

    public const LOCATIONS = [self::LOCATION_GARAGE, self::LOCATION_PARKING];

    protected $fillable = [
        'contract_id',
        'vehicle_id',
        'decision',
        'anchor_reading_id',
        'oil_limit',
        'allowed_max',
        'expected_return_odometer',
        'remaining_days',
        'decided_by',
        'decided_by_name',
        'note',
        'is_auto',
        'settled_at',
        'settled_ticket_id',
    ];

    protected $casts = [
        'oil_limit'                => 'integer',
        'allowed_max'              => 'integer',
        'expected_return_odometer' => 'integer',
        'remaining_days'           => 'integer',
        'is_auto'                  => 'boolean',
        'settled_at'               => 'datetime',
        'sales_confirmed_at'       => 'datetime',
        'test_required'            => 'boolean',
        'oil_changed_at'           => 'datetime',
        'oil_changed_odometer'     => 'integer',
        'request_adopted'          => 'boolean',
        'returned_to_customer_at'  => 'datetime',
        'return_reminder_at'       => 'datetime',
    ];

    /**
     * Are we still holding a car whose oil is already done?
     *
     * The rental has not ended — the customer is paying for a car standing in our yard. This is the
     * step that has no natural owner and no natural alarm, which is why it has both here: a stage of
     * its own on every board, and a chase that keeps ringing until somebody hands the keys back.
     */
    public function owesReturnToCustomer(): bool
    {
        return $this->oilWorkDone()
            && $this->returned_to_customer_at === null
            && $this->contract
            && $this->contract->state !== 'closed'
            && $this->contract->in_date === null;
    }

    /** Where the oil will be changed, defaulting to the garage when nobody has said. */
    public function serviceLocation(): string
    {
        return in_array($this->service_location, self::LOCATIONS, true)
            ? $this->service_location
            : self::LOCATION_GARAGE;
    }

    /**
     * Is the inspection request this recall points at one we ADOPTED rather than raised?
     *
     * An adopted request (typically the system's own "routine check overdue") carries a test the oil
     * change does not perform, so the oil lifecycle must never close it. See the migration.
     */
    public function hasAdoptedRequest(): bool
    {
        return (bool) $this->request_adopted && $this->inspection_ticket_id !== null;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The routine ticket this decision finally became, once the car came back. */
    public function settledTicket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'settled_ticket_id');
    }

    /** The inspection FOLLOW-UP request this decision raised — announces the car before it arrives. */
    public function inspectionTicket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'inspection_ticket_id');
    }

    /** Still owed: nobody has turned it into a ticket yet. */
    public function isOpen(): bool
    {
        return $this->settled_at === null;
    }

    /** The person who confirmed Sales had agreed the return with the customer. */
    public function salesConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_confirmed_by');
    }

    /** The ONE driver collection this recall raised — the movement, owned by the logistics lane. */
    public function collectionTask(): BelongsTo
    {
        return $this->belongsTo(LogisticsTask::class, 'collection_task_id');
    }

    /** A recall that is still waiting for Sales to agree the return with the customer. */
    public function isAwaitingSalesConfirmation(): bool
    {
        return $this->decision === self::DECISION_RECALL
            && $this->isOpen()
            && $this->sales_confirmed_at === null;
    }

    /** Sales have agreed the return — the collection may be raised and the drivers told. */
    public function isSalesConfirmed(): bool
    {
        return $this->sales_confirmed_at !== null;
    }

    /** The person who recorded the completed oil change. */
    public function oilChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oil_changed_by');
    }

    /**
     * The oil has actually been changed and the reading recorded — the ONE fact that ends this
     * follow-up. Everything before it (decision, Sales OK, collection, inspection) is arrangement.
     */
    public function isOilChanged(): bool
    {
        return $this->oil_changed_at !== null;
    }

    /**
     * Is the oil actually done — by EITHER hand that can do it?
     *
     * There are two, and they leave different traces. Somebody in our own yard reads the dial and
     * presses "Oil changed", which stamps this row (isOilChanged) and moves the car's service anchor.
     * A GARAGE does it inside the maintenance workflow instead, and the trace is the ticket closing —
     * that path stamps the vehicle through confirmRoutineServices() and never touches this row.
     *
     * Asking only the first question is what made a garage-changed car go quiet: the ticket closed,
     * the workshop moved on, and the recall read "completed" while a customer was still paying for a
     * car standing in our parking. Everything that asks "is the work finished, can we give it back"
     * asks THIS, not `oil_changed_at`.
     */
    public function oilWorkDone(): bool
    {
        if ($this->isOilChanged()) {
            return true;
        }

        $ticket = $this->settled_ticket_id ? $this->settledTicket : null;

        return $ticket !== null && in_array($ticket->workflow_status, Maintenance::WF_TERMINAL, true);
    }

    /**
     * A recalled car that has landed with NO test asked for, and nobody holding it yet.
     *
     * This is the gap the hand-over closes. With a test there is an inspection request, so the car is
     * announced, reviewed and handed to the Inspector. Without one there is no request at all — the
     * car arrives and the only thing that knows it owes an oil change is this row. So the moment it
     * lands it is handed to the Supervisors as a real ticket in their dispatch queue, where reading
     * the dial and picking the garage is the job they already do.
     *
     * Parking is excluded on purpose: the change happens here, by our own inspector, and there is no
     * garage for a Supervisor to pick.
     *
     * @see \App\Services\OilChangeProjectionService::handOverAtWorkshop()
     */
    public function awaitsSupervisorHandOver(): bool
    {
        if ($this->decision !== self::DECISION_RECALL
            || ! $this->isOpen()
            || $this->test_required
            || $this->oilWorkDone()
            || $this->settled_ticket_id !== null
            || $this->serviceLocation() !== self::LOCATION_GARAGE
            || $this->recallStage() !== self::STAGE_AT_WORKSHOP) {
            return false;
        }

        // A request still alive means somebody IS being asked to look at this car — an adopted
        // routine check, or one a Controller already released to the Inspector — whatever the test
        // flag says. That car is spoken for; handing it to the Supervisors as well would put it in
        // two queues at once.
        $request = $this->inspection_ticket_id ? $this->inspectionTicket : null;

        return $request === null || in_array($request->workflow_status, Maintenance::WF_TERMINAL, true);
    }

    /**
     * What must happen to this car once it is back, as facts rather than checkboxes.
     *
     * The oil change is NOT stored and NOT settable. This recall exists because the oil lifecycle
     * said the car needs oil attention, so "oil change required" is simply what a recall MEANS —
     * derive it and nobody can write it away later by re-saving the row with one box unticked.
     * The test is the opposite: a genuine operational instruction the dispatcher gives the driver,
     * which is why it is the only half that is stored (and the only half the API accepts).
     *
     * @return array{oil_change:array{required:bool,locked:bool,reason:string}, test:array{required:bool,locked:bool,decided:bool}}
     */
    public function requiredActions(): array
    {
        return [
            'oil_change' => [
                'required' => true,
                'locked'   => true,
                // A code, not a sentence — the wording belongs to the UI. See [[reason-code-contract]].
                'reason'   => 'oil_projection_recall',
                // Required stays true forever (it is why this recall exists); `done` is the separate
                // question of whether the workshop has actually performed it, with the reading it
                // was performed at. A requirement that quietly disappears once it is met loses the
                // reason the customer was interrupted in the first place.
                'done'     => $this->oilWorkDone(),
                'done_at'  => optional($this->oil_changed_at)->toIso8601String(),
                'odometer' => $this->oil_changed_odometer,
                'done_by'  => $this->oil_changed_by_name,
            ],
            'test' => [
                'required' => (bool) $this->test_required,
                'locked'   => false,
                'decided'  => $this->test_required !== null,
                // The answer is not a preference, it is a ROUTE: with a test the car is announced to
                // the review queue and handed to the Inspector, who runs the workflow; without one it
                // goes straight to the Supervisors, who read the dial and pick the garage. Published
                // so the card can say who is waiting for the car instead of leaving people to guess.
                'routes_to' => $this->test_required
                    ? 'inspector'
                    : ($this->serviceLocation() === self::LOCATION_PARKING ? 'inspector' : 'supervisor'),
            ],
        ];
    }

    /**
     * Where this recall has actually got to — derived, never stored.
     *
     * Read in strict outcome-first order: what the car has already been through beats what it is
     * waiting for, so a car whose oil ticket is closed reads "completed" even though every earlier
     * record still exists. Null for a defer — there is no relay to run.
     */
    public function recallStage(): ?string
    {
        if ($this->decision !== self::DECISION_RECALL) {
            return null;
        }

        // ── The far end: the oil change this whole relay existed to produce ──
        // A recorded change beats every other record, including an open service ticket: the oil IS
        // changed, and the reading that proves it is on this row. But "the oil is done" is not the
        // end of a RECALL — we took the car off a paying customer, and it is only finished when
        // they have it back.
        // Either hand counts (see oilWorkDone): our own "Oil changed" stamp, or the garage's ticket
        // closing. A car whose garage ticket is shut is just as finished as one someone recorded by
        // hand — and just as much still ours until the customer has it back.
        if ($this->oilWorkDone()) {
            return $this->owesReturnToCustomer()
                ? self::STAGE_RETURN_TO_CUSTOMER
                : self::STAGE_COMPLETED;
        }
        if ($this->settled_ticket_id) {
            return self::STAGE_OIL_SERVICE;
        }
        if ($this->settled_at) {
            // Settled with no ticket: the car came back and the ACTUAL mileage proved oil was not
            // due. The recall is finished either way — the arithmetic answered it, not a person.
            return self::STAGE_COMPLETED;
        }

        // ── Where the CAR is: the collection chain, which is the physical truth ──────────────
        // Computed FIRST, before the inspection, because a reviewer approving the request early is
        // an office decision — it does not move a car one metre. A recalled car may be approved
        // while it is still at the customer's (the return is being organised, so the request is not
        // waiting on chance), and if approval outranked this the board would announce "Being
        // inspected" for a car nobody has collected, and quietly hide the Sales OK button that is
        // the actual next action.
        $where = $this->collectionStage();

        // ── The inspection, once the request is released AND the car is genuinely here ──────
        $request = $this->inspectionTicket;
        $released = $request
            && $request->workflow_status !== Maintenance::WF_PENDING_REVIEW
            && ! in_array($request->workflow_status, Maintenance::WF_TERMINAL, true);

        if ($released && in_array($where, [self::STAGE_VEHICLE_COLLECTED, self::STAGE_AT_WORKSHOP], true)) {
            return self::STAGE_INSPECTION;
        }

        return $where;
    }

    /**
     * The physical half of the relay — where the car actually is, read from the Sales stamp and the
     * collection's own status. Never from the inspection request: paperwork does not move cars.
     */
    private function collectionStage(): string
    {
        if (! $this->isSalesConfirmed()) {
            return self::STAGE_WAITING_SALES;
        }

        $task = $this->collectionTask;
        if (! $task) {
            // Sales agreed but no movement is on file — the collection could not be raised. Say
            // "find a driver", which is the true next action, rather than inventing progress.
            return self::STAGE_READY_FOR_DRIVER;
        }

        if ($task->status === LogisticsTask::STATUS_CANCELLED) {
            return self::STAGE_CANCELLED;
        }

        return match ($task->status) {
            LogisticsTask::STATUS_DISPATCHED => self::STAGE_READY_FOR_DRIVER,
            LogisticsTask::STATUS_EN_ROUTE   => self::STAGE_DRIVER_ASSIGNED,
            LogisticsTask::STATUS_PICKED_UP,
            LogisticsTask::STATUS_IN_TRANSIT,
            LogisticsTask::STATUS_TO_DESTINATION => self::STAGE_VEHICLE_COLLECTED,
            default => self::STAGE_AT_WORKSHOP,   // delivered / returned / legacy arrivals
        };
    }

    /**
     * Is the car physically ours right now? The LogisticsTask pick-up step is the custody fact —
     * the rental contract stays open until OfficeManager closes it, so `vehicles.operational_status`
     * still reads "rented" long after a driver has taken the keys. Anything that asks "can we work
     * on this car yet" must ask this, not the contract.
     */
    public function inOurCustody(): bool
    {
        $stage = $this->recallStage();

        return in_array($stage, [
            self::STAGE_VEHICLE_COLLECTED,
            self::STAGE_AT_WORKSHOP,
            self::STAGE_INSPECTION,
            self::STAGE_OIL_SERVICE,
        ], true);
    }
}
