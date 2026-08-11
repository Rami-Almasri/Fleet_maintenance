<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Logistics Dispatch task: an order to MOVE a vehicle, now run as a CLAIM-based, driver-executed
 * round trip that replaces the WhatsApp relay. A coordinator raises an unassigned request; the first
 * driver to claim it owns the move and steps it along:
 *
 *   dispatched   raised by a coordinator, sitting in the driver pool, awaiting a claim
 *   en_route     a driver claimed it and is on the way to collect the car   (locked to that driver)
 *   picked_up    the car is now with the driver, in transit to the destination
 *   delivered    the car is at the destination (e.g. the garage)
 *   returned     the car is back at base — the terminal step (GPS-verified)  [round trips]
 *   cancelled    the move was called off                                     (terminal)
 *
 * For a one-way move (round_trip = false) the "delivered" step IS the end: the car stays at the
 * destination, so the task closes there. Terminality is therefore keyed on `completed_at`, which the
 * service stamps on whichever step ends the move (returned, one-way delivered, or cancelled). That one
 * column is the single source of truth for "is the car still out?" — scopeOpen() reads it directly.
 *
 * Legacy rows from the pre-claim era still resolve: `in_transit` (the old single open leg) and the
 * round-trip drafts `to_destination` / `at_destination` / `to_base` are recognised as active phases;
 * `completed` is the legacy terminal marker (old `delivered` rows were migrated to `returned`).
 *
 * Self-contained snapshots (plate / label / people names) keep a task meaningful after a re-sync or a
 * rename; vehicle / user ids stay loose (indexed, no FK), mirroring maintenance_swaps.
 */
class LogisticsTask extends Model
{
    // Canonical claim lifecycle (the phases the board steps through).
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_EN_ROUTE   = 'en_route';
    public const STATUS_PICKED_UP  = 'picked_up';
    public const STATUS_DELIVERED  = 'delivered';
    public const STATUS_RETURNED   = 'returned';
    public const STATUS_CANCELLED  = 'cancelled';

    // Legacy phases — kept so pre-claim rows still resolve everywhere.
    public const STATUS_IN_TRANSIT     = 'in_transit';     // legacy single open leg ≈ picked_up
    public const STATUS_TO_DESTINATION = 'to_destination'; // legacy round-trip draft ≈ picked_up
    public const STATUS_AT_DESTINATION = 'at_destination'; // legacy round-trip draft ≈ delivered
    public const STATUS_TO_BASE        = 'to_base';        // legacy round-trip draft ≈ delivered (returning)
    public const STATUS_COMPLETED      = 'completed';      // legacy terminal marker ≈ returned

    /** Non-terminal phases. NB open/closed is decided by completed_at, not this list (see scopeOpen). */
    public const ACTIVE_STATUSES = [
        self::STATUS_DISPATCHED,
        self::STATUS_EN_ROUTE,
        self::STATUS_PICKED_UP,
        self::STATUS_DELIVERED,
        self::STATUS_IN_TRANSIT,
        self::STATUS_TO_DESTINATION,
        self::STATUS_AT_DESTINATION,
        self::STATUS_TO_BASE,
    ];

    /** Terminal phases — the move is finished. */
    public const TERMINAL_STATUSES = [self::STATUS_RETURNED, self::STATUS_CANCELLED, self::STATUS_COMPLETED];

    /**
     * TRANSPORT phases — the driver physically has the car and is moving it A→B. This, and ONLY this, is
     * what makes a driver BUSY (see DriverAvailabilityService). A claimed-but-pre-pickup leg (en_route,
     * going to COLLECT the car) is included because the driver is already committed to the job and can't
     * take another; a leg sitting AT the destination (delivered / at_destination) is NOT transport — the
     * car has been handed over and the driver is free again even while the task itself stays open.
     */
    public const TRANSPORT_STATUSES = [
        self::STATUS_EN_ROUTE,
        self::STATUS_PICKED_UP,
        self::STATUS_IN_TRANSIT,     // legacy ≈ picked_up
        self::STATUS_TO_DESTINATION, // legacy ≈ picked_up
        self::STATUS_TO_BASE,        // legacy ≈ returning
    ];

    /**
     * The only legal FORWARD step out of each phase (the driver walking the move along). Claiming
     * (dispatched → en_route) and cancelling are handled separately. From `delivered` a round trip
     * heads home (returned) while a one-way move is already finished. Legacy phases fast-forward to
     * the nearest sensible step so old rows can still be closed.
     */
    public const TRANSITIONS = [
        self::STATUS_EN_ROUTE       => [self::STATUS_PICKED_UP],
        self::STATUS_PICKED_UP      => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED      => [self::STATUS_RETURNED],
        self::STATUS_IN_TRANSIT     => [self::STATUS_DELIVERED, self::STATUS_RETURNED],
        self::STATUS_TO_DESTINATION => [self::STATUS_DELIVERED, self::STATUS_RETURNED],
        self::STATUS_AT_DESTINATION => [self::STATUS_RETURNED],
        self::STATUS_TO_BASE        => [self::STATUS_RETURNED],
    ];

    /** Common destinations surfaced as quick-picks in the UI (free text is still allowed). */
    public const COMMON_DESTINATIONS = ['Deals on Wheels', 'Garage', 'Office', 'Showroom', 'Parking Yard'];

    /**
     * GO AND GET THE CAR FROM THE CUSTOMER — a collection, not an ordinary run.
     *
     * The car is somebody else's until the driver takes the keys, and the reading at the doorstep is
     * the reason the trip exists, so this kind of move gets its own heading in the driver's queue and
     * always demands the odometer at pick-up. See the `purpose` migration.
     */
    public const PURPOSE_CUSTOMER_COLLECTION = 'oil_recall_collection';

    /** Is this move a collection from a customer's doorstep? */
    public function isCustomerCollection(): bool
    {
        return $this->purpose === self::PURPOSE_CUSTOMER_COLLECTION;
    }

    /** One-click status replies an assignee can send back to a "Ping location" (free text also allowed). */
    public const STATUS_PRESETS = ['At site', 'In traffic', 'Arrived'];

    protected $fillable = [
        'vehicle_id', 'vehicle_plate', 'vehicle_label', 'maintenance_id', 'purpose',
        'destination', 'round_trip',
        'assigned_to_id', 'assigned_to_name',
        'assigned_by_id', 'assigned_by_name',
        'status', 'status_changed_at', 'notes',
        'dispatched_at', 'claimed_at', 'completed_at', 'completed_by_name',
        'returned_at', 'returned_lat', 'returned_lng', 'returned_accuracy',
        // "Where is the car?" ping/reply
        'last_status', 'last_status_at', 'last_status_by', 'last_pinged_at',
    ];

    protected $casts = [
        'round_trip'        => 'boolean',
        'dispatched_at'     => 'datetime',
        'claimed_at'        => 'datetime',
        'status_changed_at' => 'datetime',
        'completed_at'      => 'datetime',
        'returned_at'       => 'datetime',
        'returned_lat'      => 'float',
        'returned_lng'      => 'float',
        'returned_accuracy' => 'float',
        'last_status_at'    => 'datetime',
        'last_pinged_at'    => 'datetime',
    ];

    /** Only tasks still in effect — the car is out on a move. completed_at is the canonical flag. */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('completed_at');
    }

    /** Is the car still on this move? (Nothing has closed it yet.) */
    public function isActive(): bool
    {
        return $this->completed_at === null;
    }

    /**
     * Open tasks whose driver is physically MOVING the car right now — the only phase that makes a driver
     * "busy". Excludes tasks parked at the destination (delivered / at_destination): the car is handed
     * over, the driver is free, even though the task stays open on a round trip.
     */
    public function scopeInTransport(Builder $q): Builder
    {
        return $q->open()->whereIn('status', self::TRANSPORT_STATUSES);
    }

    /** A pooled, unclaimed request any driver may take. */
    public function isClaimable(): bool
    {
        return $this->isActive()
            && $this->status === self::STATUS_DISPATCHED
            && empty($this->assigned_to_id);
    }

    /** May this task step forward to the given phase from where it is now? */
    public function canMoveTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Does this move carry a strict before/after odometer pair? Garage trips — anything brought back
     * (round_trip) or tied to a maintenance ticket — do; a plain one-way move (e.g. to the showroom)
     * does not. Mirrors LogisticsDispatchController::requiresOdometer().
     */
    public function requiresOdometer(): bool
    {
        // A collection always does: the reading at the customer's doorstep is the reason the trip
        // exists, and once the car is ours that moment has gone.
        return (bool) ($this->round_trip || $this->maintenance_id || $this->isCustomerCollection());
    }

    /**
     * The forward step(s) the assignee can take from here, as [{action, label, to, needs_odometer}] for
     * the UI. `action` is the verb the API expects (pickup / deliver / return); `needs_odometer` tells
     * the frontend to collect the reading + photo before posting. Empty when there's nothing left to do
     * (a one-way move that's been delivered, or a closed task).
     */
    public function nextActions(): array
    {
        if (! $this->isActive()) {
            return [];
        }

        $map = [
            self::STATUS_RETURNED  => ['return', 'Returned / Arrived'],
            self::STATUS_DELIVERED => ['deliver', 'Delivered'],
            self::STATUS_PICKED_UP => ['pickup', 'Picked Up'],
        ];

        $requiresOdo = $this->requiresOdometer();
        $out = [];
        foreach (self::TRANSITIONS[$this->status] ?? [] as $to) {
            // One-way moves finish at "delivered" — never offer a "return to base" step for them.
            if ($to === self::STATUS_RETURNED && ! $this->round_trip
                && in_array($this->status, [self::STATUS_DELIVERED], true)) {
                continue;
            }
            [$action, $label] = $map[$to] ?? [null, null];
            if (! $action) {
                continue;
            }
            // Odometer is captured at pick-up (before), and at the move's POST point: delivery for a
            // one-way move, or return-to-base for a round trip.
            $needs = match ($action) {
                'pickup'  => $requiresOdo,
                'deliver' => $requiresOdo && ! $this->round_trip,
                'return'  => $requiresOdo,
                default   => false,
            };
            $out[] = ['action' => $action, 'label' => $label, 'to' => $to, 'needs_odometer' => $needs];
        }

        return $out;
    }

    /** Does reaching $to close the move? (Round trips end at base; one-way moves end at delivery.) */
    public function isTerminalStep(string $to): bool
    {
        if (in_array($to, [self::STATUS_RETURNED, self::STATUS_CANCELLED, self::STATUS_COMPLETED], true)) {
            return true;
        }

        return $to === self::STATUS_DELIVERED && ! $this->round_trip;
    }

    /** A human label for the current phase, with the destination spelled in. */
    public function phaseLabel(): string
    {
        $dest = $this->destination ?: 'destination';

        return match ($this->status) {
            self::STATUS_DISPATCHED     => 'Awaiting driver',
            self::STATUS_EN_ROUTE       => 'En route to collect',
            self::STATUS_PICKED_UP,
            self::STATUS_IN_TRANSIT,
            self::STATUS_TO_DESTINATION => 'Picked up · to ' . $dest,
            self::STATUS_DELIVERED,
            self::STATUS_AT_DESTINATION => 'Delivered · at ' . $dest,
            self::STATUS_TO_BASE        => 'Returning to base',
            self::STATUS_RETURNED,
            self::STATUS_COMPLETED      => 'Returned / arrived at base',
            self::STATUS_CANCELLED      => 'Cancelled',
            default                     => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LogisticsTaskEvent::class, 'logistics_task_id');
    }
}
