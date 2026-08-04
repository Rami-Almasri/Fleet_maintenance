<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One real workshop visit, collapsed from several maintenance EVENTS.
 *
 * READ MODEL — same status as MaintenanceSignature. Rows are produced in full by
 * `intelligence:rebuild-visits` and may be dropped and regenerated at will. Never write to this
 * table from application code: `maintenances` is the record, this is the shape that makes it
 * countable.
 *
 * @property int|null    $vehicle_id
 * @property int|null    $vendor_id
 * @property string      $started_at
 * @property string|null $ended_at
 * @property int|null    $duration_days
 * @property bool        $is_open
 * @property bool        $is_cancelled
 * @property bool        $has_close_date
 * @property int         $event_row_count
 * @property array       $maintenance_ids
 * @property int         $primary_maintenance_id
 * @property string      $origin_mix
 * @property bool        $multi_vendor_day
 * @property int         $grouping_window_days
 * @property array|null  $signature_set
 */
class RepairVisit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vehicle_id', 'vendor_id', 'started_at', 'ended_at', 'duration_days',
        'is_open', 'is_cancelled', 'has_close_date', 'event_row_count',
        'maintenance_ids', 'primary_maintenance_id', 'origin_mix',
        'multi_vendor_day', 'grouping_window_days', 'signature_set', 'built_at',
    ];

    protected $casts = [
        'started_at'           => 'date:Y-m-d',
        'ended_at'             => 'date:Y-m-d',
        'duration_days'        => 'integer',
        'is_open'              => 'boolean',
        'is_cancelled'         => 'boolean',
        'has_close_date'       => 'boolean',
        'event_row_count'      => 'integer',
        'maintenance_ids'      => 'array',
        'multi_vendor_day'     => 'boolean',
        'grouping_window_days' => 'integer',
        'signature_set'        => 'array',
        'built_at'             => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Visits whose duration can actually be measured.
     *
     * Only ~26% of tickets ever record a return date, and 31 close before they open. Any duration
     * metric MUST start here and MUST report its coverage alongside the number — otherwise "average
     * repair time" silently means "average repair time among the quarter that got closed".
     */
    public function scopeMeasurable(Builder $query): Builder
    {
        return $query->whereNotNull('duration_days')->where('is_cancelled', false);
    }

    /** Visits attributable to a specific garage — excludes the 1,738 tickets with no vendor. */
    public function scopeAttributed(Builder $query): Builder
    {
        return $query->whereNotNull('vendor_id')->whereNotNull('vehicle_id');
    }
}
