<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One deduplicated fault event, paired with the next occurrence of the same fault on the same car.
 *
 * READ MODEL, rebuilt in full by `intelligence:rebuild-recurrence`. Never written by application code.
 *
 * ── READING THIS TABLE CORRECTLY ─────────────────────────────────────────────────────────────────
 * Rows with a null `next_occurred_at` are NOT missing data — they are faults that never came back,
 * and they are the denominator of every recurrence rate. Use {@see scopeRecurred()} for the
 * numerator and the unfiltered table for the denominator; filtering the table down to recurrences
 * and then computing a "rate" over it yields 100% every time.
 *
 * Granularity is SYSTEM level, not fault level: `BRAKES` conflates noise, pads, discs and ABS. The
 * platform may say "brake work came back after N days" and may not say "brake noise came back".
 *
 * @property int         $vehicle_id
 * @property string      $signature
 * @property string      $occurred_at
 * @property int|null    $first_vendor_id
 * @property string|null $next_occurred_at
 * @property int|null    $next_vendor_id
 * @property int|null    $days_to_return
 * @property bool        $returned_30
 * @property string      $label_source     derived|human|both
 * @property int         $source_row_count how many raw signature rows collapsed into this event
 * @property bool        $multi_vendor_day
 */
class FaultRecurrencePair extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vehicle_id', 'signature', 'occurred_at',
        'first_maintenance_id', 'first_vendor_id',
        'next_occurred_at', 'next_maintenance_id', 'next_vendor_id',
        'days_to_return', 'returned_30', 'returned_60', 'returned_90', 'same_vendor',
        'label_source', 'source_row_count', 'multi_vendor_day',
        'chain_position', 'chain_length', 'built_at',
    ];

    protected $casts = [
        'occurred_at'       => 'date:Y-m-d',
        'next_occurred_at'  => 'date:Y-m-d',
        'days_to_return'    => 'integer',
        'returned_30'       => 'boolean',
        'returned_60'       => 'boolean',
        'returned_90'       => 'boolean',
        'same_vendor'       => 'boolean',
        'source_row_count'  => 'integer',
        'multi_vendor_day'  => 'boolean',
        'chain_position'    => 'integer',
        'chain_length'      => 'integer',
        'built_at'          => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function firstVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'first_vendor_id');
    }

    public function nextVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'next_vendor_id');
    }

    /** The numerator: events where the same fault did come back. */
    public function scopeRecurred(Builder $query): Builder
    {
        return $query->whereNotNull('next_occurred_at');
    }

    /** Events attributable to a garage — the only ones that may appear in a garage's score. */
    public function scopeAttributed(Builder $query): Builder
    {
        return $query->whereNotNull('first_vendor_id');
    }
}
