<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single Inspector's-Pad flag: the Inspector (Abu Maroof) noting an issue keyword and/or a free-text
 * observation against a car, ahead of any maintenance ticket. It stays `pending` until the car is
 * picked up for maintenance, at which point the pick-up intake copies it onto the new ticket's
 * `findings` and marks it `consumed`. See the create migration for the full rationale.
 */
class InspectorPadFlag extends Model
{
    /** Waiting to be picked up. */
    public const STATUS_PENDING  = 'pending';
    /** Folded into a maintenance ticket on pick-up (terminal). */
    public const STATUS_CONSUMED = 'consumed';

    protected $fillable = [
        'vehicle_id',
        'keyword',
        'observation',
        'severity',
        'status',
        'created_by',
        'consumed_by_maintenance_id',
        'consumed_at',
    ];

    protected $casts = [
        'consumed_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'consumed_by_maintenance_id');
    }

    /** Only the flags still waiting to be picked up. */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * The single-line label a mechanic reads on the ticket — keyword and observation joined, or
     * whichever is present. Also the `text` written into the ticket's findings on pick-up.
     */
    public function label(): string
    {
        $keyword     = trim((string) $this->keyword);
        $observation = trim((string) $this->observation);

        if ($keyword !== '' && $observation !== '') {
            return $keyword . ' — ' . $observation;
        }

        return $keyword !== '' ? $keyword : $observation;
    }
}
