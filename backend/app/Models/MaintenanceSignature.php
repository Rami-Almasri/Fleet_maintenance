<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A canonical repair signature attached to a maintenance ticket.
 *
 * READ MODEL. Rows are produced by `intelligence:rebuild-signatures` from the ticket's own text and
 * may be deleted and regenerated at will — never treat this as the place a fault was recorded. The
 * ticket is the record; this is the index that makes the ticket findable by fault.
 *
 * @property int         $maintenance_id
 * @property int|null    $vehicle_id
 * @property string|null $occurred_at
 * @property string      $signature
 * @property string      $source        human|derived|confirmed
 * @property bool        $is_exposure
 * @property array|null  $matched_terms
 * @property string      $classifier_version
 */
class MaintenanceSignature extends Model
{
    use HasFactory;

    public const SOURCE_HUMAN     = 'human';
    public const SOURCE_DERIVED   = 'derived';
    public const SOURCE_CONFIRMED = 'confirmed';

    protected $fillable = [
        'maintenance_id', 'vehicle_id', 'occurred_at', 'signature',
        'source', 'is_exposure', 'matched_terms', 'classifier_version',
    ];

    protected $casts = [
        'occurred_at'   => 'date',
        'is_exposure'   => 'boolean',
        'matched_terms' => 'array',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Workshop-quality scope. BODY and RIM recur because customers damage cars, not because repairs
     * fail — including them ranks every body shop last. Any query that scores a garage, a
     * technician or a repair MUST start here. See Discovery Log (D2, D3).
     */
    public function scopeQualityRelevant($query)
    {
        return $query->where('is_exposure', false);
    }

    /** A label a human stands behind — either originally, or by confirming a derived one. */
    public function scopeHumanBacked($query)
    {
        return $query->whereIn('source', [self::SOURCE_HUMAN, self::SOURCE_CONFIRMED]);
    }
}
