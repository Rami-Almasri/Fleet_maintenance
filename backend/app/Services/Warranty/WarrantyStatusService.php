<?php

namespace App\Services\Warranty;

use App\Models\Vehicle;
use App\Models\Warranty;
use Illuminate\Support\Collection;

/**
 * "Is this car under warranty, and how much of it is left?" — answered once, for every surface.
 *
 * Evidence class: D (derived). Produces: nothing persisted. Consumes: warranties, vehicles.odometer.
 *
 * ── WHY THIS IS NOT A COLUMN ────────────────────────────────────────────────────────────────────
 *
 * The obvious implementation is `vehicles.warranty_status`, refreshed nightly. It is also wrong, and
 * wrong in a way that only shows up on the cars that matter most. A warranty bounded by distance
 * ends when the car reaches a number, and this fleet's cars reach numbers between one nightly job
 * and the next: at 6,000 km a month, a car crosses a 20,000 km warranty boundary in the middle of a
 * Tuesday. A stored status would say UNDER WARRANTY while somebody spent our money on a claim that
 * had already lapsed — or, worse, would say EXPIRED and stop us claiming something we could still
 * have claimed. So the state is computed on read, from {@see Warranty::evaluate()}, against the
 * odometer as it stands at the moment of asking.
 *
 * ── THE ROLL-UP RULE, AND WHY "EXPIRING SOON" WINS ─────────────────────────────────────────────
 *
 * A car has a SET of promises, not one. The badge has to reduce that set to a word:
 *
 *   UNDER_WARRANTY  at least one is live with comfortable room left on both legs
 *   EXPIRING_SOON   at least one is live, but everything still live is near its end
 *   EXPIRED         there were promises and they have all run out
 *   NONE            no promise was ever recorded. NOT the same as EXPIRED, and the difference is
 *                   operational: EXPIRED means we know the answer, NONE means nobody has looked.
 *                   Collapsing them would hide every car whose warranty booklet is still in the
 *                   glovebox behind a badge that says the cover is gone.
 *
 * EXPIRING_SOON deliberately outranks UNDER_WARRANTY when it is the more urgent truth, but only when
 * NOTHING live is comfortable: a car with a 5-year powertrain warranty and a 3-year bumper-to-bumper
 * expiring next month reads UNDER_WARRANTY, because it is — the badge is not a to-do list. What
 * makes the expiring cover actionable is the pre-expiry inspection and the alert, not the badge.
 *
 * ── UNKNOWN DISTANCE IS REPORTED, NEVER ASSUMED ────────────────────────────────────────────────
 *
 * A km-bounded warranty judged with no odometer is flagged (`distance_unknown`) and its "active"
 * means only "not out of time". Every caller gets told; none of them is allowed to quietly round it
 * up to cover. Same discipline as the model one layer down.
 */
class WarrantyStatusService
{
    /**
     * The four words the car's badge can say. Constants here, not in a support class: this service
     * is the only thing that decides them, and a vocabulary with one reader belongs beside it.
     *
     * NONE is not EXPIRED. "We know the cover ended" and "nobody has recorded any cover" are
     * different operational facts — collapsing them would hide every car whose booklet is still in
     * the glovebox behind a badge that says the cover is gone.
     */
    public const STATE_UNDER_WARRANTY = 'under_warranty';
    public const STATE_EXPIRING_SOON  = 'expiring_soon';
    public const STATE_EXPIRED        = 'expired';
    public const STATE_NONE           = 'none';

    /**
     * The car's headline warranty state, from the WHOLE-CAR promises only.
     *
     * kind=vehicle only, on purpose. A car whose only live warranty is the 12-month cover on a tyre
     * fitted last week is not "under warranty" in any sense an operator means by the phrase, and a
     * badge that said so would be worse than no badge — it would route people to a dealer who has
     * never heard of the car. Part and repair warranties are still real and still listed on the car's
     * page; they simply are not what "this vehicle is under warranty" means.
     *
     * @return array{state:string, live_count:int, total_count:int, headline:?array, expiring_soon:bool,
     *               days_remaining:?int, km_remaining:?int, distance_unknown:bool}
     */
    public function vehicleState(Vehicle $vehicle, ?Collection $warranties = null): array
    {
        // Prefer what the caller handed us, then an already-eager-loaded relation (the vehicle LIST
        // loads `warranties` once for the whole page — querying per row here would turn one badge
        // into four hundred queries), and only query as a last resort.
        $rows = $warranties
            ?? ($vehicle->relationLoaded('warranties') ? $vehicle->warranties : null)
            ?? $vehicle->vehicleWarranties()->get();

        return $this->summarise(
            collect($rows)->where('kind', Warranty::KIND_VEHICLE)->values(),
            $vehicle->odometer !== null ? (int) $vehicle->odometer : null
        );
    }

    /**
     * "Is this car covered right now, and by whom?" — the flat answer the maintenance cycle needs.
     *
     * Returns null when the car has no live whole-car cover, which is the ordinary case for most of
     * the fleet. A null is what makes the banner simply not render; the caller never has to reason
     * about an empty state.
     *
     * Judged against the car's CURRENT odometer, because cover ends on months or kilometres,
     * whichever comes first — a car doing 6,000 km a month can be out of cover while its date still
     * looks healthy, and sending that one to the dealer wastes everybody's week.
     *
     * @return array{warranty_id:int, provider:?string, provider_kind:?string, contact_phone:?string,
     *               expires_on:?string, days_remaining:?int, km_remaining:?int, expiring_soon:bool}|null
     */
    public function activeCoverFor(Vehicle $vehicle): ?array
    {
        $odometer = $vehicle->odometer !== null ? (int) $vehicle->odometer : null;

        $rows = $vehicle->relationLoaded('warranties')
            ? $vehicle->warranties->where('kind', Warranty::KIND_VEHICLE)
            : $vehicle->vehicleWarranties()->get();

        $live = collect($rows)
            ->map(fn (Warranty $w) => ['w' => $w, 'v' => $w->evaluate(null, $odometer)])
            ->filter(fn ($j) => $j['v']['state'] === Warranty::STATE_ACTIVE)
            // The longest-lasting one, for the same reason the badge quotes it: "until when?" is the
            // question being answered, and the answer is the furthest date we are protected to.
            ->sortByDesc(fn ($j) => $j['v']['days_remaining'] ?? -1)
            ->first();

        if (! $live) {
            return null;
        }

        /** @var Warranty $w */
        $w = $live['w'];

        return [
            'warranty_id'    => $w->id,
            'provider'       => $w->provider_name,
            'provider_kind'  => $w->provider_kind,
            'contact_phone'  => $w->contact_phone,
            'expires_on'     => $w->expires_on?->toDateString(),
            'days_remaining' => $live['v']['days_remaining'],
            'km_remaining'   => $live['v']['km_remaining'],
            'expiring_soon'  => (bool) $live['v']['expiring_soon'],
        ];
    }

    /**
     * Reduce a set of promises to one state, plus the best remaining figures across it.
     *
     * PURE — takes rows and a reading, touches no database, so the rule can be tested against every
     * awkward combination (live + expired, time-only, distance-only, unknown odometer) without a
     * fixture. Everything else in this class is a loader in front of it.
     *
     * @param  Collection<int,Warranty> $warranties
     * @param  int|null $odometer the car's reading; null makes every distance leg unjudgeable
     */
    public function summarise(Collection $warranties, ?int $odometer): array
    {
        if ($warranties->isEmpty()) {
            return [
                'state' => self::STATE_NONE,
                'live_count' => 0, 'total_count' => 0,
                'headline' => null, 'expiring_soon' => false,
                'days_remaining' => null, 'km_remaining' => null,
                // Present as nulls so every caller gets one shape and never has to tell "no cover"
                // apart from "the backend forgot the key".
                'km_over' => null, 'days_over' => null,
                'distance_unknown' => false,
            ];
        }

        $judged = $warranties->map(fn (Warranty $w) => [
            'warranty' => $w,
            'verdict'  => $w->evaluate(null, $odometer),
        ]);

        $live = $judged->filter(fn ($j) => $j['verdict']['state'] === Warranty::STATE_ACTIVE);

        if ($live->isEmpty()) {
            // Everything ran out (or was voided). A real, knowable answer — distinct from NONE.
            // The most recently ended one is what somebody asking "when did we lose cover?" wants.
            $last = $judged->sortByDesc(fn ($j) => $j['warranty']->expires_on)->first();

            return [
                'state' => self::STATE_EXPIRED,
                'live_count' => 0, 'total_count' => $judged->count(),
                'headline' => $this->present($last),
                'expiring_soon' => false,
                'days_remaining' => null, 'km_remaining' => null,
                /**
                 * HOW FAR PAST the limit the car already is, carried up to the roll-up so the vehicle
                 * LIST can say it without loading each warranty. "Expired" on its own cannot tell a
                 * car that went over last week — still worth a call to the dealer — from one that
                 * went over two years ago, and the fleet's own report prints exactly this number.
                 */
                'km_over'   => $last['verdict']['km_over'] ?? null,
                'days_over' => $last['verdict']['days_over'] ?? null,
                'distance_unknown' => $judged->contains(fn ($j) => $j['verdict']['distance_unknown']),
            ];
        }

        /**
         * The headline is the LONGEST-LASTING live promise, not the first or the newest: it is the
         * one that answers "how long are we protected for?", which is the question the badge is
         * standing in for. Sorted on days first and km second, with nulls (an unlimited or
         * unjudgeable leg) sorting last so a warranty with no end date cannot outrank a real one
         * merely by being unmeasurable.
         */
        $best = $live->sortByDesc(fn ($j) => $j['verdict']['days_remaining'] ?? -1)
            ->sortByDesc(fn ($j) => $j['verdict']['km_remaining'] ?? -1)
            ->first();

        // Only when EVERYTHING still live is near its end does the car read as expiring — see the
        // class note on why a 5-year powertrain warranty keeps the badge green.
        $allExpiringSoon = $live->every(fn ($j) => $j['verdict']['expiring_soon'] === true);

        return [
            'state' => $allExpiringSoon ? self::STATE_EXPIRING_SOON : self::STATE_UNDER_WARRANTY,
            'live_count'  => $live->count(),
            'total_count' => $judged->count(),
            'headline'    => $this->present($best),
            'expiring_soon' => $live->contains(fn ($j) => $j['verdict']['expiring_soon'] === true),
            'days_remaining' => $best['verdict']['days_remaining'],
            'km_remaining'   => $best['verdict']['km_remaining'],
            // Null while cover is live — a warranty is on one side of its limit or the other, and
            // shipping both would invite a caller to subtract them.
            'km_over'        => null,
            'days_over'      => null,
            // True when ANY live warranty has an unjudgeable distance leg. Surfaced, never smoothed
            // over: "active" on such a row means "not out of time" and nothing more.
            'distance_unknown' => $live->contains(fn ($j) => $j['verdict']['distance_unknown']),
        ];
    }

    /**
     * The headline, flattened to what a badge needs — never the whole model.
     */
    private function present(?array $judged): ?array
    {
        if (! $judged) {
            return null;
        }

        /** @var Warranty $w */
        $w = $judged['warranty'];

        return [
            'id'            => $w->id,
            'kind'          => $w->kind,
            'subject'       => $w->subject,
            'provider_kind' => $w->provider_kind,
            'provider_name' => $w->provider_name,
            'starts_on'     => $w->starts_on?->toDateString(),
            'expires_on'    => $w->expires_on?->toDateString(),
            'expires_at_km' => $w->expires_at_km,
            'verdict'       => $judged['verdict'],
        ];
    }
}
