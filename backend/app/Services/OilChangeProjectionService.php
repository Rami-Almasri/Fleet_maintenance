<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractMileageReading;
use App\Models\ContractOilDecision;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\OilRecallTask;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
 *     oil_limit = last_service_odometer + service_interval_km
 *     threshold = oil_limit + TOLERANCE          (a.k.a. "allowed max" — how far it may run)
 *     expected  = anchorOdometer + daysSince(anchorDate) × RATE
 *     chase when expected >= threshold
 *
 * ── The decision (what the chase is FOR) ───────────────────────────────────────────────────
 * Reaching the oil limit is not, by itself, an emergency. The operational question is narrower:
 *
 *     WILL THIS CAR STILL BE INSIDE THE TOLERANCE WHEN THE CUSTOMER BRINGS IT BACK?
 *
 * which is answered the moment a real reading lands, by projecting forward over the days the
 * rental still has to run:
 *
 *     expected_return = expected_now + remaining_rental_days × RATE
 *
 *     expected_return <= oil_limit   → within_tolerance          nothing to do
 *     expected_return <= allowed_max → service_required_on_return let it finish, book the change
 *     expected_return >  allowed_max → decision_required          a person picks recall vs defer
 *
 * So a car 100 km past its limit with two days left to run is NOT recalled — it lands on 8,000 km
 * against an 8,000 km allowance and is simply serviced on return. Only a car that cannot finish
 * inside the tolerance is put in front of a human, and that human has exactly two answers:
 * recall it now, or accept the overrun and service it at close (ContractOilDecision).
 *
 * RATE is a flat **business assumption** (200 km/day), NOT an attempt to model this customer.
 * The contract may allow more (250 km/day is the usual allowance) — we predict at 200 because
 * it is closer to how the fleet actually gets driven, and GRACE absorbs the difference. Keeping
 * it fixed is what makes this deterministic, explainable to ops, and testable.
 *
 * ── The anchor ─────────────────────────────────────────────────────────────────────────────
 * The anchor starts at the branch handover reading (`contracts.out_milage` on the day the car
 * went out) and is REPLACED by any NEWER dated observation — a customer-reported reading, or the
 * "Oil Change" sheet's MILAGE column dated by its LAST EDIT stamp. That single moving anchor is
 * what makes the daily evaluation stateless: it never needs to know how many chases have
 * happened, it just reads the newest one. It is also what re-arms the alert — the dedup key
 * carries the anchor, so a new reading mints a new key and the next chase can fire.
 *
 * A sheet reading moves the NUMBERS but never triggers the RECALL question on its own
 * (`decision_ready` still demands a customer reading). LAST EDIT stamps the row, not the mileage
 * cell, so editing a note re-dates the row without anybody having looked at the dashboard —
 * fine for showing a fresher estimate, not good enough to interrupt a paying customer's rental.
 *
 * ── Degraded behaviour ─────────────────────────────────────────────────────────────────────
 * Consumes E6 (odometer chain), currently 🔴. When no dated anchor exists, or every candidate is
 * one of the branch's "no reading" placeholders (null / 0 / 1), we return `no_data` and predict
 * NOTHING. There is still no blanket fallback to `vehicles.odometer`: for an open contract that
 * column is usually just this contract's own handover reading echoed back, so trusting it would
 * dress a placeholder up as a fact. Only a reading that names an independent observer AND
 * carries the date it was taken (today: the sheet) is admitted. A visible "can't project this
 * car" beats a confident wrong date — the discipline serviceStatus() follows by refusing to guess.
 *
 * Produces: nothing persistent. Every value here is recomputed on read.
 */
class OilChangeProjectionService
{
    /** Anchor came from the branch handover (`contracts.out_milage`). */
    public const ANCHOR_HANDOVER = 'handover';

    /** Anchor came from a customer-reported mid-rental reading. */
    public const ANCHOR_READING = 'reading';

    /**
     * Anchor came from the "Oil Change" sheet's MILAGE column — a workshop employee read the
     * dashboard and typed it in, dated by the sheet's own LAST EDIT stamp.
     */
    public const ANCHOR_SHEET = 'sheet';

    // ── The decision axis (`oil_status`) ─────────────────────────────────────────────────────
    // Deliberately SEPARATE from `status`, which stays the chase axis ("do we need to phone the
    // customer"). One asks whether we have a trustworthy number; this one asks what to do with it.

    /** The rental will finish before the car even reaches its oil point. Nothing to do. */
    public const OIL_WITHIN_TOLERANCE = 'within_tolerance';

    /** It will pass the oil point but finish inside the tolerance — let it run, service it at close. */
    public const OIL_SERVICE_ON_RETURN = 'service_required_on_return';

    /** It cannot finish inside the tolerance. A person must choose: recall now, or accept + defer. */
    public const OIL_DECISION_REQUIRED = 'decision_required';

    /** Somebody chose to bring the car back early. */
    public const OIL_RECALL_REQUIRED = 'recall_required';

    /** No oil anchor and/or no mileage anchor — we refuse to guess. */
    public const OIL_NO_DATA = 'no_data';

    /**
     * How old a customer reading may be and still be treated as a FACT worth deciding on.
     *
     * This is the difference between "this car will bust its allowance" (arithmetic, always true of
     * any long rental) and "somebody should decide about this car today" (a call worth making).
     * Recalling a paying customer's car is not a decision anyone should take against a projection,
     * and a reading that is days old has decayed back into one — so past this, the car is chased for
     * a new number instead. Lives here, not on the board or the scanner, so those two can never
     * disagree about which cars are actually asking for a human.
     */
    public const DECISION_FRESH_DAYS = 1;

    /**
     * How close to its oil point a car must be before "recall or defer?" is a question worth asking.
     *
     * The decision axis is pure arithmetic: any long rental will exceed its allowance eventually, so
     * `expected_return > allowed_max` is true of a car with a WHOLE INTERVAL still in front of it.
     * Found by walking the flow end to end: the moment an oil change was recorded — new limit, fresh
     * anchor, 7,000 km of life ahead — the board immediately asked whether to recall the car again.
     * Arithmetically right, operationally absurd, and precisely the noise this board exists to remove.
     *
     * So a decision only becomes today's business once the car is within a week's driving of its oil
     * point. Before that the fact is still published (`oil_status` stays `decision_required` and the
     * card says the rental cannot finish inside the allowance) — it simply is not put in front of a
     * person yet, because nothing they decide today would be acted on for a month.
     */
    public const DECISION_WINDOW_DAYS = 7;

    // ── The OPERATIONAL LANE ─────────────────────────────────────────────────────────────────
    // One primary action per car, derived HERE so the board, the tab counts and any future
    // consumer can never disagree. The decision/chase axes above stay authoritative for WHAT is
    // true of the car; the lane says what a person DOES about it today, by strict priority:
    //
    //   1. A returned car never reaches this board at all (`Contract::currentlyOpen()`), and the
    //      hourly oil:settle-returns sweep raises its ticket — so "actually returned" is handled
    //      upstream of the lane, never mislabelled as a call.
    //   2. Due back TODAY (or overdue) and still out ⇒ SERVICE_ON_RETURN. Phoning a customer to
    //      ask about oil on the day they are already returning the car is operational nonsense —
    //      the action is: when it arrives, read the real odometer, check the oil, service if due.
    //   3. An intervention already agreed or answerable now ⇒ ACTION_REQUIRED (open recall, or a
    //      fresh reading proving the allowance cannot survive the rental).
    //   4. A stale number on a car that still has days to run ⇒ CALL_CUSTOMER.
    //   5. Everything else ⇒ SAFE (within tolerance, or booked for service at close).

    public const LANE_ACTION_REQUIRED   = 'action_required';
    public const LANE_SERVICE_ON_RETURN = 'service_on_return';
    public const LANE_CALL_CUSTOMER     = 'call_customer';
    public const LANE_SAFE              = 'safe';
    public const LANE_NO_DATA           = 'no_data';

    /** @param array<string,mixed> $p a payload built by project() */
    public function laneFor(array $p): string
    {
        if (($p['oil_status'] ?? self::OIL_NO_DATA) === self::OIL_NO_DATA) {
            return self::LANE_NO_DATA;
        }

        // FIRST: is there an oil question at all? A car that finishes the rental before it even
        // reaches its oil point has nothing to ask, answer or catch on arrival — whatever its
        // return date. Putting a car with a full interval left in front of a person because it
        // happens to come back today is exactly the noise this board exists to remove.
        $concern = in_array($p['oil_status'], [
            self::OIL_DECISION_REQUIRED,
            self::OIL_RECALL_REQUIRED,
            self::OIL_SERVICE_ON_RETURN,
        ], true) || ($p['status'] ?? null) === 'chase_due';

        // …but a DECISION about a car that is nowhere near its oil point is not today's business.
        // A car whose oil was changed this morning is arithmetically certain to bust its allowance
        // again before a 60-day rental ends — putting it back in front of a person the same day is
        // how a queue teaches people to ignore it. The fact stays published; the card goes quiet
        // until the car is within a week's driving of the point itself.
        if ($p['oil_status'] === self::OIL_DECISION_REQUIRED
            && ! ($p['decision_due_soon'] ?? true)
            && ($p['status'] ?? null) !== 'chase_due') {
            return self::LANE_SAFE;
        }

        if (! $concern) {
            return self::LANE_SAFE;
        }

        // Only then does the arrival rule apply: a car with a real oil question that is due back
        // today (or overdue) is caught on arrival, never phoned.
        if (($p['return_date_known'] ?? false) && ($p['remaining_days'] ?? null) === 0) {
            return self::LANE_SERVICE_ON_RETURN;
        }
        if ($p['oil_status'] === self::OIL_RECALL_REQUIRED || ($p['decision_ready'] ?? false)) {
            return self::LANE_ACTION_REQUIRED;
        }
        if ($p['oil_status'] === self::OIL_DECISION_REQUIRED || ($p['status'] ?? null) === 'chase_due') {
            return self::LANE_CALL_CUSTOMER;
        }
        return self::LANE_SAFE;
    }

    // ── Oil-service reading vs the projection anchor ─────────────────────────────────────────
    // The sheet's "LAST CHANGE" km is a REAL dashboard observation (a workshop employee read the
    // dash), but the sheet carries NO reliable date for it — so it can never become a projection
    // anchor (no date ⇒ no days-elapsed). What it CAN do is contradict the anchor, and that
    // contradiction is classified rather than hidden:

    /** Oil service recorded ahead of the anchor, at a km the car could plausibly have reached. */
    public const SERVICE_MID_RENTAL = 'mid_rental_service';

    /** Oil service recorded ahead of anything the car could have reached — verify the sheet row. */
    public const SERVICE_SUSPICIOUS = 'suspicious';

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
     * The absolute odometer at which this car's oil is DUE — the sheet-driven service point
     * itself, with no tolerance folded in. Crossing it is normal; the tolerance above it is what
     * decides whether crossing it matters yet.
     *
     * Null when the Oil Change sheet has no anchor for the car (serviceStatus() 'no_data').
     */
    public function oilLimit(Vehicle $vehicle): ?int
    {
        $baseline = $vehicle->last_service_odometer;
        $interval = $vehicle->service_interval_km;

        if ($baseline === null || $interval === null) {
            return null;
        }

        return (int) $baseline + (int) $interval;
    }

    /**
     * The absolute odometer this car may NOT run past — the oil limit plus the fleet-wide
     * tolerance. This is the number every decision is made against ("allowed max"): 7,500 km of
     * oil limit with a 500 km tolerance means the car is allowed to reach 8,000 km.
     */
    public function threshold(Vehicle $vehicle): ?int
    {
        $limit = $this->oilLimit($vehicle);

        return $limit === null ? null : $limit + $this->grace();
    }

    /**
     * Classify the car's last oil-service reading AGAINST the projection anchor.
     *
     * Returns null when there is nothing to say: no service baseline, or the service km sits at or
     * below the anchor (the normal case — the oil was changed before the car went out). When the
     * service km is AHEAD of the anchor it is either a genuine mid-rental service or a bad sheet
     * row, and the split is a plausibility test: could this car, driving at the prediction rate
     * since its anchor date, actually have reached that odometer? Within today's projection plus
     * the configured margin ⇒ `mid_rental_service` (informational). Beyond it ⇒ `suspicious` —
     * the record physically contradicts the mileage evidence and a human must check the sheet
     * before trusting anything derived from it (including the oil limit itself).
     *
     * Deliberately NEVER promotes the service km to an anchor: the sheet carries no reliable
     * service date, and an undated reading cannot be projected forward.
     *
     * @return array{odometer:int, ahead_of_anchor_km:int, state:string}|null
     */
    public function serviceReadingState(Vehicle $vehicle, array $anchor, int $expected): ?array
    {
        $serviceKm = $vehicle->last_service_odometer;
        if ($serviceKm === null || (int) $serviceKm <= self::PLACEHOLDER_MAX) {
            return null;
        }

        $ahead = (int) $serviceKm - (int) $anchor['odometer'];
        if ($ahead <= 0) {
            return null;
        }

        $margin = max(0, (int) config('maintenance.oil_projection.service_plausibility_margin_km', 500));

        return [
            'odometer'           => (int) $serviceKm,
            'ahead_of_anchor_km' => $ahead,
            'state'              => (int) $serviceKm <= $expected + $margin
                ? self::SERVICE_MID_RENTAL
                : self::SERVICE_SUSPICIOUS,
        ];
    }

    /**
     * The day this rental is contracted to come back: pickup + the contracted duration.
     *
     * `contracts.days` is the duration OfficeManager holds for an open rental (an extension simply
     * updates it, which is why nothing here needs rescheduling). Null when the duration is missing
     * — the caller must then say so rather than quietly assuming the car is back today.
     */
    public function returnDueOn(Contract $contract): ?Carbon
    {
        $days = (int) ($contract->days ?? 0);

        if ($days <= 0 || ! $contract->out_date) {
            return null;
        }

        return Carbon::parse($contract->out_date)->startOfDay()->addDays($days);
    }

    /**
     * The current projection anchor: the NEWEST dated reading we hold for this car.
     *
     * Three sources can supply one, and they are ranked by the date the reading was TAKEN, not by
     * which table it lives in — the whole model is "anchor + days elapsed", so the freshest
     * observation is always the best starting point whoever wrote it down:
     *
     *   1. a customer-reported mid-rental reading  (ContractMileageReading.reported_on)
     *   2. the "Oil Change" sheet's MILAGE column  (vehicles.odometer_reading_on ← LAST EDIT)
     *   3. the branch handover reading             (contracts.out_date / out_milage)
     *
     * WHY THE SHEET IS ALLOWED IN HERE. This method used to refuse `vehicles.odometer` outright,
     * on the reasoning that for an open contract that column had itself been refreshed FROM this
     * contract's handover reading — so consulting it just echoed the handover back, and a
     * placeholder handover made it stale by a whole rental. That reasoning was correct for the
     * writers that existed then (the OM car card and the handover chain), and it is exactly why
     * this is still not a blanket `vehicles.odometer` fallback.
     *
     * The sheet is different in kind. A workshop employee physically read the dashboard and typed
     * the number in, mid-rental, with no reference to any handover — it is an independent
     * observation, and the sheet stamps LAST EDIT so we know when it was made. So it is admitted
     * on exactly the same terms as a customer reading: only when it is DATED, only when it beats
     * the handover on date, and never as a bare number.
     *
     * Guarded narrowly on purpose:
     *   - `odometer_source` must be 'sheet'. A car whose odometer was last written by the OM sync
     *     or by the handover chain is NOT admitted — that would resurrect the circular echo.
     *   - `odometer_reading_on` must exist. An undated reading cannot be projected forward, and
     *     falling back to the import timestamp would date a weeks-old figure as today.
     *   - it must not predate the handover. A sheet row edited before the car went out describes
     *     the previous rental; the handover is the newer fact and keeps the anchor.
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
        $handover = ($out !== null && (int) $out > self::PLACEHOLDER_MAX && $contract->out_date)
            ? [
                'odometer'   => (int) $out,
                'at'         => Carbon::parse($contract->out_date)->startOfDay(),
                'source'     => self::ANCHOR_HANDOVER,
                'reading_id' => null,
            ]
            : null;

        $sheet = $this->sheetAnchor($contract->vehicle);

        // Newest dated observation wins. With both present and same-dated, the handover stays —
        // it is the reading taken at the moment custody changed hands, which is the more precise
        // fact about where this rental started.
        if ($sheet && (! $handover || $sheet['at']->gt($handover['at']))) {
            return $sheet;
        }

        return $handover;   // null here = no trustworthy starting point, see the class docblock
    }

    /**
     * The "Oil Change" sheet's MILAGE as a dated anchor candidate, or null when it cannot serve as
     * one. See anchor() for why this is deliberately not a general `vehicles.odometer` fallback.
     *
     * @return array{odometer:int, at:Carbon, source:string, reading_id:?int}|null
     */
    private function sheetAnchor(?Vehicle $vehicle): ?array
    {
        if (! $vehicle) {
            return null;
        }

        // A CONSTRAINED eager load ("vehicle:id,plate_no,…") that omits these columns does not
        // error — Eloquent hands back null for a column it never selected — so this method would
        // quietly answer "no sheet reading" for every car and the whole board would fall back to
        // handover anchors months out of date. That is precisely the bug that shipped: the board
        // read stale while a full-model check on the same data read correctly. Rather than trust
        // every future caller to remember two column names, detect the partial load and fill it in.
        $attrs = $vehicle->getAttributes();
        if (! array_key_exists('odometer_source', $attrs) || ! array_key_exists('odometer_reading_on', $attrs)) {
            $missing = Vehicle::whereKey($vehicle->getKey())
                ->first(['odometer', 'odometer_source', 'odometer_reading_on']);
            if (! $missing) {
                return null;
            }
            $vehicle = $missing;
        }

        if ($vehicle->odometer_source !== self::ANCHOR_SHEET
            || $vehicle->odometer_reading_on === null
            || (int) ($vehicle->odometer ?? 0) <= self::PLACEHOLDER_MAX) {
            return null;
        }

        return [
            'odometer'   => (int) $vehicle->odometer,
            'at'         => $vehicle->odometer_reading_on->copy()->startOfDay(),
            'source'     => self::ANCHOR_SHEET,
            'reading_id' => null,
        ];
    }

    /**
     * Evaluate one open rental as of a given day (defaults to today).
     *
     * Two independent verdicts come back:
     *   `status`     — the CHASE axis: ok | chase_due | no_data. "Do we need a real number?"
     *   `oil_status` — the DECISION axis (see the class docblock). "Given the number we have, what
     *                  happens to this car?" This is what the board acts on.
     *
     * @return array{
     *   status:string, expected:?int, threshold:?int, anchor_odometer:?int, anchor_on:?string,
     *   anchor_source:?string, reading_id:?int, days_elapsed:?int, rate:int, grace:int,
     *   km_to_threshold:?int, breach_on:?string, key:?string, oil_status:string, oil_limit:?int,
     *   tolerance:int, allowed_max:?int, return_due_on:?string, remaining_days:?int,
     *   return_date_known:bool, expected_return:?int, over_tolerance_km:?int, decision:?array,
     *   handover_odometer:?int, handover_on:?string, oil_service_odometer:?int,
     *   oil_service_ahead_km:?int, oil_service_state:?string
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
            // ── evidence trail ── the handover reading is published SEPARATELY from the anchor so
            // no consumer ever has to guess which one it is looking at, and the oil-service reading
            // is classified against the anchor instead of being silently trusted or hidden.
            'handover_odometer'  => null,
            'handover_on'        => null,
            'oil_service_odometer' => null,
            'oil_service_ahead_km' => null,
            'oil_service_state'    => null,
            // ── decision axis ──
            'oil_status'        => self::OIL_NO_DATA,
            'oil_limit'         => null,
            'tolerance'         => $this->grace(),
            'allowed_max'       => null,
            'return_due_on'     => null,
            'remaining_days'    => null,
            'return_date_known' => false,
            'expected_return'   => null,
            'over_tolerance_km' => null,
            'decision'          => null,
            'decision_ready'    => false,
            'decision_due_soon' => false,
            'decision_window_km' => null,
            'lane'              => self::LANE_NO_DATA,
        ];

        $vehicle = $contract->vehicle;
        if (! $vehicle) {
            return $base;
        }

        // The handover reading, published under its own name whatever the anchor turns out to be.
        if ($contract->out_milage !== null && (int) $contract->out_milage > self::PLACEHOLDER_MAX && $contract->out_date) {
            $base['handover_odometer'] = (int) $contract->out_milage;
            $base['handover_on']       = Carbon::parse($contract->out_date)->toDateString();
        }
        if ($vehicle->last_service_odometer !== null && (int) $vehicle->last_service_odometer > self::PLACEHOLDER_MAX) {
            $base['oil_service_odometer'] = (int) $vehicle->last_service_odometer;
        }

        $oilLimit  = $this->oilLimit($vehicle);
        $threshold = $this->threshold($vehicle);
        $anchor    = $this->anchor($contract);

        if ($threshold === null || $anchor === null) {
            // array_merge, not `+`: the union operator keeps the LEFT side's existing null and the
            // caller would never see the limits we do know.
            return array_merge($base, [
                'threshold'   => $threshold,
                'oil_limit'   => $oilLimit,
                'allowed_max' => $threshold,
            ]);
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

        // ── The return window ────────────────────────────────────────────────────────────────
        // How much further this car can still be driven under the contract we hold. An overdue
        // rental floors at 0 rather than going negative: the customer is past due, so the only
        // honest statement is "it could come back at any moment", not "it drove backwards".
        $dueOn     = $this->returnDueOn($contract);
        $remaining = $dueOn ? (int) max(0, $asOf->diffInDays($dueOn, false)) : null;

        // A contract with no duration cannot be projected to its end. We fall back to "as if it
        // came back today", which is OPTIMISTIC — so `return_date_known` is published alongside it
        // and the board says so out loud. Guessing a duration would be worse: it would silently
        // manufacture a recall (or silently suppress one) from a number nobody entered.
        $expectedReturn = $expected + (($remaining ?? 0) * $rate);

        $decision  = $this->latestDecision($contract);
        $oilStatus = $this->classify($expectedReturn, $oilLimit, $threshold, $decision, $anchor);
        $service   = $this->serviceReadingState($vehicle, $anchor, $expected);

        // How near the car is to the oil point itself — the difference between "this rental cannot
        // finish inside its allowance" (arithmetic, often about a date a month away) and "somebody
        // should decide about this car today".
        $window  = $rate * max(0, (int) config('maintenance.oil_projection.decision_window_days', self::DECISION_WINDOW_DAYS));
        $dueSoon = $oilLimit === null || $expected >= ($oilLimit - $window);

        $out = array_merge($base, [
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
            // ── decision axis ──
            'oil_status'        => $oilStatus,
            'oil_limit'         => $oilLimit,
            'tolerance'         => $this->grace(),
            'allowed_max'       => $threshold,
            'return_due_on'     => $dueOn?->toDateString(),
            'remaining_days'    => $remaining,
            'return_date_known' => $dueOn !== null,
            'expected_return'   => $expectedReturn,
            // Positive = how far past the allowance it lands; negative = headroom still in hand.
            'over_tolerance_km' => $expectedReturn - $threshold,
            'decision'          => $decision ? $this->decisionPayload($decision, $anchor) : null,
            // Is this a call anyone can actually take right now? Any long rental is arithmetically
            // certain to bust its allowance — a 30-day hire cannot fit inside an oil interval — so
            // `decision_required` alone would put most of the fleet in front of a human every day.
            // The question only becomes answerable once we hold a FRESH REAL reading; until then the
            // car belongs in the chase queue, not the decision queue.
            'decision_ready'    => $oilStatus === self::OIL_DECISION_REQUIRED
                                && $anchor['source'] === self::ANCHOR_READING
                                && $daysElapsed <= self::DECISION_FRESH_DAYS
                                && $dueSoon,
            // Is the car actually NEAR the oil point it will overshoot? Published in its own right
            // so the board can say "not yet — we'll ask when it gets close" instead of going quiet
            // for reasons nobody can see.
            'decision_due_soon' => $dueSoon,
            'decision_window_km' => $window,
            // ── evidence trail ──
            'oil_service_ahead_km' => $service['ahead_of_anchor_km'] ?? null,
            'oil_service_state'    => $service['state'] ?? null,
        ]);

        // The lane is derived LAST, from the finished payload, so it can never disagree with the
        // axes it summarises.
        $out['lane'] = $this->laneFor($out);

        return $out;
    }

    /**
     * Turn the return-window arithmetic into one of the four operating states.
     *
     * A recorded decision overrides the arithmetic, with one exception that matters: a `defer` is
     * bound to the anchor it was taken on. If a LATER customer reading shows the car is being
     * driven harder than the number that decision was based on — hard enough to bust the tolerance
     * again — the defer is treated as superseded and the call comes back to a human. That mirrors
     * the re-arm doctrine the whole feature runs on: a new anchor re-opens the question. A `recall`
     * is terminal; the car is already coming back, so there is nothing left to re-ask.
     */
    private function classify(
        int $expectedReturn,
        ?int $oilLimit,
        int $allowedMax,
        ?ContractOilDecision $decision,
        array $anchor,
    ): string {
        $bare = match (true) {
            $expectedReturn > $allowedMax                        => self::OIL_DECISION_REQUIRED,
            $oilLimit !== null && $expectedReturn > $oilLimit    => self::OIL_SERVICE_ON_RETURN,
            default                                              => self::OIL_WITHIN_TOLERANCE,
        };

        if (! $decision || ! $decision->isOpen()) {
            return $bare;
        }

        if ($decision->decision === ContractOilDecision::DECISION_RECALL) {
            return self::OIL_RECALL_REQUIRED;
        }

        // Deferred. Stands unless a newer reading has re-broken the tolerance.
        return ($bare === self::OIL_DECISION_REQUIRED && $this->supersedes($anchor, $decision))
            ? self::OIL_DECISION_REQUIRED
            : self::OIL_SERVICE_ON_RETURN;
    }

    /** True when the projection is now anchored on a READING taken after the decision was made. */
    private function supersedes(array $anchor, ContractOilDecision $decision): bool
    {
        return $anchor['reading_id'] !== null
            && (int) $anchor['reading_id'] !== (int) $decision->anchor_reading_id;
    }

    /** The decision as the board renders it — what was chosen, by whom, and against which figures. */
    private function decisionPayload(ContractOilDecision $decision, array $anchor): array
    {
        return [
            'id'                       => $decision->id,
            'decision'                 => $decision->decision,
            'note'                     => $decision->note,
            'decided_by'               => $decision->decided_by_name,
            'decided_at'               => optional($decision->created_at)->toDateTimeString(),
            'is_auto'                  => (bool) $decision->is_auto,
            'settled_at'               => optional($decision->settled_at)->toDateTimeString(),
            'settled_ticket_id'        => $decision->settled_ticket_id,
            // The arithmetic it was taken against — so a week-old call can still be read honestly
            // beside today's numbers instead of appearing to contradict them.
            'expected_return_odometer' => $decision->expected_return_odometer,
            'allowed_max'              => $decision->allowed_max,
            'remaining_days'           => $decision->remaining_days,
            'superseded'               => $decision->isOpen()
                && $decision->decision === ContractOilDecision::DECISION_DEFER
                && $this->supersedes($anchor, $decision),
            // The recall relay — null unless this decision actually was a recall. The board reads
            // its next action from here rather than re-deriving one from the raw figures.
            'recall'                   => $this->recallState($decision),
        ];
    }

    /** The standing decision for this rental, if any — newest wins. */
    public function latestDecision(Contract $contract): ?ContractOilDecision
    {
        return ContractOilDecision::where('contract_id', $contract->id)
            ->orderByDesc('id')
            ->first();
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

    // ── The mid-rental decision ──────────────────────────────────────────────────────────────

    /**
     * Record what a person decided about a rental that cannot finish inside the tolerance.
     *
     * Only offered — and only accepted — when the arithmetic actually asks for it. A car heading
     * for 7,900 km against an 8,000 km allowance is NOT a decision; it is a car that gets its oil
     * changed when it comes back, and putting it in front of a human is exactly the noise this
     * feature exists to remove. Deciding an already-decided contract is allowed (someone changes
     * their mind, or a fresh reading re-opened a defer) — the newest row wins and the old one stays
     * as history.
     *
     * Both decisions raise the standing deferred-maintenance flag: whatever we choose now, this car
     * owes an oil change the moment it is back, and that flag is what the rest of the system
     * already understands by "owes maintenance".
     */
    public function decide(
        Contract $contract,
        string $decision,
        ?User $actor = null,
        ?string $note = null,
        array $options = [],
    ): ContractOilDecision {
        if (! in_array($decision, ContractOilDecision::DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => 'Choose one: recall the car now, or do the oil change when it comes back.',
            ]);
        }

        $projection = $this->project($contract);

        if ($projection['oil_status'] === self::OIL_NO_DATA) {
            throw ValidationException::withMessages([
                'decision' => 'There is no mileage to decide on yet — record a reading from the customer first.',
            ]);
        }

        // The gate. `recall_required` is included so a recall can be revised to a defer — and an
        // OPEN prior decision keeps the question revisable in BOTH directions: once a defer is
        // recorded the classifier reads the car as "booked for return", which must not lock the
        // Controller out of escalating that same car to a recall while it is still out.
        $prior    = $this->latestDecision($contract);
        $revising = $prior !== null && $prior->isOpen();
        $decidable = [self::OIL_DECISION_REQUIRED, self::OIL_RECALL_REQUIRED];
        if (! in_array($projection['oil_status'], $decidable, true) && ! $revising) {
            throw ValidationException::withMessages([
                'decision' => 'This car is projected to finish inside the '
                            . number_format($projection['allowed_max']) . ' km allowance, so there is nothing to decide'
                            . ' — the oil change is already booked for when it comes back.',
            ]);
        }

        // ── Leen's two operational choices, taken at the same moment as the decision ──────────
        // `test_required`: is this car being tested as well? Leen answers it on the recall dialog,
        // and her answer decides TWO things at once — whether a test is in the driver's brief, and
        // whether an inspection request exists for this car at all.
        //
        // Silence is neither yes nor no. A caller that sends nothing is an older caller, not a
        // person who decided against a test, so it keeps exactly the behaviour it always had: the
        // announcing request is filed, and `test_required` stays NULL ("nobody has said yet") for
        // the dispatcher to answer later through the collection instructions.
        $pendingTest = $this->pendingTestRequest($contract->vehicle_id, $prior?->inspection_ticket_id);
        $testChoice  = array_key_exists('test_required', $options) && $options['test_required'] !== null
            ? (bool) $options['test_required']
            : null;
        $testRequired = $testChoice ?? $prior?->test_required;
        $fileRequest  = $testChoice ?? true;

        $location = $options['service_location'] ?? $prior?->service_location;
        if ($location !== null && ! in_array($location, ContractOilDecision::LOCATIONS, true)) {
            throw ValidationException::withMessages([
                'service_location' => 'Say where the oil will be changed: at a garage, or in our parking.',
            ]);
        }
        $location ??= ContractOilDecision::LOCATION_GARAGE;

        return DB::transaction(function () use ($contract, $decision, $actor, $note, $projection, $prior, $testRequired, $fileRequest, $location, $pendingTest) {
            $row = ContractOilDecision::create([
                'contract_id'              => $contract->id,
                'vehicle_id'               => $contract->vehicle_id,
                'decision'                 => $decision,
                'anchor_reading_id'        => $projection['reading_id'],
                'oil_limit'                => $projection['oil_limit'],
                'allowed_max'              => $projection['allowed_max'],
                'expected_return_odometer' => $projection['expected_return'],
                'remaining_days'           => $projection['remaining_days'],
                'decided_by'               => $actor?->id,
                'decided_by_name'          => $actor?->name,
                'note'                     => $note,
                'is_auto'                  => false,
            ]);

            // The two choices, stamped before anything reads them. Written with forceFill because
            // they are operational instructions, not client-supplied attributes of the decision.
            $row->forceFill([
                'test_required'    => $testRequired,
                'service_location' => $location,
            ])->save();

            // Whatever was chosen, the car owes an oil change on return. Reuse the standing flag the
            // rest of the fleet already reads as "route this car to the garage when it lands".
            if ($contract->vehicle) {
                app(OperationsService::class)->flagDeferredMaintenance(
                    $contract->vehicle,
                    $this->flagNote($decision, $projection),
                    $actor?->name,
                );
            }

            // ONE follow-up request per contract in the EXISTING inspection workflow — created on
            // the first decision, reused and re-stamped on every revision (never duplicated). The
            // request carries a REFERENCE to this decision; every figure the inspection side shows
            // is recomputed live from it.
            //
            // …and when the car is NOT being tested there is no request at all: the oil change is a
            // driver job, and filing a test request for it would put a card in the review queue
            // asking a Controller to approve an inspection nobody wants.
            $ticket = $fileRequest
                ? $this->syncInspectionFollowUp($contract, $projection, $decision, $actor, $row, $pendingTest)
                : null;

            if ($decision === ContractOilDecision::DECISION_RECALL) {
                // Carry the relay forward. Re-affirming a recall that Sales already agreed (a
                // second "Recall now" tap, or a revision after a fresh reading) must NOT reset the
                // car to "waiting for Sales" and orphan the collection a driver is already running.
                $this->inheritRecallProgress($prior, $row);
                // …but the choices just made are THIS decision's, so they win over what was carried.
                $row->forceFill([
                    'test_required'    => $testRequired,
                    'service_location' => $location,
                ])->save();

                // The conversation: reach Sales, have them agree the return with the customer.
                $this->openRecallTask($row, $contract, $projection, $actor, $note);

                // 🛑 NO driver collection here, deliberately. The car belongs to a paying customer
                // until Sales says they have agreed to give it back; dispatching a driver before
                // that sends someone to a doorstep nobody has knocked on. The retrieval is raised
                // by confirmSales() — the one gate — and only then does the driver pool hear
                // anything at all. See [[oil-recall-sales-gate]].
            } else {
                $this->cancelOpenRecallTasks($contract, 'Revised to "do it on return".');
                $this->cancelRecallCollections($contract, $actor,
                    'Oil decision revised to "do it on return" — the customer keeps the car until the agreed return.');
            }

            return $row;
        });
    }

    /**
     * Create-or-reuse the ONE inspection follow-up request a decided contract owns, inside the
     * EXISTING workflow (Stage-0 Controller request → Inspector's queue). The request stores a
     * REFERENCE to the decision in `trigger_detail` (plus an at-decision snapshot for audit);
     * everything the inspection side displays is recomputed live from that reference, so a new
     * mileage reading changes the story everywhere at once.
     *
     * Best-effort by design: the decision row is the authoritative write, and a workflow that
     * refuses (inactive fleet, missing actor) must never undo it.
     */
    private function syncInspectionFollowUp(
        Contract $contract,
        array $projection,
        string $decision,
        ?User $actor,
        ContractOilDecision $row,
        ?Maintenance $adoptable = null,
    ): ?Maintenance {
        if (! $actor || ! $contract->vehicle_id) {
            return null; // the request must be attributable to the Controller who decided
        }

        try {
            $verb = $decision === ContractOilDecision::DECISION_RECALL ? 'recall now' : 'oil change on return';

            // Reuse the open request an earlier decision on this contract already filed — and carry
            // its ADOPTED flag with it. decide() writes a new row every time, so reading the flag off
            // the row being created would always say "false": a second "Recall now" on a car whose
            // card belongs to the system would quietly re-label it as ours, and the protection that
            // stops the oil lifecycle closing somebody else's safety check would rest on nothing.
            $priorLink = ContractOilDecision::where('contract_id', $contract->id)
                ->whereNotNull('inspection_ticket_id')
                ->where('id', '!=', $row->id)
                ->orderByDesc('id')
                ->first();

            $ticket = $priorLink?->inspection_ticket_id ? Maintenance::find($priorLink->inspection_ticket_id) : null;
            if ($ticket && in_array($ticket->workflow_status, Maintenance::WF_TERMINAL, true)) {
                $ticket = null;
            }
            $adopted = $ticket ? (bool) $priorLink->request_adopted : false;

            // ADOPT the request the fleet ALREADY has. When the system has flagged this car for a
            // routine check and Leen says "yes, test it too", the oil change is added to THAT card
            // rather than filing a second request for the same car on the same day. Two cards for
            // one car is how a queue stops being believed.
            if (! $ticket && $adoptable) {
                $ticket  = $adoptable;
                $adopted = true;
            }

            if (! $ticket) {
                // Filed with a STUB note: the workflow log quotes the whole note inside its own
                // finite description line; the full figures go on the ticket right after.
                $ticket = app(MaintenanceWorkflowService::class)->requestInspectionByController([
                    'vehicle_id'         => (int) $contract->vehicle_id,
                    'trigger_reason'     => 'test_drive',   // a proactive check, in the office-request pattern
                    'customer_complaint' => sprintf('Oil follow-up — %s (contract %s).', $verb, $contract->contract_no ?? $contract->id),
                    'test_kind'          => Maintenance::TEST_ROUTINE_CHECK,
                ], $actor);

                // The follow-up WAITS in the review queue rather than jumping straight to the
                // Inspector: /inspection-review is the waiting room for a car that is still out
                // (its card shows "Waiting for return"), and approving it when the car actually
                // arrives is what hands it to the Inspector. The Controller stamps stay — the
                // request remains fully attributable.
                $ticket->workflow_status = Maintenance::WF_PENDING_REVIEW;
            }

            $overAllowance = max(0, (int) ($projection['over_tolerance_km'] ?? 0));

            // The reference (authoritative) + an at-decision snapshot (audit): "what did the
            // Controller see when they decided" stays answerable even after readings move on.
            $oilDetail = [
                'source'                   => 'oil_projection',
                'contract_oil_decision_id' => $row->id,
                'contract_id'              => $contract->id,
                'contract_no'              => $contract->contract_no,
                'decision'                 => $decision,
                'decided_by'               => $actor->name,
                'adopted_request'          => $adopted,
                'figures_at_decision'      => [
                    'anchor_odometer' => $projection['anchor_odometer'],
                    'expected_return' => $projection['expected_return'],
                    'oil_limit'       => $projection['oil_limit'],
                    'allowed_max'     => $projection['allowed_max'],
                    'over_allowance'  => $overAllowance,
                    'remaining_days'  => $projection['remaining_days'],
                ],
            ];
            $oilLine = sprintf(
                'Oil follow-up — %s. Moved more than expected: ~%s km over the %s km max (return ~%s km; last reading %s km, contract %s). Check oil on arrival.',
                $verb,
                number_format($overAllowance),
                number_format((int) $projection['allowed_max']),
                number_format((int) $projection['expected_return']),
                number_format((int) $projection['anchor_odometer']),
                $contract->contract_no ?? $contract->id,
            );

            if ($adopted) {
                // An adopted request keeps its OWN reason and its own trigger detail — the system's
                // "why this car was flagged" is evidence, not scaffolding, and overwriting it would
                // leave a card that can no longer explain why it exists. The oil layer is added
                // ALONGSIDE it, under its own key, and the review card renders both.
                $detail = is_array($ticket->trigger_detail) ? $ticket->trigger_detail : [];
                $detail['oil_projection'] = $oilDetail;
                $ticket->trigger_detail   = $detail;

                if (! str_contains((string) $ticket->customer_complaint, 'Oil follow-up')) {
                    $ticket->customer_complaint = trim((string) $ticket->customer_complaint) . "\n\n" . $oilLine;
                }
            } else {
                $ticket->trigger_detail     = $oilDetail;
                $ticket->customer_complaint = $oilLine;
            }
            $ticket->save();

            $row->forceFill([
                'inspection_ticket_id' => $ticket->id,
                'request_adopted'      => $adopted,
            ])->save();

            return $ticket;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    // ── Giving the car back ──────────────────────────────────────────────────────────────────

    /** How often the "give the car back" chase re-rings while we are still holding the car. */
    public const RETURN_CHASE_MINUTES = 5;

    /**
     * THE CAR IS BACK WITH THE CUSTOMER — the step that actually ends a recall.
     *
     * We interrupted a paying rental. The oil change is what the fleet wanted; the customer wants
     * their car. Until this is stamped the recall is not finished, whatever the workshop has done,
     * and the chase below keeps ringing.
     *
     * Idempotent — a second press returns the same row untouched.
     */
    public function markReturnedToCustomer(Contract $contract, User $actor, ?string $note = null): ContractOilDecision
    {
        $row = $this->latestDecision($contract);

        if (! $row || ! $row->isOilChanged()) {
            throw ValidationException::withMessages([
                'return' => 'There is nothing to hand back yet — the oil change has not been recorded.',
            ]);
        }

        if ($row->returned_to_customer_at !== null) {
            return $row;
        }

        $row->forceFill([
            'returned_to_customer_at'      => Carbon::now(),
            'returned_to_customer_by_name' => $actor->name ?: $actor->email,
        ])->save();

        $this->logRecallEvent($row, VehicleLogEvent::EVENT_OIL_RECALL_RETURNED, $actor, [
            'description' => 'Car handed back to the customer after the oil change'
                           . ($note ? ' — ' . $note : '') . '.',
            'meta' => [
                'contract_id'     => $contract->id,
                'contract_no'     => $contract->contract_no,
                'oil_decision_id' => $row->id,
                'held_minutes'    => $row->oil_changed_at?->diffInMinutes(Carbon::now()),
            ],
        ]);

        return $row->fresh();
    }

    /**
     * THE CHASE — ring the people holding the car, every few minutes, until it goes back.
     *
     * Deliberately noisy, and deliberately not a daily digest: this step is invisible by nature. The
     * work is finished, the ticket is closed, the workshop has moved on — and a customer is paying
     * for a car standing in our yard. Nothing else in the system will notice.
     *
     * Stateless by design. It re-reads the world every run and rings anyone whose last ring is older
     * than the window, so it is safe to run every minute, restart mid-sweep, or run twice: the
     * `return_reminder_at` stamp is what stops a double ring, not a queue of jobs.
     *
     * @return array{chased:int, notified:int}
     */
    public function chaseCustomerReturns(): array
    {
        $window  = Carbon::now()->subMinutes(self::RETURN_CHASE_MINUTES);
        $chased  = 0;
        $rung    = 0;

        ContractOilDecision::query()
            ->whereNotNull('oil_changed_at')
            ->whereNull('returned_to_customer_at')
            ->where(fn ($q) => $q->whereNull('return_reminder_at')->orWhere('return_reminder_at', '<=', $window))
            ->with(['contract.customer', 'vehicle'])
            ->orderBy('oil_changed_at')
            ->limit(100)
            ->get()
            ->each(function (ContractOilDecision $row) use (&$chased, &$rung) {
                // The contract may have closed since — the customer came and got it, or the rental
                // ended. Nothing owed, and nothing to ring about.
                if (! $row->owesReturnToCustomer()) {
                    $row->forceFill(['returned_to_customer_at' => $row->returned_to_customer_at ?: Carbon::now()])->save();

                    return;
                }

                $chased++;
                $rung += $this->ringReturnChase($row);
                $row->forceFill(['return_reminder_at' => Carbon::now()])->save();
            });

        return ['chased' => $chased, 'notified' => $rung];
    }

    /**
     * One round of the chase for one car. Rung to the people who can actually act: whoever owns the
     * work where the car is standing (Abu Maroof in our parking, the Supervisors at a garage) plus
     * the Controllers who took the decision and will field the customer's call.
     */
    private function ringReturnChase(ContractOilDecision $row): int
    {
        $vehicle  = $row->vehicle;
        $contract = $row->contract;
        $plate    = $vehicle?->plate_no ?: ('#' . $row->vehicle_id);
        $held     = $row->oil_changed_at ? $row->oil_changed_at->diffForHumans(null, true) : null;

        $body = trim(sprintf(
            "The oil change is done — this car is still with us and the customer is still paying for it.\n"
            . "%s%s\nHand it back and press “Returned to the customer” to stop this reminder.",
            $contract?->customer?->name_en ? 'Customer: ' . $contract->customer->name_en . '. ' : '',
            $held ? 'Waiting ' . $held . ' since the oil was changed.' : '',
        ));

        $sent = 0;
        foreach ($this->collectionOwners($row)->merge($this->controllers())->unique('id') as $user) {
            app(NotificationScanner::class)->notifyUser($user, [
                'type'     => 'oil_return_to_customer',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Give the car back · ' . $plate,
                'body'     => $body,
                'url'      => '/oil-projection',
                // Keyed on the ROUND, not the decision: this alert is meant to re-ring, and a key
                // that never changes is exactly what the notification scanner dedups away.
                'key'      => 'oil_return:' . $row->id . ':' . Carbon::now()->format('YmdHi'),
                'icon'     => 'truck',
                'meta'     => [
                    'contract_id'     => $row->contract_id,
                    'vehicle_id'      => $row->vehicle_id,
                    'oil_decision_id' => $row->id,
                    'oil_changed_at'  => optional($row->oil_changed_at)->toIso8601String(),
                ],
            ]);
            $sent++;
        }

        return $sent;
    }

    /**
     * DOES THE FLEET ALREADY WANT THIS CAR TESTED? — the request sitting in /inspection-review.
     *
     * The commonest case by far is the system's own "routine check overdue" card: the car has been
     * out 15 days, the scheduler flagged it, and it is waiting for a Controller. If Leen is now
     * recalling that same car for oil, filing a SECOND request would put two cards for one car in
     * one queue — so this finds the first one, and the oil change is added to it instead.
     *
     * Deliberately narrow: only a request that is still WAITING for a decision (nothing already
     * approved and running), never one the oil lifecycle raised itself, and never a request that is
     * already linked to this contract's own decision.
     */
    public function pendingTestRequest(?int $vehicleId, ?int $excludeTicketId = null): ?Maintenance
    {
        if (! $vehicleId) {
            return null;
        }

        return Maintenance::query()
            ->where('vehicle_id', $vehicleId)
            ->where('workflow_status', Maintenance::WF_PENDING_REVIEW)
            ->when($excludeTicketId, fn ($q) => $q->where('id', '!=', $excludeTicketId))
            // Never adopt an oil follow-up — including one raised for a different contract on the
            // same car. Adopting it would nest the oil story inside itself.
            ->whereNotIn('id', ContractOilDecision::whereNotNull('inspection_ticket_id')->select('inspection_ticket_id'))
            ->orderBy('id')
            ->first();
    }

    // ── The Sales gate ───────────────────────────────────────────────────────────────────────

    /**
     * SALES OK — the customer has agreed to bring the car back. This is the gate the whole recall
     * waits behind, and the only place a driver collection is ever raised.
     *
     * The person clicking this is not typing the customer's answer into a form; they are recording
     * that they spoke to Sales and Sales confirmed. That is why it takes one click and an
     * authenticated actor and nothing else — the note is optional colour, never the evidence.
     *
     * Strictly once. A second click returns the same row untouched: no second collection, no second
     * alert to the Supervisors, no second anything. The `sales_confirmed_at` stamp is what makes
     * that true, and it is written before any notification goes out.
     *
     * @throws ValidationException when there is no open recall to confirm
     */
    public function confirmSales(Contract $contract, User $actor, ?string $note = null): ContractOilDecision
    {
        $row = $this->latestDecision($contract);

        if (! $row || $row->decision !== ContractOilDecision::DECISION_RECALL || ! $row->isOpen()) {
            throw ValidationException::withMessages([
                'sales' => 'There is no open recall on this rental to confirm. Choose "Recall now" first.',
            ]);
        }

        // Already confirmed — say yes, change nothing. Re-tapping a button that has already fired
        // must never cost the fleet a duplicate driver run.
        if ($row->isSalesConfirmed()) {
            return $row;
        }

        $projection = $this->project($contract);

        return DB::transaction(function () use ($contract, $row, $actor, $note, $projection) {
            $row->forceFill([
                'sales_confirmed_at'      => Carbon::now(),
                'sales_confirmed_by'      => $actor->id,
                'sales_confirmed_by_name' => $actor->name ?: $actor->email,
                'sales_note'              => $note,
            ])->save();

            // The call this recall raised has served its purpose — the customer has agreed. Moved
            // to `contacted` rather than closed: the car is not back yet, and the Controllers still
            // own the conversation until it is.
            OilRecallTask::open()->where('contract_id', $contract->id)->get()
                ->each(fn (OilRecallTask $t) => $t->forceFill([
                    'status'       => OilRecallTask::STATUS_CONTACTED,
                    'claimed_by'   => $t->claimed_by ?: $actor->id,
                    'claimed_at'   => $t->claimed_at ?: Carbon::now(),
                    'outcome_note' => trim(($t->outcome_note ? $t->outcome_note . ' · ' : '')
                                    . 'Sales confirmed the customer will return the car'
                                    . ($note ? ' — ' . $note : '') . '.'),
                ])->save());

            // NOW the movement exists. One task, linked to the same follow-up request.
            $task = $this->openRecallCollection($contract, $row, $projection, $actor);
            if ($task) {
                $row->forceFill(['collection_task_id' => $task->id])->save();
            }

            $this->logRecallEvent($row, VehicleLogEvent::EVENT_OIL_RECALL_SALES_CONFIRMED, $actor, [
                'description' => 'Sales confirmed the customer will return the car — driver collection released'
                               . ($task ? ' (dispatch #' . $task->id . ')' : '') . '.',
                'meta' => [
                    'contract_id'        => $contract->id,
                    'contract_no'        => $contract->contract_no,
                    'oil_decision_id'    => $row->id,
                    'collection_task_id' => $task?->id,
                    'from_stage'         => ContractOilDecision::STAGE_WAITING_SALES,
                    'to_stage'           => $row->fresh()->recallStage(),
                    'sales_note'         => $note,
                    'confirmed_by'       => $actor->name,
                ],
            ]);

            $this->notifySupervisorsOfCollection($contract, $row->fresh(), $projection, $actor, $task);

            return $row->fresh();
        });
    }

    /**
     * The dispatcher's instruction for what happens to the car after it is collected.
     *
     * The ONLY thing this accepts is whether a test/inspection is wanted. The oil change is not on
     * the form — not as a locked field, not as an ignored field, it is simply not an input — so
     * there is no request shape, honest or crafted, that can turn it off. A recall that came from
     * the oil projection owes an oil change by definition; see ContractOilDecision::requiredActions().
     *
     * @throws ValidationException when there is no open recall to instruct
     */
    public function setCollectionInstructions(Contract $contract, bool $testRequired, User $actor): ContractOilDecision
    {
        $row = $this->latestDecision($contract);

        if (! $row || $row->decision !== ContractOilDecision::DECISION_RECALL || ! $row->isOpen()) {
            throw ValidationException::withMessages([
                'test_required' => 'There is no open recall on this rental to give instructions for.',
            ]);
        }

        return DB::transaction(function () use ($contract, $row, $testRequired, $actor) {
            $row->forceFill(['test_required' => $testRequired])->save();

            // Rewrite the driver's brief so the person doing the collecting reads the same required
            // work the workshop will read. The task is the driver's copy; this row is the record.
            $task = $row->collectionTask;
            if ($task && $task->isActive()) {
                $task->notes = $this->collectionBrief($contract, $row);
                $task->save();
            }

            $this->logRecallEvent($row, VehicleLogEvent::EVENT_OIL_RECALL_INSTRUCTED, $actor, [
                'description' => 'After collection: ' . ($testRequired ? 'inspection/test + ' : '')
                               . 'oil change (required — this recall came from the oil projection).',
                'meta' => [
                    'contract_id'      => $contract->id,
                    'oil_decision_id'  => $row->id,
                    'required_actions' => $row->requiredActions(),
                ],
            ]);

            return $row->fresh();
        });
    }

    // ── The far end: the oil was actually changed ────────────────────────────────────────────

    /**
     * THE OIL IS CHANGED — one number, and the whole follow-up ends.
     *
     * Everything upstream of this is arrangement: the projection predicts, a Controller decides,
     * Sales agree, a driver collects. None of it changes a single kilometre of the car's oil life.
     * This does. The workshop reads the dash after the change and enters that number, and from it:
     *
     *   • the car's service anchor moves      (Vehicle::recordOilService — the SOLE writer of
     *     `last_service_odometer`, which also rolls the recurring oil ServiceReminder forward, so
     *     the vehicle profile now says "next change at reading + interval": 20,000 → 27,000);
     *   • the projection re-anchors           (the reading is stored against the contract, so the
     *     board recomputes from a REAL number instead of a 200 km/day guess, and the car drops out
     *     of the queue by arithmetic rather than by someone dismissing it);
     *   • the follow-up closes                (the decision is stamped and settled, the recall call
     *     and any collection still out are stood down, the announcing inspection request is
     *     resolved, and the standing deferred-maintenance flag is cleared).
     *
     * The 500 km grace is untouched here on purpose: it belongs to the PROJECTION (how far a car may
     * run past its limit before it becomes a question), never to the service record. The next cycle
     * starts at the reading, and the grace is added again on top of the new limit by threshold().
     *
     * Idempotent: recording it twice returns the first record untouched — one oil change, one anchor.
     *
     * @throws ValidationException when there is no open follow-up, or the reading is not credible
     */
    public function recordOilChange(Contract $contract, int $odometer, User $actor, ?string $note = null): ContractOilDecision
    {
        $vehicle = $contract->vehicle;
        if (! $vehicle) {
            throw ValidationException::withMessages(['odometer' => 'This contract has no vehicle.']);
        }

        $row = $this->latestDecision($contract);
        if (! $row) {
            throw ValidationException::withMessages([
                'odometer' => 'There is no oil follow-up on this rental. Decide "Recall now" or'
                            . ' "Do it on return" first, so the change is recorded against something.',
            ]);
        }

        // Already recorded. Say yes and change nothing — a double tap must never move the anchor a
        // second time and hand the car a fresh interval it has not earned.
        if ($row->isOilChanged()) {
            return $row;
        }

        if ($odometer <= self::PLACEHOLDER_MAX) {
            throw ValidationException::withMessages([
                'odometer' => 'Enter the odometer the oil was changed at.',
            ]);
        }

        // The same discipline as a customer reading: mileage does not run backwards. Getting this
        // wrong here is worse than on the board — a low number becomes the car's service anchor and
        // silently shortens (or parks) its next interval.
        $anchor = $this->anchor($contract);
        if ($anchor && $odometer < $anchor['odometer']) {
            throw ValidationException::withMessages([
                'odometer' => 'The reading is below the last known odometer ('
                            . number_format($anchor['odometer']) . ' km). Check the number.',
            ]);
        }
        if ($vehicle->last_service_odometer !== null && $odometer < (int) $vehicle->last_service_odometer) {
            throw ValidationException::withMessages([
                'odometer' => 'The reading is below the last recorded oil service ('
                            . number_format((int) $vehicle->last_service_odometer) . ' km). Check the number.',
            ]);
        }

        return DB::transaction(function () use ($contract, $vehicle, $row, $odometer, $actor, $note, $anchor) {
            // 1. The reading itself, against the contract — this is what re-anchors the projection.
            //    Source STAFF: a workshop reading off the dash is our own capture, not a phone call.
            ContractMileageReading::create([
                'contract_id' => $contract->id,
                'vehicle_id'  => $vehicle->id,
                'odometer'    => $odometer,
                'reported_on' => Carbon::now()->toDateString(),
                'recorded_by' => $actor->id,
                'reported_by' => $actor->name ?: $actor->email,
                'source'      => ContractMileageReading::SOURCE_STAFF,
                'note'        => trim('Odometer at the oil change' . ($note ? ' — ' . $note : '')),
            ]);

            // 2. The car itself. ONE writer for the service anchor, fleet-wide — this rolls both the
            //    vehicle's `last_service_odometer` and the recurring oil ServiceReminder.
            $vehicle->recordOilService($odometer);

            // 3. The follow-up is finished. `settled_at` with no ticket is what keeps the hourly
            //    return sweep from minting an oil ticket for a change that has already been done.
            $row->forceFill([
                'oil_changed_at'       => Carbon::now(),
                'oil_changed_odometer' => $odometer,
                'oil_changed_by'       => $actor->id,
                'oil_changed_by_name'  => $actor->name ?: $actor->email,
                'oil_change_note'      => $note,
                'settled_at'           => Carbon::now(),
            ])->save();

            $done = 'Oil changed at ' . number_format($odometer) . ' km by ' . ($actor->name ?: $actor->email) . '.';

            $nextDue = $odometer + (int) $vehicle->fresh()->service_interval_km;
            $outcome = $done . ' Next change due at ' . number_format($nextDue) . ' km.';

            // 4. Everything that was only ever asking for this change stands down.
            $this->cancelOpenRecallTasks($contract, $done);
            $this->cancelRecallCollections($contract, $actor, $done . ' Collection no longer needed.');

            // The announcing inspection request is resolved ONLY if the oil was all it was waiting
            // for. A recall that also asked for a test still owes that test, and closing the request
            // here would quietly delete it — the card instead keeps its place in the review queue
            // and now reads "oil changed", so the reviewer approves it for the test alone.
            if ($row->test_required || $row->hasAdoptedRequest()) {
                $this->annotateInspectionFollowUp($contract, $outcome . ' The test is still owed.');
            } else {
                $this->resolveInspectionFollowUp($contract, null, $outcome);
            }

            app(OperationsService::class)->resolveDeferredMaintenance($vehicle);

            $fresh = $vehicle->fresh();
            $this->logRecallEvent($row, VehicleLogEvent::EVENT_OIL_CHANGE_RECORDED, $actor, [
                'description' => $done . ' Next change due at '
                               . number_format((int) $fresh->last_service_odometer + (int) $fresh->service_interval_km)
                               . ' km (' . number_format((int) $fresh->service_interval_km) . ' km interval).',
                'meta' => [
                    'contract_id'          => $contract->id,
                    'contract_no'          => $contract->contract_no,
                    'oil_decision_id'      => $row->id,
                    'odometer'             => $odometer,
                    'previous_anchor'      => $anchor['odometer'] ?? null,
                    'next_due_odometer'    => (int) $fresh->last_service_odometer + (int) $fresh->service_interval_km,
                    'service_interval_km'  => (int) $fresh->service_interval_km,
                    'note'                 => $note,
                ],
            ]);

            return $row->fresh();
        });
    }

    /**
     * The retrieval leg of a recall: a pooled driver collection through the existing logistics
     * lane. Raised ONLY after Sales have confirmed (see confirmSales). Idempotent — the dispatch
     * service returns an existing open move untouched — linked to the follow-up request via
     * maintenance_id, and deliberately silent about the vehicle's operational status: the customer
     * holds the car until a driver actually picks it up, and that pick-up is the custody fact.
     */
    private function openRecallCollection(Contract $contract, ContractOilDecision $row, array $projection, ?User $actor): ?LogisticsTask
    {
        if (! $actor || ! $contract->vehicle) {
            return null;
        }

        try {
            return app(LogisticsDispatchService::class)->dispatchOilRecallCollection(
                $contract->vehicle,
                $row->inspection_ticket_id,
                $this->collectionBrief($contract, $row, $projection),
                $actor,
                $this->destinationFor($row),
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The driver's brief — what to collect, from whom, and what the car owes when it lands. The
     * oil change is stated as a requirement, not a suggestion: the driver is not being asked to
     * decide it, only to carry it with the car.
     */
    private function collectionBrief(Contract $contract, ContractOilDecision $row, ?array $projection = null): string
    {
        $projection ??= [
            'expected_return'   => $row->expected_return_odometer,
            'allowed_max'       => $row->allowed_max,
            'over_tolerance_km' => (int) $row->expected_return_odometer - (int) $row->allowed_max,
        ];

        $required = $row->test_required
            ? 'Inspection/test + OIL CHANGE (required)'
            : 'OIL CHANGE (required)';

        return sprintf(
            'Oil recall — collect from customer %s (contract %s) and bring it to %s.'
            . ' Sales confirmed the return. Projected ~%s km vs %s km max (%s km over).'
            . ' Odometer reading on collection. Required after arrival: %s.',
            $contract->customer?->name_en ?? 'the customer',
            $contract->contract_no ?? $contract->id,
            $this->destinationFor($row),
            number_format((int) $projection['expected_return']),
            number_format((int) $projection['allowed_max']),
            number_format(max(0, (int) ($projection['over_tolerance_km'] ?? 0))),
            $required,
        );
    }

    /** Where the driver is taking the car, in the words the driver reads on his own queue. */
    private function destinationFor(ContractOilDecision $row): string
    {
        return $row->serviceLocation() === ContractOilDecision::LOCATION_PARKING
            ? 'Parking'
            : 'Workshop';
    }

    /**
     * WHO is told to make the oil change happen — decided by WHERE it happens, nothing else.
     *
     *   parking → the Inspector (Abu Maroof) does it himself in our own yard;
     *   garage  → the Supervisors (Waleed & Abdullah) arrange it with a garage.
     *
     * The driver pool hears about the collection either way, from the dispatch itself — he is
     * fetching the car in both cases, and neither audience replaces him.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    public function collectionOwners(ContractOilDecision $row): \Illuminate\Support\Collection
    {
        if ($row->serviceLocation() === ContractOilDecision::LOCATION_PARKING) {
            return $this->parkingOwners();
        }

        return $this->collectionSupervisors();
    }

    /**
     * Abu Maroof — the person who changes the oil when the car comes to our own parking.
     *
     * Resolved the same way every other recipient list in this codebase is: a named allow-list
     * first, and only then a permission fallback NARROWED BY ROLE. The bare permission would also
     * match Lin, Marwa and the QA accounts, and a job handed to everyone is a job nobody owns.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    public function parkingOwners(): \Illuminate\Support\Collection
    {
        $ids = array_values(array_filter((array) config('maintenance.oil_projection.parking_user_ids', [])));
        if ($ids) {
            return User::whereIn('id', $ids)->where('status', 'active')->orderBy('id')->get();
        }

        $permission = (string) config('maintenance.oil_projection.parking_fallback_permission', 'maintenance.initiate');
        $roles      = array_filter((array) config('maintenance.oil_projection.parking_fallback_roles', []));

        // No role configured ⇒ no automatic fallback at all, and the caller says so rather than
        // broadcasting. Silence that is visible beats an alert nobody owns.
        if (! $roles) {
            Log::warning('Oil recall: no parking recipient configured — nobody was told to change the oil');

            return collect();
        }

        return User::permission($permission)->role($roles)->where('status', 'active')->orderBy('id')->get();
    }

    /**
     * Tell the Supervisors (Waleed & Abdullah) to arrange a driver — the ONE alert this gate fires,
     * and never before Sales have confirmed.
     *
     * Sent to the same audience the workshop's own follow-up goes to, resolved through the shared
     * recipient rule (named allow-list first, then supervisors by role) rather than broadcast to a
     * bare permission — a job handed to everyone is a job nobody owns. The driver POOL is alerted
     * separately by the dispatch itself; nobody is auto-assigned, which is the point: the
     * Supervisors pick the driver through the existing logistics board.
     */
    private function notifySupervisorsOfCollection(
        Contract $contract,
        ContractOilDecision $row,
        array $projection,
        User $actor,
        ?LogisticsTask $task,
    ): void {
        $vehicle = $contract->vehicle;
        $car     = trim(($vehicle?->plate_no ? $vehicle->plate_no . ' · ' : '')
                      . trim(($vehicle?->make ?? '') . ' ' . ($vehicle?->model ?? '')));
        $over    = max(0, (int) ($projection['over_tolerance_km'] ?? 0));
        $parking = $row->serviceLocation() === ContractOilDecision::LOCATION_PARKING;

        // The ask is different for each audience, so the sentence is too: the Inspector is being
        // told to do the work, the Supervisors to arrange it. A single generic "please handle this"
        // is how a job ends up owned by nobody.
        $body = trim(sprintf(
            "%s — customer return confirmed by Sales. %s\n"
            . "Reason: oil service required (%s km past the %s km allowance).\n"
            . 'A driver is collecting it and bringing it to %s. Required on arrival: %s.',
            $car ?: ('Vehicle #' . $contract->vehicle_id),
            $parking
                ? 'The oil change is to be done in our parking — please do it when the car lands.'
                : 'Please arrange a driver and a garage for the oil change.',
            number_format($over),
            number_format((int) ($projection['allowed_max'] ?? $row->allowed_max)),
            $parking ? 'the parking' : 'the garage',
            $row->test_required ? 'inspection/test + oil change (required)' : 'oil change (required)',
        ));

        foreach ($this->collectionOwners($row) as $user) {
            app(NotificationScanner::class)->notifyUser($user, [
                'type'     => 'oil_recall_collection',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => ($parking ? 'Oil change in the parking · ' : 'Arrange a driver · ')
                            . ($vehicle?->plate_no ?: 'oil recall'),
                'body'     => $body,
                // Straight to the move itself, so the alert IS the way in to the work.
                'url'      => $task ? '/logistics?task=' . $task->id : '/logistics',
                // Keyed on the decision, not the moment: a retried confirm cannot double-ring.
                'key'      => 'oil_recall_collection:' . $row->id,
                'icon'     => 'truck',
                'meta'     => [
                    'contract_id'        => $contract->id,
                    'vehicle_id'         => $contract->vehicle_id,
                    'plate'              => $vehicle?->plate_no,
                    'oil_decision_id'    => $row->id,
                    'collection_task_id' => $task?->id,
                    'inspection_ticket_id' => $row->inspection_ticket_id,
                    'required_actions'   => $row->requiredActions(),
                    'service_location'   => $row->serviceLocation(),
                ],
            ]);
        }
    }

    /**
     * WHO arranges the driver: the Supervisors (Waleed & Abdullah). Resolved through the shared
     * checkpoint recipient rule so this feature can never drift into broadcasting to everyone
     * holding `maintenance.delegate` — on the live fleet that also matches admins and QA accounts.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    public function collectionSupervisors(): \Illuminate\Support\Collection
    {
        return app(MaintenanceCheckpointService::class)->defaultRecipients();
    }

    /**
     * Carry a still-running recall forward onto a re-affirmed decision row.
     *
     * `decide()` writes a NEW row every time (the history is the point), so without this a second
     * "Recall now" — or a revision after a fresh reading — would produce a row with no Sales stamp
     * and no collection link, and the board would ask for a confirmation that already happened
     * while a driver was on the road. The relay belongs to the CAR, not to the row.
     */
    private function inheritRecallProgress(?ContractOilDecision $prior, ContractOilDecision $row): void
    {
        if (! $prior || ! $prior->isSalesConfirmed()) {
            return;
        }

        $task = $prior->collectionTask;

        $row->forceFill([
            'sales_confirmed_at'      => $prior->sales_confirmed_at,
            'sales_confirmed_by'      => $prior->sales_confirmed_by,
            'sales_confirmed_by_name' => $prior->sales_confirmed_by_name,
            'sales_note'              => $prior->sales_note,
            // Only a LIVE move carries over. A cancelled/finished collection stays with the row it
            // belonged to, and the new decision starts from "find a driver" instead of pointing at
            // a movement that is over.
            'collection_task_id'      => $task && $task->isActive() ? $task->id : null,
            'test_required'           => $prior->test_required,
        ])->save();
    }

    /**
     * Append a recall step to the vehicle's audit trail. Anchored to the follow-up REQUEST when
     * there is one, so the whole story — recall, Sales OK, instructions, inspection, oil change —
     * reads as one ticket's history rather than scattered vehicle notes.
     */
    private function logRecallEvent(ContractOilDecision $row, string $event, User $actor, array $opts): void
    {
        try {
            $log     = app(VehicleLogService::class);
            $request = $row->inspectionTicket;

            if ($request) {
                $log->record($request, $event, $actor, $opts);

                return;
            }

            if ($row->vehicle) {
                $log->recordVehicle($row->vehicle, $event, $actor, $opts + ['source_tag' => 'oil_recall']);
            }
        } catch (\Throwable $e) {
            report($e);   // an audit write must never sink the transition it describes
        }
    }

    /**
     * The recall relay as every consumer reads it: which stage, who acts next, what Sales said,
     * where the driver is, and what the car owes on arrival.
     *
     * Derived on every read from the records that own each fact — this service invents no state of
     * its own beyond the Sales stamp. Null for anything that is not an open recall.
     */
    public function recallState(?ContractOilDecision $row): ?array
    {
        if (! $row || $row->decision !== ContractOilDecision::DECISION_RECALL) {
            return null;
        }

        $stage = $row->recallStage();
        $task  = $row->collectionTask;

        return [
            'decision_id' => $row->id,
            'stage'       => $stage,
            // WHO must act now. The board's whole job is to answer this without anyone guessing.
            'owner'       => match ($stage) {
                ContractOilDecision::STAGE_WAITING_SALES     => 'sales',
                ContractOilDecision::STAGE_READY_FOR_DRIVER  => 'supervisor',
                ContractOilDecision::STAGE_DRIVER_ASSIGNED,
                ContractOilDecision::STAGE_VEHICLE_COLLECTED => 'driver',
                ContractOilDecision::STAGE_AT_WORKSHOP       => 'controller',
                ContractOilDecision::STAGE_INSPECTION        => 'inspector',
                ContractOilDecision::STAGE_OIL_SERVICE       => 'workshop',
                default                                      => null,
            },
            'awaiting_sales'    => $row->isAwaitingSalesConfirmation(),
            'in_our_custody'    => $row->inOurCustody(),
            'sales' => [
                'confirmed'    => $row->isSalesConfirmed(),
                'confirmed_at' => optional($row->sales_confirmed_at)->toIso8601String(),
                'confirmed_by' => $row->sales_confirmed_by_name,
                'note'         => $row->sales_note,
            ],
            'collection' => $task ? [
                'task_id'   => $task->id,
                'status'    => $task->status,
                'phase'     => $task->phaseLabel(),
                'driver'    => $task->assigned_to_name,
                'claimed'   => $task->assigned_to_id !== null,
                'active'    => $task->isActive(),
                'destination' => $task->destination,
            ] : null,
            'required_actions'    => $row->requiredActions(),
            'inspection_ticket_id' => $row->inspection_ticket_id,
            'service_ticket_id'    => $row->settled_ticket_id,
            // WHERE the change happens, and therefore who owns it. Published so no consumer has to
            // re-derive the routing rule that decided who was told.
            'service_location'    => $row->serviceLocation(),
            'owner_role'          => $row->serviceLocation() === ContractOilDecision::LOCATION_PARKING
                ? 'inspector'
                : 'supervisor',
            // True when the test this recall rides on was ALREADY in the review queue — the card is
            // the system's own request with the oil change added, not a second card for the same car.
            'request_adopted'     => $row->hasAdoptedRequest(),
            // The last step: the oil is done and the customer is still paying for a car in our yard.
            'owes_return'         => $row->owesReturnToCustomer(),
            'returned_at'         => optional($row->returned_to_customer_at)->toIso8601String(),
            'returned_by'         => $row->returned_to_customer_by_name,
            'oil_changed_at'      => optional($row->oil_changed_at)->toIso8601String(),
        ];
    }

    /** Stand down any open recall collection for this contract's car (revision, or the car came back). */
    private function cancelRecallCollections(Contract $contract, ?User $actor, string $why): void
    {
        if (! $actor || ! $contract->vehicle_id) {
            return;
        }

        try {
            $ticketIds = ContractOilDecision::where('contract_id', $contract->id)
                ->whereNotNull('inspection_ticket_id')
                ->pluck('inspection_ticket_id');

            $tasks = LogisticsTask::open()
                ->where('vehicle_id', $contract->vehicle_id)
                ->where(function ($q) use ($ticketIds) {
                    $q->whereIn('maintenance_id', $ticketIds)
                        ->orWhere('notes', 'like', 'Oil recall%');
                })
                ->get();

            $svc = app(LogisticsDispatchService::class);
            foreach ($tasks as $task) {
                $task->notes = trim(($task->notes ? $task->notes . ' · ' : '') . $why);
                $task->save();
                $svc->cancel($task, $actor);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Close the announcing follow-up request once the car is back and the REAL service ticket
     * exists — but only while it is still a request (pre-ticket); an inspection the Inspector has
     * already started is never yanked away.
     */
    private function resolveInspectionFollowUp(Contract $contract, ?Maintenance $serviceTicket, string $note): void
    {
        try {
            $ids = ContractOilDecision::where('contract_id', $contract->id)
                ->whereNotNull('inspection_ticket_id')
                ->pluck('inspection_ticket_id')
                ->unique();

            // A request we ADOPTED is not ours to close. It was already in the queue asking for a
            // test of its own (typically the system's routine check), and the oil change has not
            // performed that test — closing it here would silently delete a safety check nobody
            // cancelled. It gets the outcome written on it instead.
            $adopted = ContractOilDecision::where('contract_id', $contract->id)
                ->where('request_adopted', true)
                ->pluck('inspection_ticket_id')
                ->filter()
                ->unique();

            foreach ($ids as $id) {
                $req = Maintenance::find($id);
                if (! $req || ($serviceTicket && (int) $req->id === (int) $serviceTicket->id)) {
                    continue;
                }
                if ($adopted->contains($id)) {
                    $this->annotateInspectionFollowUp($contract, $note);
                    continue;
                }
                if (! in_array($req->workflow_status, Maintenance::WF_PRE_TICKET, true)) {
                    continue;
                }
                $req->workflow_status = Maintenance::WF_CLOSED;
                $req->review_notes = trim(((string) $req->review_notes !== '' ? $req->review_notes . ' · ' : '') . $note);
                $req->save();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Write an outcome onto the announcing follow-up request WITHOUT closing it — for the case where
     * the oil is done but the request still has work of its own (a test the recall asked for).
     */
    private function annotateInspectionFollowUp(Contract $contract, string $note): void
    {
        try {
            $id = ContractOilDecision::where('contract_id', $contract->id)
                ->whereNotNull('inspection_ticket_id')
                ->orderByDesc('id')
                ->value('inspection_ticket_id');

            $req = $id ? Maintenance::find($id) : null;
            if (! $req || ! in_array($req->workflow_status, Maintenance::WF_PRE_TICKET, true)) {
                return;
            }

            $req->review_notes = trim(((string) $req->review_notes !== '' ? $req->review_notes . ' · ' : '') . $note);
            $req->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Raise the "phone the customer and arrange the return" task, and put it in front of the two
     * people who make these calls.
     *
     * 🛑 This creates NO logistics record. Recalling a car is a conversation, not a movement: nobody
     * knows yet when or from where the car is coming back, and inventing a dispatch would put a
     * half-specified transport job in a driver's queue. When a real logistics lane exists it will
     * hang off this task, and nothing in the decision engine above will need to change.
     */
    private function openRecallTask(
        ContractOilDecision $decision,
        Contract $contract,
        array $projection,
        ?User $actor,
        ?string $note,
    ): OilRecallTask {
        $recipients = $this->controllers();

        $task = OilRecallTask::create([
            'contract_oil_decision_id' => $decision->id,
            'contract_id'              => $contract->id,
            'vehicle_id'               => $contract->vehicle_id,
            'status'                   => OilRecallTask::STATUS_OPEN,
            'reason_code'              => OilRecallTask::REASON_OIL_TOLERANCE,
            // The figures frozen as the caller will read them out — the live projection will have
            // moved on by the time anyone picks up the phone.
            'customer_reading'         => $projection['anchor_odometer'],
            'customer_reading_on'      => $projection['anchor_on'],
            'oil_limit'                => $projection['oil_limit'],
            'allowed_max'              => $projection['allowed_max'],
            'expected_return_odometer' => $projection['expected_return'],
            'remaining_days'           => $projection['remaining_days'],
            'created_by'               => $actor?->id,
            'created_by_name'          => $actor?->name,
            'decided_at'               => $decision->created_at ?? Carbon::now(),
            'assigned_user_ids'        => $recipients->pluck('id')->all(),
            'note'                     => $note,
        ]);

        $vehicle = $contract->vehicle;
        $car     = trim(($vehicle?->plate_no ? $vehicle->plate_no . ' · ' : '')
                      . trim(($vehicle?->make ?? '') . ' ' . ($vehicle?->model ?? '')));
        $over    = $task->overToleranceKm() ?? 0;

        foreach ($recipients as $user) {
            app(NotificationScanner::class)->notifyUser($user, [
                'type'     => 'oil_recall_task',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Call the customer · arrange the car\'s return',
                'body'     => trim($car . ' is heading for ' . number_format((int) $task->expected_return_odometer)
                            . ' km, ' . number_format($over) . ' km past the '
                            . number_format((int) $task->allowed_max) . ' km allowance. Arrange to get it in.'),
                'url'      => '/oil-projection',
                'key'      => 'oil_recall_task:' . $task->id,
                'icon'     => 'oil',
                'meta'     => [
                    'task_id'     => $task->id,
                    'contract_id' => $contract->id,
                    'vehicle_id'  => $contract->vehicle_id,
                    'plate'       => $vehicle?->plate_no,
                    'over_km'     => $over,
                ],
            ]);
        }

        return $task;
    }

    /** Withdraw any outstanding recall call — the decision it served no longer stands. */
    public function cancelOpenRecallTasks(Contract $contract, string $why): int
    {
        return OilRecallTask::open()->where('contract_id', $contract->id)->get()
            ->each(fn (OilRecallTask $t) => $t->forceFill([
                'status'       => OilRecallTask::STATUS_CANCELLED,
                'outcome_note' => trim(($t->outcome_note ? $t->outcome_note . ' · ' : '') . $why),
                'completed_at' => Carbon::now(),
            ])->save())
            ->count();
    }

    /**
     * The two people who make these calls: the configured allow-list (Leen & Marwa), falling back to
     * holders of the follow-up permission when it is empty. Same audience doctrine as the chase — a
     * task handed to "everyone with a broad permission" is a task nobody owns.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    public function controllers(): \Illuminate\Support\Collection
    {
        $ids = array_values(array_filter((array) config('maintenance.oil_projection.recipient_user_ids', [])));

        $query = $ids
            ? User::whereIn('id', $ids)
            : User::permission('reminders.manage');

        return $query->where('status', 'active')->orderBy('id')->get();
    }

    /** The one-line "why" carried by the deferred-maintenance flag, in operational language. */
    private function flagNote(string $decision, array $projection): string
    {
        $over = max(0, (int) $projection['over_tolerance_km']);

        return $decision === ContractOilDecision::DECISION_RECALL
            ? 'Oil change — car recalled from rental; projected ' . number_format($projection['expected_return'])
              . ' km against a ' . number_format($projection['allowed_max']) . ' km allowance.'
            : 'Oil change owed on return — accepted running ' . number_format($over)
              . ' km past the ' . number_format($projection['allowed_max']) . ' km allowance.';
    }

    // ── Settlement: the car comes back ───────────────────────────────────────────────────────

    /**
     * The rental has closed — turn the owed oil change into a real ticket.
     *
     * This is the far end of every path above: a recall, an accepted overrun, and the quiet case
     * where nobody was ever asked because the car crossed its limit inside the tolerance. All three
     * mean the same job, so all three land here and mint the same routine-service ticket through
     * MaintenanceWorkflowService — the single write path for a scheduled service.
     *
     * Idempotent by construction: one settled ContractOilDecision per contract, and the row is
     * written even when no human ever decided (`is_auto`), which is what makes the sweep safe to
     * run on every tick.
     *
     * Returns the ticket, or null when nothing was owed / it could not be raised.
     */
    public function settleOnReturn(Contract $contract, ?User $actor = null): ?Maintenance
    {
        // Still out. Nothing to settle — the decision, if any, is still live.
        if ($contract->state !== 'closed' && ! $contract->in_date) {
            return null;
        }

        $vehicle = $contract->vehicle;
        if (! $vehicle) {
            return null;
        }

        // Already settled once. Never mint a second ticket for the same rental.
        if (ContractOilDecision::where('contract_id', $contract->id)->whereNotNull('settled_at')->exists()) {
            return null;
        }

        $decision = ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $oilLimit = $this->oilLimit($vehicle);

        // What the car actually came back on. The branch's return reading is a real capture (unlike
        // the customer's phone number), so it is preferred; a placeholder falls back to the last
        // projection we held.
        $returned = (int) ($contract->in_milage ?? 0);
        $finalKm  = $returned > self::PLACEHOLDER_MAX
            ? $returned
            : ($this->project($contract, $contract->in_date ? Carbon::parse($contract->in_date) : null)['expected'] ?? null);

        // THE ACTUAL MILEAGE DECIDES. The projection recommended, the Controller chose, but the
        // number the car actually came back on is the only thing that makes an oil change real:
        // a car that returns short of its oil limit gets NO forced change, whatever the model
        // predicted at decision time. A decision with a not-due return still settles (the
        // follow-up is resolved as "inspection only"); no decision + not due = nothing to do.
        $due = $oilLimit !== null && $finalKm !== null && $finalKm >= $oilLimit;
        if ($decision === null && ! $due) {
            return null;
        }

        $actor ??= $this->settleActor($decision);
        if (! $actor) {
            // No user to attribute the ticket to. Say so loudly rather than swallow the job — the
            // car is back, owing a service, and nothing was raised.
            Log::warning('Oil settle: no actor available to open the service ticket', [
                'contract_id' => $contract->id,
                'vehicle_id'  => $vehicle->id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($contract, $vehicle, $decision, $finalKm, $actor, $due, $oilLimit) {
            $ticket = null;
            if ($due) {
                try {
                    $ticket = app(MaintenanceWorkflowService::class)
                        ->openServiceTicket($vehicle, 'Oil Change', $finalKm, $actor);
                } catch (\Throwable $e) {
                    // A car that has left the fleet (sold / disposed) can't enter the workflow. That is a
                    // legitimate outcome, not a failure of this sweep.
                    Log::warning('Oil settle: could not open the service ticket', [
                        'contract_id' => $contract->id,
                        'vehicle_id'  => $vehicle->id,
                        'error'       => $e->getMessage(),
                    ]);

                    return null;
                }
            }

            $row = $decision ?: ContractOilDecision::create([
                'contract_id'              => $contract->id,
                'vehicle_id'               => $vehicle->id,
                // Nobody was asked: the car finished inside the tolerance, which is precisely the
                // case this flow is designed NOT to interrupt a rental for.
                'decision'                 => ContractOilDecision::DECISION_DEFER,
                'oil_limit'                => $this->oilLimit($vehicle),
                'allowed_max'              => $this->threshold($vehicle),
                'expected_return_odometer' => $finalKm,
                'is_auto'                  => true,
            ]);

            $row->forceFill(['settled_at' => Carbon::now(), 'settled_ticket_id' => $ticket?->id])->save();

            if ($ticket) {
                // The EXECUTION ticket carries the same story reference the request did — the oil
                // change is the action the inspection follow-up produced, never an orphan.
                $ticket->trigger_detail = [
                    'source'                   => 'oil_projection',
                    'contract_oil_decision_id' => $row->id,
                    'contract_id'              => $contract->id,
                    'contract_no'              => $contract->contract_no,
                    'decision'                 => $row->decision,
                    'inspection_request_id'    => $row->inspection_ticket_id,
                    'actual_return_km'         => $finalKm,
                ];
                $ticket->save();
            }

            // The car is back, so the "arrange the return" call has served its purpose. Closed as
            // DONE, not cancelled — the job was completed by the car arriving.
            $recallOutcome = $ticket
                ? 'Car returned; oil change raised as ticket #' . $ticket->id . '.'
                : 'Car returned inside its oil limit — no change needed.';
            OilRecallTask::open()->where('contract_id', $contract->id)->get()
                ->each(fn (OilRecallTask $t) => $t->forceFill([
                    'status'       => OilRecallTask::STATUS_DONE,
                    'outcome_note' => trim(($t->outcome_note ? $t->outcome_note . ' · ' : '') . $recallOutcome),
                    'completed_at' => Carbon::now(),
                ])->save());

            // The car came back on its own terms: stand down any collection run still out, and
            // resolve the announcing follow-up request with what the ACTUAL mileage proved.
            $this->cancelRecallCollections($contract, $actor,
                'Car returned — collection no longer needed.' . ($ticket ? ' Oil ticket #' . $ticket->id . ' raised.' : ''));
            $this->resolveInspectionFollowUp($contract, $ticket, $ticket
                ? 'Settled on return — oil service raised as ticket #' . $ticket->id
                    . ' (actual ' . number_format((int) $finalKm) . ' km).'
                : 'Resolved on return — oil NOT due (actual ' . number_format((int) $finalKm)
                    . ' km, limit ' . number_format((int) $oilLimit) . ' km). Inspection only.');

            // The debt is now a ticket; the standing flag has done its job.
            app(OperationsService::class)->resolveDeferredMaintenance($vehicle);

            // The notification follows the CURRENT state: the actual figures, not the projection.
            if ($ticket) {
                foreach ($this->controllers() as $user) {
                    app(NotificationScanner::class)->notifyUser($user, [
                        'type'     => 'oil_due_on_return',
                        'category' => 'maintenance',
                        'severity' => 'warning',
                        'title'    => 'Oil Change Required · ' . ($vehicle->plate_no ?: ('#' . $vehicle->id)),
                        'body'     => 'Returned on ' . number_format((int) $finalKm) . ' km — oil limit '
                                    . number_format((int) $oilLimit) . ' km. Ticket #' . $ticket->id . ' raised.',
                        'url'      => '/maintenance-workflow?ticket=' . $ticket->id,
                        'key'      => 'oil_settle:' . $contract->id,
                        'icon'     => 'wrench',
                        'meta'     => ['contract_id' => $contract->id, 'ticket_id' => $ticket->id],
                    ]);
                }
            }

            return $ticket;
        });
    }

    /**
     * Sweep every rental that has come back but never had its owed oil change raised.
     *
     * The explicit close path calls settleOnReturn() directly, but most contracts on the live fleet
     * are closed by the OfficeManager sync writing `in_date` — no application code runs there at
     * all. This sweep is what makes the promise hold either way.
     *
     * @return array{settled:int, tickets:array<int,int>}
     */
    public function settleReturnedRentals(int $limit = 200): array
    {
        $settled = 0;
        $tickets = [];

        // Rentals with an open (unsettled) decision, plus recently-returned rentals that may owe a
        // change nobody was ever asked about.
        Contract::query()
            ->where('contract_type', 'C')
            ->whereNotNull('vehicle_id')
            ->where(fn ($q) => $q->where('state', 'closed')->orWhereNotNull('in_date'))
            ->where(function ($q) {
                $q->whereIn('id', ContractOilDecision::whereNull('settled_at')->select('contract_id'))
                  // …or a recent return nobody was ever asked about. Bounded at BOTH ends on purpose:
                  //   • the 7-day window, because a rental that came back last month is water under
                  //     the bridge and raising an oil ticket for it now is noise, not diligence;
                  //   • `settle_from`, because without it the very first run would sweep up every car
                  //     that happened to return in the days before this flow existed and dump a pile
                  //     of tickets on the workshop for cars that have already been and gone.
                  ->orWhere(fn ($r) => $r->whereDate('in_date', '>=', $this->settleFloor()->toDateString()));
            })
            ->whereNotIn('id', ContractOilDecision::whereNotNull('settled_at')->select('contract_id'))
            ->with('vehicle')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (Contract $c) use (&$settled, &$tickets) {
                $ticket = $this->settleOnReturn($c);
                if ($ticket) {
                    $settled++;
                    $tickets[$c->id] = $ticket->id;
                }
            });

        return ['settled' => $settled, 'tickets' => $tickets];
    }

    /**
     * How far back the sweep may reach for a return NOBODY decided about: the later of the 7-day
     * window and the configured go-live date. A rental that carries an explicit decision is settled
     * whatever its date — that call was taken under this flow and is owed either way.
     */
    private function settleFloor(): Carbon
    {
        $window = Carbon::now()->subDays(7)->startOfDay();
        $from   = config('maintenance.oil_projection.settle_from');

        if (! $from) {
            return $window;
        }

        $configured = Carbon::parse($from)->startOfDay();

        return $configured->gt($window) ? $configured : $window;
    }

    /**
     * Who the auto-raised ticket is attributed to: the person who took the decision, else one of
     * the configured follow-up controllers (Leen / Marwa), else nobody — and we decline rather
     * than attribute a real workshop ticket to an arbitrary account.
     */
    private function settleActor(?ContractOilDecision $decision): ?User
    {
        if ($decision?->decided_by && ($user = User::find($decision->decided_by))) {
            return $user;
        }

        $ids = (array) config('maintenance.oil_projection.recipient_user_ids', []);

        return $ids ? User::whereIn('id', $ids)->orderBy('id')->first() : null;
    }
}
