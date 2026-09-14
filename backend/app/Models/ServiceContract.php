<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A prepaid allowance of scheduled servicing — "5 Lube Service / 5 Yrs", bought with the car.
 *
 * NOT A WARRANTY, and the distinction is the reason this class exists. A warranty answers "if it
 * breaks, who pays?"; this answers "how many free services are left?". One is a yes/no about a
 * fault, the other is a count that goes down. See the create_service_contracts_table migration.
 *
 * ── THE THREE WAYS IT RUNS OUT, AND WHICHEVER COMES FIRST ───────────────────────────────────────
 *
 *   the DATE     "5 Yrs"                  → ends_on
 *   the ODOMETER "or 75K KM"              → ends_at_km, an ABSOLUTE reading on the dial
 *   the COUNT    "5 Lube Service"         → services_total, consumed one at a time
 *
 * A warranty has two legs; this has three, and the third is the one people actually hit. A contract
 * with two years and 30,000 km left is finished the moment its fifth service is used, and a system
 * that only checked the other two would keep telling a supervisor the car is covered.
 *
 * ── NOTHING IS STORED THAT MOVES ───────────────────────────────────────────────────────────────
 *
 * Whether the contract is still live is COMPUTED on every read, exactly as a warranty's is, because
 * the distance leg depends on the car's odometer and the car keeps being driven. `status` is only
 * ever `active` (live or run out — the clock decides) or `ended` (a person terminated it).
 */
class ServiceContract extends Model
{
    use SoftDeletes;

    /** Live or run out by clock/odometer/count — never written as 'expired'; that is computed. */
    public const STATUS_ACTIVE = 'active';
    /** Deliberately terminated: the car was sold, the contract cancelled. */
    public const STATUS_ENDED = 'ended';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ENDED];

    /** Outcomes of {@see evaluate()} — computed, never a column. */
    public const STATE_ACTIVE = 'active';
    public const STATE_EXPIRED = 'expired';
    public const STATE_ENDED = 'ended';

    /** WHICH leg finished it — the thing a person needs to hear first. */
    public const BY_TIME     = 'time';
    public const BY_DISTANCE = 'distance';
    public const BY_SERVICES = 'services';

    /**
     * How near the end counts as "running out", per leg. Constants rather than config: they colour
     * one badge, and a dial nobody turns should not exist. One service left IS nearly out — that is
     * the whole point of a count.
     */
    public const SOON_DAYS     = 60;
    public const SOON_KM       = 5000;
    public const SOON_SERVICES = 1;

    protected $fillable = [
        'vehicle_id', 'coverage_label', 'provider_name', 'contact_phone',
        'services_total', 'services_used', 'interval_km',
        'starts_on', 'ends_on', 'ends_at_km',
        'last_service_odometer', 'last_service_on',
        'status', 'notes',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'starts_on'             => 'date',
        'ends_on'               => 'date',
        'last_service_on'       => 'date',
        'ends_at_km'            => 'integer',
        'last_service_odometer' => 'integer',
        'services_total'        => 'integer',
        'services_used'         => 'integer',
        'interval_km'           => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where('vehicle_id', $vehicleId);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    /** How many services are still paid for. Null when the contract is capped by period, not count. */
    public function servicesRemaining(): ?int
    {
        if ($this->services_total === null) {
            return null;
        }

        return max(0, (int) $this->services_total - (int) $this->services_used);
    }

    /**
     * The odometer the next covered service falls due at — "last change + the interval".
     *
     * Null unless both facts are known, rather than guessed from the car's own service schedule:
     * the fleet may service at 8,000 km while the contract only pays every 10,000, and answering
     * with the wrong one sends somebody to the dealer a service early.
     */
    public function nextServiceDueAtKm(): ?int
    {
        if ($this->last_service_odometer === null || ! $this->interval_km) {
            return null;
        }

        return (int) $this->last_service_odometer + (int) $this->interval_km;
    }

    /**
     * Is this contract still live, and if not, what finished it?
     *
     * @param  int|null $odometer the car's reading now. Without it the distance leg cannot be judged,
     *                            and this says so (`distance_unknown`) rather than assuming cover —
     *                            the same refusal the warranty makes one table over.
     *
     * @return array{state:string, ended_by:?string, days_remaining:?int, km_remaining:?int,
     *               km_over:?int, services_remaining:?int, running_out:bool, distance_unknown:bool,
     *               next_service_due_at_km:?int}
     */
    public function evaluate(?string $on = null, ?int $odometer = null): array
    {
        $at = $on ? Carbon::parse($on)->startOfDay() : now()->startOfDay();

        $servicesLeft = $this->servicesRemaining();
        $distanceUnknown = $this->ends_at_km !== null && $odometer === null;

        if ($this->status === self::STATUS_ENDED) {
            return $this->verdict(self::STATE_ENDED, null, null, null, null, $servicesLeft, false, $distanceUnknown);
        }

        // ── The three legs, judged independently ────────────────────────────────────────────────
        $timeExpired     = $this->ends_on && $at->gt(Carbon::parse($this->ends_on)->endOfDay());
        $distanceExpired = $this->ends_at_km !== null && $odometer !== null && $odometer > (int) $this->ends_at_km;
        $servicesUsedUp  = $servicesLeft !== null && $servicesLeft <= 0;

        if ($timeExpired || $distanceExpired || $servicesUsedUp) {
            /**
             * Which one to report when several have finished. SERVICES first, because "you have used
             * all five" is the most concrete and the least arguable; then DISTANCE, which in this
             * fleet is nearly always the one that arrived first; then time.
             */
            $endedBy = $servicesUsedUp ? self::BY_SERVICES
                : ($distanceExpired ? self::BY_DISTANCE : self::BY_TIME);

            // HOW FAR PAST — the report's own negative, as a positive magnitude. "3,412 km over
            // limit" is actionable; "expired" on its own is not.
            $kmOver = ($this->ends_at_km !== null && $odometer !== null && $odometer > (int) $this->ends_at_km)
                ? $odometer - (int) $this->ends_at_km
                : null;

            return $this->verdict(self::STATE_EXPIRED, $endedBy, null, null, $kmOver, $servicesLeft, false, $distanceUnknown);
        }

        $daysRemaining = $this->ends_on
            ? max(0, (int) $at->diffInDays(Carbon::parse($this->ends_on)->endOfDay(), false))
            : null;

        $kmRemaining = ($this->ends_at_km !== null && $odometer !== null)
            ? max(0, (int) $this->ends_at_km - $odometer)
            : null;

        // Running out on ANY leg. One service left counts — a count of one is nearly nothing.
        $runningOut = ($daysRemaining !== null && $daysRemaining <= self::SOON_DAYS)
            || ($kmRemaining !== null && $kmRemaining <= self::SOON_KM)
            || ($servicesLeft !== null && $servicesLeft <= self::SOON_SERVICES);

        return $this->verdict(self::STATE_ACTIVE, null, $daysRemaining, $kmRemaining, null, $servicesLeft, $runningOut, $distanceUnknown);
    }

    /** True when the contract is live right now, given a reading. */
    public function isLive(?int $odometer = null): bool
    {
        return $this->evaluate(null, $odometer)['state'] === self::STATE_ACTIVE;
    }

    private function verdict(
        string $state,
        ?string $endedBy,
        ?int $daysRemaining,
        ?int $kmRemaining,
        ?int $kmOver,
        ?int $servicesLeft,
        bool $runningOut,
        bool $distanceUnknown,
    ): array {
        return [
            'state'                  => $state,
            'ended_by'               => $endedBy,
            'days_remaining'         => $daysRemaining,
            'km_remaining'           => $kmRemaining,
            // A positive magnitude with its own key, never a negative "remaining" — so nothing can
            // render "-3,412 km remaining" or add the two together.
            'km_over'                => $kmOver,
            'services_remaining'     => $servicesLeft,
            'running_out'            => $runningOut,
            'distance_unknown'       => $distanceUnknown,
            'next_service_due_at_km' => $this->nextServiceDueAtKm(),
        ];
    }
}
