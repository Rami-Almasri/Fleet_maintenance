<?php

namespace App\Services\Warranty;

use App\Models\Vehicle;
use App\Models\VehicleCheckRequirement;
use App\Models\Warranty;
use App\Services\VehicleCheckService;
use Illuminate\Support\Collection;

/**
 * Look at the car while somebody else is still paying for it.
 *
 * Evidence class: D (derived) → F (the obligation it raises). Produces: vehicle_check_requirements.
 * Consumes: warranties, vehicles.odometer.
 *
 * ── THE ASYMMETRY THIS EXISTS TO EXPLOIT ───────────────────────────────────────────────────────
 *
 * A worn wheel bearing found the week before cover ends is the manufacturer's bill. The same bearing,
 * on the same car, found the week after is ours — and nothing about the car changed in between. The
 * only variable is when somebody looked. Every fleet loses money to this and almost none of them
 * notice, because the loss is invisible: it arrives as an ordinary repair invoice with no note
 * saying "this was claimable eleven days ago".
 *
 * So the system asks, before the window shuts, on every car that has one.
 *
 * ── WHY A CHECK REQUIREMENT AND NOT A REMINDER ─────────────────────────────────────────────────
 *
 * A reminder is a note; an obligation is a question a named human must answer, and the difference is
 * the whole point. This is asked as a [[VehicleCheckRequirement]], which means it inherits four
 * behaviours that would otherwise all have to be rebuilt here:
 *
 *   · it ATTACHES to the next inspection that looks at the car, so it reaches whoever opens the
 *     bonnet next rather than only the person who happened to be on shift the morning it was raised;
 *   · it cannot be raised twice — the cycle key IS the warranty id, so the daily sweep re-running
 *     every morning for thirty days produces exactly one obligation;
 *   · an answer of "looked, nothing found" RESOLVES it and creates no fault, which matters more here
 *     than anywhere else in the catalog (see below);
 *   · a finding becomes a real MaintenanceTask through the existing pipeline, at which point the
 *     coverage engine sees a fault on a car with live cover and the warranty case opens itself.
 *
 * ── A CLEAN RESULT IS A SUCCESS ────────────────────────────────────────────────────────────────
 *
 * The temptation is to treat a warranty inspection that finds nothing as a wasted trip, and to build
 * the check so it produces *something*. That would be the same fake-fault behaviour the check catalog
 * was built to abolish. "We looked before cover ended and there was nothing" is a genuinely valuable
 * answer: it is what makes the expiry defensible, and it is recorded as such.
 */
class WarrantyExpiryInspectionService
{
    public function __construct(private VehicleCheckService $checks) {}

    /**
     * Ask, on every car whose cover is about to end.
     *
     * @param  int|null $leadDays how far ahead to look; defaults to config. Deliberately SHORTER than
     *                  the expiring-soon alert horizon: the alert is a heads-up, this is a job
     *                  somebody has to schedule, and asking two months out means it sits unanswered
     *                  until it is urgent.
     * @return array{scanned:int, raised:int, skipped:int, requirements:array<int,int>}
     */
    public function sweep(?int $leadDays = null): array
    {
        $leadDays ??= (int) config('warranty.inspection_lead_days', 30);

        $candidates = $this->candidates($leadDays);
        $raised = [];
        $skipped = 0;

        foreach ($candidates as $row) {
            /** @var Warranty $warranty */
            $warranty = $row['warranty'];
            /** @var Vehicle $vehicle */
            $vehicle = $warranty->vehicle;

            if (! $vehicle) {
                $skipped++;
                continue;
            }

            $requirement = $this->checks->raise($vehicle, [
                'check_type' => 'warranty_expiry',
                // One obligation per warranty, not per car: a vehicle under both a 3-year
                // bumper-to-bumper and a 5-year powertrain has two windows closing on two different
                // dates, and one shared check would silently skip the second.
                'check_key'  => 'warranty_expiry:' . $warranty->id,
                'rule_key'   => 'warranty_expiry',
                'source'     => VehicleCheckRequirement::SOURCE_MONITOR,
                'severity'   => 'moderate',
                // THE IDEMPOTENCY ANCHOR. The daily sweep re-runs for thirty consecutive mornings;
                // because the cycle is the warranty itself, all thirty runs resolve to the same row.
                'cycle_key'  => 'warranty:' . $warranty->id,
                // A CODE, never a sentence — the UI composes the wording, in either language.
                // [[reason-code-contract]]
                'reason_code'   => 'check.warranty_expiring',
                'reason_params' => [
                    'warranty_id'    => $warranty->id,
                    'provider'       => $warranty->provider_name,
                    'expires_on'     => $warranty->expires_on?->toDateString(),
                    'days_remaining' => $row['verdict']['days_remaining'],
                    'km_remaining'   => $row['verdict']['km_remaining'],
                ],
                // The evidence a reviewer needs to judge whether the inspection was worth doing —
                // and, later, to see what was true when it was asked for.
                'evidence' => [
                    'warranty_id'    => $warranty->id,
                    'subject'        => $warranty->subject,
                    'provider_name'  => $warranty->provider_name,
                    'provider_kind'  => $warranty->provider_kind,
                    'reference_no'   => $warranty->reference_no,
                    'expires_on'     => $warranty->expires_on?->toDateString(),
                    'expires_at_km'  => $warranty->expires_at_km,
                    'odometer'       => $vehicle->odometer,
                    'remaining'      => $row['verdict']['remaining_evidence'],
                ],
            ]);

            $raised[] = $requirement->id;
        }

        return [
            'scanned' => $candidates->count(),
            'raised'  => count($raised),
            'skipped' => $skipped,
            'requirements' => $raised,
        ];
    }

    /**
     * The warranties whose window is about to shut, judged on BOTH legs.
     *
     * SQL narrows on the date leg — the only one that can be range-scanned — and then every row is
     * judged in PHP against its own car's odometer. The second pass is not a refinement, it is the
     * whole reason this is correct: a car with eight months of cover and four hundred kilometres left
     * is about to lose it, and no query that reads only `expires_on` can see that at all. Rows with a
     * distance leg are therefore pulled in regardless of their date.
     *
     * @return Collection<int,array{warranty:Warranty, verdict:array}>
     */
    private function candidates(int $leadDays): Collection
    {
        return Warranty::query()
            ->where('status', Warranty::STATUS_ACTIVE)
            ->where(function ($q) use ($leadDays) {
                $q->whereBetween('expires_on', [now()->toDateString(), now()->addDays($leadDays)->toDateString()])
                  // Distance-bounded cover: the date filter cannot judge it, so let PHP.
                  ->orWhereNotNull('expires_at_km');
            })
            ->with(['vehicle:id,plate_no,make,model,odometer'])
            ->get()
            ->map(fn (Warranty $w) => [
                'warranty' => $w,
                'verdict'  => $w->evaluate(null, $w->vehicle?->odometer !== null ? (int) $w->vehicle->odometer : null),
            ])
            ->filter(function ($row) use ($leadDays) {
                $v = $row['verdict'];

                // Still live — there is nothing to inspect *before* on a warranty that already ended.
                if ($v['state'] !== Warranty::STATE_ACTIVE) {
                    return false;
                }

                // Inside the lead window on EITHER leg. The km side uses the same expiring-soon
                // threshold as everything else, so one dial moves the whole feature.
                $byDate = $v['days_remaining'] !== null && $v['days_remaining'] <= $leadDays;
                $byKm   = $v['km_remaining'] !== null && $v['km_remaining'] <= Warranty::expiringSoonKm();

                return $byDate || $byKm;
            })
            ->values();
    }
}
