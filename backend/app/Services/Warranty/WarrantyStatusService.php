<?php

namespace App\Services\Warranty;

use App\Models\Vehicle;
use App\Models\Warranty;
use App\Support\WarrantyCoverage;
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
     * The car's headline warranty state, from the WHOLE-CAR promises only.
     *
     * kind=vehicle only, on purpose. A car whose only live warranty is the 12-month cover on a tyre
     * fitted last week is not "under warranty" in any sense an operator means by the phrase, and a
     * badge that said so would be worse than no badge — it would route people to a dealer who has
     * never heard of the car. Part and repair cover is real and is answered by the coverage engine,
     * where it belongs: at the level of a specific part, not of the whole vehicle.
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
                'state' => WarrantyCoverage::STATE_NONE,
                'live_count' => 0, 'total_count' => 0,
                'headline' => null, 'expiring_soon' => false,
                'days_remaining' => null, 'km_remaining' => null,
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
            return [
                'state' => WarrantyCoverage::STATE_EXPIRED,
                'live_count' => 0, 'total_count' => $judged->count(),
                // The most recently ended one is what somebody asking "when did we lose cover?" wants.
                'headline' => $this->present($judged->sortByDesc(fn ($j) => $j['warranty']->expires_on)->first()),
                'expiring_soon' => false,
                'days_remaining' => null, 'km_remaining' => null,
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
            'state' => $allExpiringSoon ? WarrantyCoverage::STATE_EXPIRING_SOON : WarrantyCoverage::STATE_UNDER_WARRANTY,
            'live_count'  => $live->count(),
            'total_count' => $judged->count(),
            'headline'    => $this->present($best),
            'expiring_soon' => $live->contains(fn ($j) => $j['verdict']['expiring_soon'] === true),
            'days_remaining' => $best['verdict']['days_remaining'],
            'km_remaining'   => $best['verdict']['km_remaining'],
            // True when ANY live warranty has an unjudgeable distance leg. Surfaced, never smoothed
            // over: "active" on such a row means "not out of time" and nothing more.
            'distance_unknown' => $live->contains(fn ($j) => $j['verdict']['distance_unknown']),
        ];
    }

    /**
     * Everything covering one car, whatever the kind, each judged against the car's current reading.
     *
     * This is the vehicle page's payload: the whole-car cover AND the part/repair promises, because
     * on the page a battery under its own 12-month supplier warranty is exactly as relevant as the
     * manufacturer's cover — arguably more so, since it is the one people forget.
     *
     * @return array{state:array, warranties:array<int,array>}
     */
    public function fullPicture(Vehicle $vehicle): array
    {
        $all      = $vehicle->warranties()->with(['catalog:id,name,name_ar', 'provider:id,name'])->get();
        $odometer = $vehicle->odometer !== null ? (int) $vehicle->odometer : null;

        return [
            'state' => $this->summarise($all->where('kind', Warranty::KIND_VEHICLE), $odometer),
            'warranties' => $all->map(fn (Warranty $w) => [
                'warranty' => $w,
                'verdict'  => $w->evaluate(null, $odometer),
            ])->values()->all(),
        ];
    }

    /** The headline, flattened to what a badge needs — never the whole model. */
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
