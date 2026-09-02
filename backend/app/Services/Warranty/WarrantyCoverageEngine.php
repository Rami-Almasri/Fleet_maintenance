<?php

namespace App\Services\Warranty;

use App\Models\Vehicle;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Support\WarrantyCoverage;
use Illuminate\Support\Collection;

/**
 * "Before we spend money on this car, could the manufacturer, the dealer or the supplier be
 * responsible for it?" — the single place that question is answered.
 *
 * Evidence class: D (derived) — reads facts, writes nothing. Produces: a CoverageAssessment.
 * Consumes: warranties, warranty_claims (recorded decisions), vehicles.odometer.
 *
 * ── THE ENGINE DECIDES FROM DATA OR IT DEFERS TO A PERSON. THERE IS NO THIRD MODE. ──────────────
 *
 * Every COVERED and every NOT_COVERED below comes from one of exactly two things: a set-membership
 * test against a warranty's itemised cover, or a decision a named human already recorded. There is
 * no keyword matching, no similarity, no confidence, no "probably". When neither applies the answer
 * is UNKNOWN, and UNKNOWN is not a failure — it is the engine correctly declining to invent a fact,
 * and routing the question to somebody who can establish one.
 *
 * ── THE ORDER OF PRECEDENCE, AND WHY IT IS THIS ORDER ──────────────────────────────────────────
 *
 *  1. A HUMAN ALREADY ANSWERED THIS. A recorded coverage decision for this car and part type beats
 *     every rule below it, including the rules that would contradict it. Somebody read the actual
 *     contract; the engine has read a list of ids. If the recorded answer is wrong, it is corrected
 *     by recording a new one — not by having the engine quietly overrule it.
 *
 *  2. THE QUESTION IS ALREADY BEING ASKED. An open review for this subject returns UNKNOWN and
 *     points at the existing case, so a second purchase request does not open a second review and
 *     two people do not ring the same dealer about the same gearbox.
 *
 *  3. THE GARAGE STILL OWES US THIS REPAIR. A live repair warranty on the exact fault is the
 *     strongest and cheapest form of cover in this fleet — no dealer, no shipping, the car goes back
 *     to the shop that did it. Checked before the manufacturer for that reason.
 *
 *  4. THE PART'S OWN WARRANTY. A battery fitted in January under 12-month supplier cover is covered
 *     even on a car whose manufacturer warranty ran out last spring. Ask only the car and you buy a
 *     battery somebody else owed you.
 *
 *  5. WHAT THE VEHICLE WARRANTY SAYS, ITEM BY ITEM. Explicit inclusion → covered. Explicit exclusion
 *     with nothing else covering it → not covered.
 *
 *  6. EVERYTHING ELSE IS UNKNOWN. A live warranty nobody has itemised says nothing about a wheel
 *     bearing, and this is the ordinary state of the fleet on day one.
 *
 * ── ONE ASYMMETRY, DELIBERATE ──────────────────────────────────────────────────────────────────
 *
 * Across several warranties, a single COVERED beats any number of exclusions: warranty A excluding
 * the gearbox does not un-cover it when warranty B names it. An exclusion only wins when NOTHING
 * else covers the part — because the cost of checking with a dealer who then says no is a phone
 * call, and the cost of buying a gearbox the manufacturer owed us is a gearbox.
 */
class WarrantyCoverageEngine
{
    /**
     * Load everything relevant to one car and decide.
     *
     * @param Vehicle $vehicle the car — its odometer is what judges every distance leg
     */
    public function assess(Vehicle $vehicle, CoverageSubject $subject): CoverageAssessment
    {
        $warranties = Warranty::query()
            ->forVehicle($vehicle->id)
            ->where('status', Warranty::STATUS_ACTIVE)
            // Narrow on the DATE leg in SQL, then judge properly in PHP — the distance leg needs the
            // car's odometer and cannot be range-scanned. Same discipline as the register's listing:
            // scopes narrow, evaluate() decides.
            ->notTimeExpired()
            ->with(['catalog:id,name,name_ar', 'provider:id,name'])
            ->get();

        $cases = WarrantyClaim::query()
            ->forVehicle($vehicle->id)
            ->when($subject->catalogId, fn ($q) => $q->where('component_catalog_id', $subject->catalogId))
            ->orderByDesc('id')
            ->get();

        return $this->decide(
            $warranties,
            $cases,
            $subject,
            $vehicle->odometer !== null ? (int) $vehicle->odometer : null,
        );
    }

    /**
     * The rule itself. PURE — no database, no clock beyond "today", no side effects.
     *
     * Everything above this method is a loader. Keeping the decision pure is what makes it possible
     * to pin all sixteen awkward combinations (live vehicle cover + expired part cover + a recorded
     * decision that contradicts both) in a unit test with no fixtures, which is the only way a rule
     * this consequential stays correct while people edit it.
     *
     * @param Collection<int,Warranty>     $warranties  ACTIVE-status warranties on this car
     * @param Collection<int,WarrantyClaim> $cases      this car's cases, newest first
     * @param int|null $odometer the reading that judges every distance leg; null makes them unjudgeable
     */
    public function decide(
        Collection $warranties,
        Collection $cases,
        CoverageSubject $subject,
        ?int $odometer,
    ): CoverageAssessment {
        // ── 1 · A human already answered this exact question ───────────────────────────────────
        //
        // Only counts when the subject is IDENTIFIED: a decision recorded against "the gearbox" says
        // nothing about a request that never named a part type, and matching them would be exactly
        // the guessing this engine refuses to do.
        if ($subject->isIdentified()) {
            $decided = $cases->first(fn (WarrantyClaim $c) => $c->coverage_verdict !== null
                && (int) $c->component_catalog_id === $subject->catalogId);

            if ($decided && in_array($decided->coverage_verdict, [WarrantyCoverage::COVERED, WarrantyCoverage::NOT_COVERED], true)) {
                return new CoverageAssessment(
                    verdict: $decided->coverage_verdict,
                    reasonCode: WarrantyCoverage::R_DECIDED_BY_REVIEW,
                    reasonParams: [
                        'case_id'    => $decided->id,
                        'decided_by' => $decided->decided_by_name,
                        'decided_at' => $decided->decided_at?->toDateString(),
                        'because'    => $decided->coverage_reason_code,
                    ],
                    candidates: $this->liveOnes($warranties, $odometer)->values()->all(),
                    // Resolved out of the rows we ALREADY hold, never lazy-loaded off the case. Two
                    // reasons, and the second is the one that matters: a lazy load here would fire a
                    // query per assessment on the hot procurement path, and it would make this
                    // method impure — which is what lets the whole rule be tested without fixtures.
                    decisive: $warranties->firstWhere('id', $decided->warranty_id),
                    existingCase: $decided,
                );
            }
        }

        // ── 2 · The question is already open with somebody ─────────────────────────────────────
        $open = $cases->first(fn (WarrantyClaim $c) => $c->isOpen()
            && ($subject->catalogId === null || (int) $c->component_catalog_id === $subject->catalogId));

        if ($open && $open->stage === WarrantyClaim::STAGE_COVERAGE_REVIEW) {
            return new CoverageAssessment(
                verdict: WarrantyCoverage::UNKNOWN,
                reasonCode: WarrantyCoverage::R_REVIEW_IN_PROGRESS,
                reasonParams: ['case_id' => $open->id, 'since' => $open->created_at?->toDateString()],
                candidates: $this->liveOnes($warranties, $odometer)->values()->all(),
                existingCase: $open,
            );
        }

        // A case that is PAST review and still open has already been judged covered — the dealer leg
        // is running. Nothing may be bought against it while that is true.
        if ($open && $open->blocksProcurement()) {
            return new CoverageAssessment(
                verdict: WarrantyCoverage::COVERED,
                reasonCode: WarrantyCoverage::R_DECIDED_BY_REVIEW,
                reasonParams: ['case_id' => $open->id, 'stage' => $open->stage],
                candidates: $this->liveOnes($warranties, $odometer)->values()->all(),
                decisive: $warranties->firstWhere('id', $open->warranty_id),   // see above: never lazy-load here
                existingCase: $open,
            );
        }

        // ── Judge every promise once, here, and reason over the results ────────────────────────
        $judged = $warranties->map(fn (Warranty $w) => ['w' => $w, 'v' => $w->evaluate(null, $odometer)]);
        $live   = $judged->filter(fn ($j) => $j['v']['state'] === Warranty::STATE_ACTIVE);

        if ($live->isEmpty()) {
            // Nothing live. Distinguish "there was cover and it ran out" from "there never was any",
            // because the two lead to different conversations: one is a lesson about timing, the
            // other is a missing warranty booklet somebody should go and find.
            return CoverageAssessment::notCovered(
                $judged->isEmpty() ? WarrantyCoverage::R_NO_LIVE_COVER : WarrantyCoverage::R_COVER_EXPIRED,
                $judged->isEmpty() ? [] : ['ended_by' => $judged->first()['v']['ended_by']],
            );
        }

        $candidates = $live->map(fn ($j) => $j['w'])->values()->all();

        // ── 3 · The garage still owes us this exact repair ─────────────────────────────────────
        if ($subject->taskId !== null) {
            $repair = $live->first(fn ($j) => $j['w']->kind === Warranty::KIND_REPAIR
                && (int) $j['w']->maintenance_task_id === $subject->taskId);

            if ($repair) {
                return new CoverageAssessment(
                    WarrantyCoverage::COVERED,
                    WarrantyCoverage::R_REPAIR_COVER_LIVE,
                    ['provider' => $repair['w']->provider_name, 'evidence' => $repair['v']['evidence']],
                    $candidates,
                    $repair['w'],
                );
            }
        }

        // ── 4 · The part's own promise ─────────────────────────────────────────────────────────
        //
        // Matched on the fitted component first (this exact physical thing), then on the part type
        // (a part warranty recorded against the catalog entry without an asset row behind it).
        $component = $live->first(function ($j) use ($subject) {
            if ($j['w']->kind !== Warranty::KIND_PART) {
                return false;
            }
            if ($subject->componentId !== null && (int) $j['w']->vehicle_component_id === $subject->componentId) {
                return true;
            }

            return $subject->catalogId !== null && (int) $j['w']->component_catalog_id === $subject->catalogId;
        });

        if ($component) {
            return new CoverageAssessment(
                WarrantyCoverage::COVERED,
                WarrantyCoverage::R_COMPONENT_COVER_LIVE,
                [
                    'subject'  => $component['w']->subject,
                    'provider' => $component['w']->provider_name,
                    'evidence' => $component['v']['evidence'],
                ],
                $candidates,
                $component['w'],
            );
        }

        // ── 5 · What the live warranties say, item by item ─────────────────────────────────────
        //
        // A single inclusion beats any number of exclusions — see the asymmetry note in the header.
        $itemised = $live->map(fn ($j) => ['w' => $j['w'], 'says' => $j['w']->coversCatalog($subject->catalogId)]);

        if ($includes = $itemised->first(fn ($i) => $i['says'] === true)) {
            return new CoverageAssessment(
                WarrantyCoverage::COVERED,
                WarrantyCoverage::R_EXPLICITLY_COVERED,
                ['warranty' => $includes['w']->subject, 'provider' => $includes['w']->provider_name],
                $candidates,
                $includes['w'],
            );
        }

        if ($excludes = $itemised->first(fn ($i) => $i['says'] === false)) {
            return new CoverageAssessment(
                WarrantyCoverage::NOT_COVERED,
                WarrantyCoverage::R_EXPLICITLY_EXCLUDED,
                ['warranty' => $excludes['w']->subject, 'provider' => $excludes['w']->provider_name],
                $candidates,
                $excludes['w'],
            );
        }

        // ── 6 · Nobody has said anything about this part ───────────────────────────────────────
        //
        // Two shades of silence, and they are genuinely different answers:

        // (a) A distance-bounded promise judged with no odometer. "Live" above meant only "not out
        //     of time", so we do not know whether cover exists at all. UNKNOWN, and the review card
        //     will say the missing thing is a reading — not a contract.
        if ($live->contains(fn ($j) => $j['v']['distance_unknown'])) {
            return new CoverageAssessment(
                WarrantyCoverage::UNKNOWN,
                WarrantyCoverage::R_ODOMETER_UNKNOWN,
                ['part' => $subject->describe()],
                $candidates,
            );
        }

        // (b) Every live promise HAS been itemised, and this part is on none of the lists. Somebody
        //     has done the work of reading the booklets; their reading is the fact. NOT_COVERED.
        if ($subject->isIdentified() && $live->every(fn ($j) => $j['w']->isItemised())) {
            return CoverageAssessment::notCovered(
                WarrantyCoverage::R_NOT_IN_ITEMISED_COVER,
                ['part' => $subject->describe()],
                $candidates,
            );
        }

        // (c) The ordinary case on day one: a live warranty nobody has itemised. UNKNOWN — which is
        //     the honest answer, and the one that puts a person in front of the decision.
        return new CoverageAssessment(
            WarrantyCoverage::UNKNOWN,
            WarrantyCoverage::R_COVER_NOT_ITEMISED,
            ['part' => $subject->describe(), 'cover_count' => count($candidates)],
            $candidates,
        );
    }

    /** The promises that are live right now, for the candidate list on a review card. */
    private function liveOnes(Collection $warranties, ?int $odometer): Collection
    {
        return $warranties->filter(fn (Warranty $w) => $w->isLive($odometer));
    }
}
