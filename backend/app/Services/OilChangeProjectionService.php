<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractMileageReading;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * Oil-change projection for cars that are OUT on rental — Evidence class **P** (prediction).
 *
 * ── The problem ────────────────────────────────────────────────────────────────────────────
 * `Vehicle::serviceStatus()` answers "is this car due RIGHT NOW". That is useless the moment
 * the car drives off the lot: a rental with 500 km of oil life left and 30 days to run will
 * blow through the limit on day 3 and nobody finds out until it comes back.
 *
 * ── The model ──────────────────────────────────────────────────────────────────────────────
 * Everything is done in ABSOLUTE odometer values, which keeps the arithmetic honest across a
 * mid-rental oil change (the threshold simply moves) and needs no running subtraction:
 *
 *     threshold = last_service_odometer + service_interval_km + GRACE
 *     expected  = anchorOdometer + daysSince(anchorDate) × RATE
 *     chase when expected >= threshold
 *
 * RATE is a flat **business assumption** (200 km/day), NOT an attempt to model this customer.
 * The contract may allow more (250 km/day is the usual allowance) — we predict at 200 because
 * it is closer to how the fleet actually gets driven, and GRACE absorbs the difference. Keeping
 * it fixed is what makes this deterministic, explainable to ops, and testable.
 *
 * ── The anchor ─────────────────────────────────────────────────────────────────────────────
 * The anchor starts at the branch handover reading (`contracts.out_milage` on the day the car
 * went out) and is REPLACED by each customer-reported reading. That single moving anchor is
 * what makes the daily evaluation stateless: it never needs to know how many chases have
 * happened, it just reads the newest one. It is also what re-arms the alert — the dedup key
 * carries the anchor, so a new reading mints a new key and the next chase can fire.
 *
 * ── Degraded behaviour ─────────────────────────────────────────────────────────────────────
 * Consumes E6 (odometer chain), currently 🔴. When the anchor is missing or is one of the
 * branch's "no reading" placeholders (null / 0 / 1), we return `no_data` and predict NOTHING.
 * We do not fall back to `vehicles.odometer`: for an open contract that column was itself
 * refreshed FROM this contract's handover reading, so if the handover reading is a placeholder
 * the car's odometer is stale by one whole rental. A visible "can't project this car" beats a
 * confident wrong date — the same discipline serviceStatus() follows by refusing to guess.
 *
 * Produces: nothing persistent. Every value here is recomputed on read.
 */
class OilChangeProjectionService
{
    /** Anchor came from the branch handover (`contracts.out_milage`). */
    public const ANCHOR_HANDOVER = 'handover';

    /** Anchor came from a customer-reported mid-rental reading. */
    public const ANCHOR_READING = 'reading';

    /** Readings at or below this are the branch's "didn't record it" sentinels, never real mileage. */
    private const PLACEHOLDER_MAX = MileageBaselineService::PLACEHOLDER_MAX;

    public function rate(): int
    {
        return max(1, (int) config('maintenance.oil_projection.rate_km_per_day', 200));
    }

    public function grace(): int
    {
        return max(0, (int) config('maintenance.oil_projection.grace_km', 500));
    }

    /**
     * The absolute odometer at which this car's oil MUST be changed — the sheet-driven service
     * point plus the fleet-wide grace we allow a car to run past it.
     *
     * Null when the Oil Change sheet has no anchor for the car (serviceStatus() 'no_data').
     */
    public function threshold(Vehicle $vehicle): ?int
    {
        $baseline = $vehicle->last_service_odometer;
        $interval = $vehicle->service_interval_km;

        if ($baseline === null || $interval === null) {
            return null;
        }

        return (int) $baseline + (int) $interval + $this->grace();
    }

    /**
     * The current projection anchor: the newest customer-reported reading, else the branch
     * handover reading the car left on.
     *
     * @return array{odometer:int, at:Carbon, source:string, reading_id:?int}|null
     */
    public function anchor(Contract $contract): ?array
    {
        $reading = ContractMileageReading::where('contract_id', $contract->id)
            ->orderByDesc('reported_on')
            ->orderByDesc('id')          // same-day readings: the latest entered wins
            ->first();

        if ($reading && $reading->odometer > self::PLACEHOLDER_MAX) {
            return [
                'odometer'   => (int) $reading->odometer,
                'at'         => Carbon::parse($reading->reported_on)->startOfDay(),
                'source'     => self::ANCHOR_READING,
                'reading_id' => (int) $reading->id,
            ];
        }

        $out = $contract->out_milage;
        if ($out === null || (int) $out <= self::PLACEHOLDER_MAX || ! $contract->out_date) {
            return null; // no trustworthy starting point — see the class docblock
        }

        return [
            'odometer'   => (int) $out,
            'at'         => Carbon::parse($contract->out_date)->startOfDay(),
            'source'     => self::ANCHOR_HANDOVER,
            'reading_id' => null,
        ];
    }

    /**
     * Evaluate one open rental as of a given day (defaults to today).
     *
     * @return array{
     *   status:string, expected:?int, threshold:?int, anchor_odometer:?int, anchor_on:?string,
     *   anchor_source:?string, reading_id:?int, days_elapsed:?int, rate:int, grace:int,
     *   km_to_threshold:?int, breach_on:?string, key:?string
     * }
     */
    public function project(Contract $contract, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ? $asOf->copy() : Carbon::now())->startOfDay();
        $rate = $this->rate();

        $base = [
            'status'          => 'no_data',
            'expected'        => null,
            'threshold'       => null,
            'anchor_odometer' => null,
            'anchor_on'       => null,
            'anchor_source'   => null,
            'reading_id'      => null,
            'days_elapsed'    => null,
            'rate'            => $rate,
            'grace'           => $this->grace(),
            'km_to_threshold' => null,
            'breach_on'       => null,
            'key'             => null,
        ];

        $vehicle = $contract->vehicle;
        if (! $vehicle) {
            return $base;
        }

        $threshold = $this->threshold($vehicle);
        $anchor    = $this->anchor($contract);

        if ($threshold === null || $anchor === null) {
            // array_merge, not `+`: the union operator keeps the LEFT side's existing null and the
            // caller would never see the threshold we do know.
            return array_merge($base, ['threshold' => $threshold]);
        }

        // Never let a back-dated reading produce negative elapsed days. Carbon 3 returns a float
        // here, so cast — every km figure downstream must stay a whole number.
        $daysElapsed = (int) max(0, $anchor['at']->diffInDays($asOf, false));
        $expected    = $anchor['odometer'] + ($daysElapsed * $rate);
        $isDue       = $expected >= $threshold;

        // The day the projection crosses the threshold, measured from the anchor. Already past
        // it → the anchor day itself.
        $kmToGo    = $threshold - $anchor['odometer'];
        $breachDay = $kmToGo <= 0 ? 0 : (int) ceil($kmToGo / $rate);

        return [
            'status'          => $isDue ? 'chase_due' : 'ok',
            'expected'        => $expected,
            'threshold'       => $threshold,
            'anchor_odometer' => $anchor['odometer'],
            'anchor_on'       => $anchor['at']->toDateString(),
            'anchor_source'   => $anchor['source'],
            'reading_id'      => $anchor['reading_id'],
            'days_elapsed'    => $daysElapsed,
            'rate'            => $rate,
            'grace'           => $this->grace(),
            'km_to_threshold' => $threshold - $expected,
            'breach_on'       => $anchor['at']->copy()->addDays($breachDay)->toDateString(),
            'key'             => $this->dedupKey($contract, $anchor),
        ];
    }

    /**
     * The alert dedup key. It carries the ANCHOR, so:
     *   • while the anchor stands, the daily scan re-raises the same key and the scanner
     *     suppresses it — ops are asked once, not every day;
     *   • the moment a reading is entered the anchor changes, the key rotates, and the next
     *     chase is free to fire.
     *
     * Rotating the key on re-target is a hard-won rule: a previous oil alert kept its old key
     * through a rename and deadlocked sends fleet-wide.
     */
    public function dedupKey(Contract $contract, array $anchor): string
    {
        $suffix = $anchor['reading_id'] !== null ? 'r' . $anchor['reading_id'] : 'start';

        return 'oil_projection:' . $contract->id . ':' . $suffix;
    }

    /**
     * PRE-HANDOVER decision — should we change the oil before this car goes out for `$days`?
     *
     * Uses the contract's own per-day allowance (what the customer is permitted to drive), not
     * the 200 km/day prediction rate: releasing a car is a worst-case decision, so it is made
     * against the maximum they may legally cover, and being wrong here means an engine runs
     * past its limit with nobody watching.
     *
     *   release          — the whole trip fits inside the remaining oil life + grace.
     *   change_first     — it does not fit, but a fresh change WOULD cover the whole trip.
     *                      The car is in our hands and this is the cheap moment.
     *   release_and_chase— even a fresh change cannot cover the trip, so changing now buys
     *                      nothing. Let it go and let the daily projection chase it mid-rental.
     *
     * @return array{decision:string, projected_km:?int, headroom_km:?int, full_interval_km:?int, allowance:int}
     */
    public function releaseDecision(Vehicle $vehicle, int $days, ?int $allowancePerDay = null): array
    {
        $allowance = max(1, (int) ($allowancePerDay
            ?: config('maintenance.oil_projection.allowance_km_per_day', 250)));
        $days      = max(1, $days);

        $projected = $days * $allowance;
        $status    = $vehicle->serviceStatus();

        $out = [
            'decision'         => 'no_data',
            'projected_km'     => $projected,
            'headroom_km'      => null,
            'full_interval_km' => null,
            'allowance'        => $allowance,
        ];

        if ($status['status'] === 'no_data' || $status['remaining'] === null) {
            return $out;
        }

        $headroom     = (int) $status['remaining'] + $this->grace();
        $fullInterval = (int) $status['interval'] + $this->grace();

        $out['headroom_km']      = $headroom;
        $out['full_interval_km'] = $fullInterval;

        if ($projected <= $headroom) {
            $out['decision'] = 'release';
        } elseif ($projected <= $fullInterval) {
            $out['decision'] = 'change_first';
        } else {
            $out['decision'] = 'release_and_chase';
        }

        return $out;
    }
}
