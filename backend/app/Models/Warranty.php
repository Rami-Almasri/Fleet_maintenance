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
    /**
     * @var string Anchored to nothing but the car: the MANUFACTURER or DEALER owes us, on the promise
     * the vehicle arrived with. The only kind that exists before anything has gone wrong, and
     * therefore the only one that can stop us spending money in the first place.
     */
    public const KIND_VEHICLE = 'vehicle';
    public const KINDS = [self::KIND_PART, self::KIND_REPAIR, self::KIND_VEHICLE];

    /**
     * WHO honours it, as a category. Not derivable from `kind`: a part warranty can be honoured by
     * the manufacturer rather than by the shop that sold it, and the phone call is a different one.
     */
    public const PROVIDER_MANUFACTURER = 'manufacturer';
    public const PROVIDER_DEALER       = 'dealer';
    public const PROVIDER_SUPPLIER     = 'supplier';
    public const PROVIDER_GARAGE       = 'garage';
    public const PROVIDER_OTHER        = 'other';
    public const PROVIDER_KINDS = [
        self::PROVIDER_MANUFACTURER, self::PROVIDER_DEALER,
        self::PROVIDER_SUPPLIER, self::PROVIDER_GARAGE, self::PROVIDER_OTHER,
    ];

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
        'provider_kind', 'contact_name', 'contact_phone', 'contact_email',
        'starts_on', 'start_odometer', 'duration_months', 'duration_km',
        'expires_on', 'expires_at_km',
        'status', 'void_reason', 'notes',
        'covered_catalog_ids', 'excluded_catalog_ids', 'coverage_notes',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'starts_on'       => 'date',
        'expires_on'      => 'date',
        'start_odometer'  => 'integer',
        'duration_months' => 'integer',
        'duration_km'     => 'integer',
        'expires_at_km'   => 'integer',
        // The itemised cover, as component_catalog ids. NULL ⇒ "not itemised", which is NOT the same
        // as [] ("itemised, and this list is empty") — see coversCatalog().
        'covered_catalog_ids'  => 'array',
        'excluded_catalog_ids' => 'array',
    ];

    /**
     * How close to the end counts as "expiring soon", on each leg independently.
     *
     * Configurable because the right answer is operational, not technical: a fleet that can get a car
     * to a dealer in a week wants a shorter horizon than one that needs a month's notice. Both legs
     * are checked because either can be the binding one — a car with 8 months left and 900 km of
     * cover is expiring soon, and a date-only threshold would call it healthy.
     */
    public static function expiringSoonDays(): int
    {
        return (int) config('warranty.expiring_soon_days', 60);
    }

    public static function expiringSoonKm(): int
    {
        return (int) config('warranty.expiring_soon_km', 5000);
    }

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

    /**
     * The paperwork — certificate, dealer contract, purchase invoice. Reuses the vehicle document
     * trail rather than a warranty-only store; see the add_warranty_refs migration for why, and why
     * these are never stamped `superseded_at` the way a Mulkiya is.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class, 'warranty_id');
    }

    // ── scopes ───────────────────────────────────────────────────────────────────────────────────

    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $q->where('kind', $kind);
    }

    /** The whole-car promises: what "is this vehicle under warranty?" actually means. */
    public function scopeVehicleCover(Builder $q): Builder
    {
        return $q->where('kind', self::KIND_VEHICLE);
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
     * Does this promise say anything, either way, about a given part type?
     *
     * Returns exactly one of three answers and never guesses the third:
     *
     *   true   the part type is named in `covered_catalog_ids`.
     *   false  it is named in `excluded_catalog_ids` — their document, their exclusion.
     *   null   NEITHER LIST NAMES IT. Not "probably covered", not "probably not". Null is what a
     *          warranty booklet nobody has itemised actually tells you about a wheel bearing, and
     *          it is the answer that sends the question to a human instead of to a default.
     *
     * EXCLUSION WINS over inclusion when a part type somehow appears in both, because a document that
     * contradicts itself is a document we lose the argument on, and the cheap failure is to check
     * with the dealer rather than to buy on an assumption.
     *
     * A NULL list means "not itemised"; an EMPTY list means "itemised, and nothing is on it". The
     * cast keeps them distinct and this method honours the distinction — an empty covered list with a
     * populated exclusion list is a perfectly ordinary "everything except these" warranty.
     */
    public function coversCatalog(?int $catalogId): ?bool
    {
        if ($catalogId === null) {
            return null;   // nobody said which part this is — nothing can be decided about it
        }

        $excluded = $this->excluded_catalog_ids;
        if (is_array($excluded) && in_array((int) $catalogId, array_map('intval', $excluded), true)) {
            return false;
        }

        $covered = $this->covered_catalog_ids;
        if (is_array($covered) && in_array((int) $catalogId, array_map('intval', $covered), true)) {
            return true;
        }

        return null;
    }

    /** Has anybody written down what this warranty does and does not cover? */
    public function isItemised(): bool
    {
        return is_array($this->covered_catalog_ids) || is_array($this->excluded_catalog_ids);
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

        // ── What is LEFT, which is the number anyone acting on this actually needs ──────────────
        //
        // "Expires 2029-01-01" is a fact nobody can plan around; "4 months and 21,500 km left" is.
        // Both legs are reported independently and either may be null: a time-only promise has no
        // distance left to report, and a distance leg judged with no odometer has an unknowable one.
        // Null here means UNKNOWABLE and is never rendered as zero — a car whose remaining cover
        // reads "0 km" because nobody submitted a reading is exactly the car that gets written off
        // as expired while it is still under warranty.
        $daysRemaining = ($state === self::STATE_ACTIVE && $this->expires_on)
            ? max(0, (int) $at->diffInDays(Carbon::parse($this->expires_on)->endOfDay(), false))
            : null;

        $kmRemaining = ($state === self::STATE_ACTIVE && $this->expires_at_km !== null && $odometer !== null)
            ? max(0, (int) $this->expires_at_km - $odometer)
            : null;

        // Close enough to the end, on EITHER leg, that "we'll look at it later" means "we won't".
        // Only ever true while the warranty is still live — an expired warranty is not expiring.
        $expiringSoon = $state === self::STATE_ACTIVE && (
            ($daysRemaining !== null && $daysRemaining <= self::expiringSoonDays())
            || ($kmRemaining !== null && $kmRemaining <= self::expiringSoonKm())
        );

        $left = [];
        if ($daysRemaining !== null) {
            $left[] = $daysRemaining . ' days';
        }
        if ($kmRemaining !== null) {
            $left[] = number_format($kmRemaining) . ' km';
        }

        return [
            'state'            => $state,
            'ended_by'         => $endedBy,
            'reason'           => $reason,
            'months_used'      => $monthsUsed,
            'km_used'          => $kmUsed,
            'distance_unknown' => $distanceUnknown,
            'evidence'         => implode(', ', $bits),

            // The forward-looking half. Null on either leg = unknowable, never zero.
            'days_remaining'     => $daysRemaining,
            'km_remaining'       => $kmRemaining,
            'expiring_soon'      => $expiringSoon,
            'remaining_evidence' => $left ? implode(' / ', $left) : null,
        ];
    }
}
