<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A promise somebody made us, recorded so we can hold them to it.
 *
 * See the create_warranties_table migration for the full rationale. The two things this class is
 * responsible for:
 *
 *  1. DERIVING THE EXPIRY, once, on save. expires_on = starts_on + duration_months and
 *     expires_at_km = start_odometer + duration_km. Both are pure functions of facts on the row, so
 *     storing them is safe and lets the expiring-soon board range-scan an index instead of
 *     computing per row.
 *
 *  2. ANSWERING "IS IT STILL LIVE?" — which is NOT stored, because it cannot be. A warranty bounded
 *     by distance depends on the car's odometer, and the car keeps being driven. A stored
 *     `is_expired` flag would be correct only until the next rental. {@see evaluate()} computes it
 *     on demand, and returns WHY it ended, because "expired" without "on distance, 3 months early"
 *     is not something anyone can act on.
 *
 * THE ONE RULE THAT MATTERS: a warranty ends when EITHER leg runs out, whichever comes first. In a
 * rental fleet the distance leg is usually the binding one — a car doing 6,000 km a month burns a
 * 20,000 km warranty in ten weeks while its 12-month leg still looks healthy.
 */
class Warranty extends Model
{
    use SoftDeletes;

    /** @var string Anchored to a part: the SUPPLIER owes us. */
    public const KIND_PART = 'part';
    /** @var string Anchored to a fault: the GARAGE owes us the repair again. */
    public const KIND_REPAIR = 'repair';
    public const KINDS = [self::KIND_PART, self::KIND_REPAIR];

    /** Live or expired by the clock/odometer — never written as 'expired'; that is computed. */
    public const STATUS_ACTIVE = 'active';
    /** Destroyed before it ran out (unauthorised repair, wrong fluid, counterparty walked away). */
    public const STATUS_VOID = 'void';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_VOID];

    /** Outcomes of {@see evaluate()} — the computed state, never a column. */
    public const STATE_ACTIVE  = 'active';
    public const STATE_EXPIRED = 'expired';
    public const STATE_VOID    = 'void';

    /** Which leg ended it. 'unknown' = distance-bounded but we were given no odometer to judge by. */
    public const BY_TIME     = 'time';
    public const BY_DISTANCE = 'distance';

    protected $fillable = [
        'kind', 'vehicle_id',
        'part_purchase_id', 'vehicle_component_id', 'maintenance_task_id', 'maintenance_id',
        'subject', 'component_catalog_id',
        'provider_vendor_id', 'provider_name', 'reference_no',
        'starts_on', 'start_odometer', 'duration_months', 'duration_km',
        'expires_on', 'expires_at_km',
        'status', 'void_reason', 'notes',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'starts_on'       => 'date',
        'expires_on'      => 'date',
        'start_odometer'  => 'integer',
        'duration_months' => 'integer',
        'duration_km'     => 'integer',
        'expires_at_km'   => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $warranty) {
            // DERIVED, never hand-set. Recomputed on every save so editing the promise (a supplier
            // agreed to 24 months after all) can never leave a stale expiry behind.
            $warranty->expires_on = $warranty->starts_on && $warranty->duration_months
                ? Carbon::parse($warranty->starts_on)->copy()->addMonths((int) $warranty->duration_months)->toDateString()
                : null;

            $warranty->expires_at_km = $warranty->start_odometer !== null && $warranty->duration_km
                ? (int) $warranty->start_odometer + (int) $warranty->duration_km
                : null;
        });
    }

    // ── relationships ────────────────────────────────────────────────────────────────────────────

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function partPurchase(): BelongsTo
    {
        return $this->belongsTo(PartPurchase::class);
    }

    public function vehicleComponent(): BelongsTo
    {
        return $this->belongsTo(VehicleComponent::class);
    }

    /** The fault this repair warranty covers — the anchor that makes a comeback provable. */
    public function maintenanceTask(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
    }

    /** The garage (repair) or the supplier (part) who owes us. */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'provider_vendor_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    // ── scopes ───────────────────────────────────────────────────────────────────────────────────

    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $q->where('kind', $kind);
    }

    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where('vehicle_id', $vehicleId);
    }

    /**
     * Not voided and not past its DATE. Deliberately only the time leg: the distance leg needs the
     * car's current odometer, which is a per-row join this scope cannot do. Use it to NARROW, then
     * ask {@see evaluate()} for the truth — never as the final answer on its own.
     */
    public function scopeNotTimeExpired(Builder $q, ?string $on = null): Builder
    {
        $on = $on ?: now()->toDateString();

        return $q->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $w) => $w->whereNull('expires_on')->orWhereDate('expires_on', '>=', $on));
    }

    /** The expiring-soon board: date leg only, for the same reason as above. */
    public function scopeExpiringWithinDays(Builder $q, int $days): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', now()->toDateString())
            ->whereDate('expires_on', '<=', now()->addDays($days)->toDateString());
    }

    // ── the question everyone actually asks ──────────────────────────────────────────────────────

    /**
     * Is this warranty live, and if not, what ended it?
     *
     * @param  string|null $on       the date to judge at (default today) — a claim judges at the
     *                               date the failure happened, not the date someone opened the page
     * @param  int|null    $odometer the car's reading at that moment. Without it the distance leg
     *                               CANNOT be judged, and this says so rather than assuming the
     *                               warranty holds. Silently treating "unknown" as "fine" is how a
     *                               fleet talks itself into claims it has already lost.
     *
     * @return array{state:string, ended_by:string|null, reason:string|null, months_used:int|null,
     *               km_used:int|null, evidence:string, distance_unknown:bool}
     */
    public function evaluate(?string $on = null, ?int $odometer = null): array
    {
        $at = $on ? Carbon::parse($on)->startOfDay() : now()->startOfDay();

        if ($this->status === self::STATUS_VOID) {
            return $this->verdict(self::STATE_VOID, null, $this->void_reason ?: 'Voided', $at, $odometer);
        }

        // TIME LEG.
        $timeExpired = $this->expires_on && $at->gt(Carbon::parse($this->expires_on)->endOfDay());

        // DISTANCE LEG. Only judgeable with a reading; a distance-bounded warranty with no odometer
        // is reported as unknown, not as live.
        $distanceKnown    = $this->expires_at_km !== null && $odometer !== null;
        $distanceExpired  = $distanceKnown && $odometer > $this->expires_at_km;
        $distanceUnknown  = $this->expires_at_km !== null && $odometer === null;

        if ($timeExpired || $distanceExpired) {
            // Whichever came first is the honest cause. When both have run out we report distance,
            // because in this fleet distance is nearly always the one that got there first and it is
            // the more useful thing to tell a supplier.
            $endedBy = $distanceExpired ? self::BY_DISTANCE : self::BY_TIME;

            return $this->verdict(self::STATE_EXPIRED, $endedBy, null, $at, $odometer, $distanceUnknown);
        }

        return $this->verdict(self::STATE_ACTIVE, null, null, $at, $odometer, $distanceUnknown);
    }

    /** Convenience for the common "is it live right now, given this reading" question. */
    public function isLive(?int $odometer = null): bool
    {
        return $this->evaluate(null, $odometer)['state'] === self::STATE_ACTIVE;
    }

    /**
     * The human sentence a claim freezes: "8 of 12 months, 14,200 of 20,000 km".
     * Built here so the claim, the API and any page all quote the same wording.
     */
    private function verdict(
        string $state,
        ?string $endedBy,
        ?string $reason,
        Carbon $at,
        ?int $odometer,
        bool $distanceUnknown = false,
    ): array {
        $monthsUsed = $this->starts_on
            ? max(0, (int) Carbon::parse($this->starts_on)->startOfDay()->diffInMonths($at))
            : null;

        $kmUsed = ($odometer !== null && $this->start_odometer !== null)
            ? max(0, $odometer - (int) $this->start_odometer)
            : null;

        $bits = [];
        if ($this->duration_months) {
            $bits[] = sprintf('%s of %d months', $monthsUsed ?? '?', $this->duration_months);
        }
        if ($this->duration_km) {
            $bits[] = $kmUsed !== null
                ? sprintf('%s of %s km', number_format($kmUsed), number_format($this->duration_km))
                : sprintf('odometer unknown, cover is %s km', number_format($this->duration_km));
        }
        if (! $bits) {
            $bits[] = 'no time or distance limit recorded';
        }

        return [
            'state'            => $state,
            'ended_by'         => $endedBy,
            'reason'           => $reason,
            'months_used'      => $monthsUsed,
            'km_used'          => $kmUsed,
            'distance_unknown' => $distanceUnknown,
            'evidence'         => implode(', ', $bits),
        ];
    }
}
