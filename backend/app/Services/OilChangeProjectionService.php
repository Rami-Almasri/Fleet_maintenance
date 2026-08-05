<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractMileageReading;
use App\Models\ContractOilDecision;
use App\Models\Maintenance;
use App\Models\OilRecallTask;
use App\Models\User;
use App\Models\Vehicle;
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
     *   return_date_known:bool, expected_return:?int, over_tolerance_km:?int, decision:?array
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
        ];

        $vehicle = $contract->vehicle;
        if (! $vehicle) {
            return $base;
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
                                && $daysElapsed <= self::DECISION_FRESH_DAYS,
        ];
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
    public function decide(Contract $contract, string $decision, ?User $actor = null, ?string $note = null): ContractOilDecision
    {
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

        // The gate. `recall_required` is included so a recall can be revised to a defer.
        $decidable = [self::OIL_DECISION_REQUIRED, self::OIL_RECALL_REQUIRED];
        if (! in_array($projection['oil_status'], $decidable, true)) {
            throw ValidationException::withMessages([
                'decision' => 'This car is projected to finish inside the '
                            . number_format($projection['allowed_max']) . ' km allowance, so there is nothing to decide'
                            . ' — the oil change is already booked for when it comes back.',
            ]);
        }

        return DB::transaction(function () use ($contract, $decision, $actor, $note, $projection) {
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

            // Whatever was chosen, the car owes an oil change on return. Reuse the standing flag the
            // rest of the fleet already reads as "route this car to the garage when it lands".
            if ($contract->vehicle) {
                app(OperationsService::class)->flagDeferredMaintenance(
                    $contract->vehicle,
                    $this->flagNote($decision, $projection),
                    $actor?->name,
                );
            }

            // A recall is a CONVERSATION, so it produces a task for the Controllers to have it —
            // nothing more. Deferring is not a task: the rental simply runs its course.
            if ($decision === ContractOilDecision::DECISION_RECALL) {
                $this->openRecallTask($row, $contract, $projection, $actor, $note);
            } else {
                $this->cancelOpenRecallTasks($contract, 'Revised to "do it on return".');
            }

            return $row;
        });
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

        // Owed when somebody decided it was, or when the car simply came back past its oil point.
        $owed = $decision !== null || ($oilLimit !== null && $finalKm !== null && $finalKm >= $oilLimit);
        if (! $owed) {
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

        return DB::transaction(function () use ($contract, $vehicle, $decision, $finalKm, $actor) {
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

            $row->forceFill(['settled_at' => Carbon::now(), 'settled_ticket_id' => $ticket->id])->save();

            // The car is back, so the "arrange the return" call has served its purpose. Closed as
            // DONE, not cancelled — the job was completed by the car arriving.
            OilRecallTask::open()->where('contract_id', $contract->id)->get()
                ->each(fn (OilRecallTask $t) => $t->forceFill([
                    'status'       => OilRecallTask::STATUS_DONE,
                    'outcome_note' => trim(($t->outcome_note ? $t->outcome_note . ' · ' : '')
                                    . 'Car returned; oil change raised as ticket #' . $ticket->id . '.'),
                    'completed_at' => Carbon::now(),
                ])->save());

            // The debt is now a ticket; the standing flag has done its job.
            app(OperationsService::class)->resolveDeferredMaintenance($vehicle);

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
