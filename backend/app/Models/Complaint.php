<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Customer Complaint — a first-class entity (NOT a Maintenance ticket). It carries its own
 * customer-support lifecycle on `status` and its own timeline in complaint_events, independent of the
 * workshop. It links OUT to a maintenance ticket (`maintenance_id`) only if/when the triage decision
 * needs real work. See the create_complaints_table migration and [[complaint-entity]].
 */
class Complaint extends Model
{
    protected $table = 'complaints';

    // ── Source: how the complaint reached us ────────────────────────────────────────────────────────
    public const SOURCE_OPS           = 'ops';            // Operations logged a direct customer call/message
    public const SOURCE_DRIVER_RELAY  = 'driver_relayed'; // a driver relayed what the renter told them
    public const SOURCES = [self::SOURCE_OPS, self::SOURCE_DRIVER_RELAY];

    // ── Lifecycle status (authoritative — stamped at every step) ────────────────────────────────────
    public const STATUS_NEW            = 'new';            // logged, Abu Maroof not yet notified/acted
    public const STATUS_NOTIFIED       = 'notified';       // inspector notified, awaiting first contact
    public const STATUS_CONTACTED      = 'contacted';      // ≥1 customer conversation logged
    public const STATUS_IN_MAINTENANCE = 'in_maintenance'; // spawned a maintenance/inspection ticket
    public const STATUS_RESOLVED       = 'resolved';       // handled as customer support, no repair needed
    public const STATUS_CLOSED         = 'closed';         // terminal — done (post-maintenance or otherwise)
    public const STATUSES = [
        self::STATUS_NEW, self::STATUS_NOTIFIED, self::STATUS_CONTACTED,
        self::STATUS_IN_MAINTENANCE, self::STATUS_RESOLVED, self::STATUS_CLOSED,
    ];

    // Stages still needing attention (drive the "open" KPI).
    public const OPEN_STATUSES = [
        self::STATUS_NEW, self::STATUS_NOTIFIED, self::STATUS_CONTACTED, self::STATUS_IN_MAINTENANCE,
    ];

    // ── Triage decision (set once Abu Maroof has spoken to the customer) ────────────────────────────
    public const DECISION_CONTINUE   = 'continue_driving';
    public const DECISION_INSPECTION = 'bring_for_inspection';
    public const DECISION_REPLACE    = 'replace_vehicle';
    public const DECISION_ROADSIDE   = 'roadside_assistance';
    public const DECISIONS = [
        self::DECISION_CONTINUE, self::DECISION_INSPECTION,
        self::DECISION_REPLACE, self::DECISION_ROADSIDE,
    ];

    protected $fillable = [
        'vehicle_id', 'customer_id', 'contract_id', 'source', 'status', 'severity', 'description',
        'decision', 'customer_name', 'customer_phone', 'contract_no', 'maintenance_id', 'legacy_ticket_id',
        'created_by', 'assigned_to', 'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** The maintenance ticket this complaint spawned, if it needed real work. */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'maintenance_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** The complaint's own timeline, oldest → newest. */
    public function events(): HasMany
    {
        return $this->hasMany(ComplaintEvent::class, 'complaint_id')->orderBy('created_at')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
