<?php

namespace App\Services\Warranty;

use App\Models\PartRequest;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Support\WarrantyCoverage;
use Illuminate\Support\Collection;

/**
 * The warranty dashboard's numbers — how much cover the fleet has, what is waiting on somebody, and
 * what the whole thing has actually been worth.
 *
 * Evidence class: D (derived). Produces: nothing. Consumes: warranties, warranty_claims,
 * part_requests, vehicles.odometer.
 *
 * ── ONE TILE IS THE POINT OF THE FEATURE AND THE REST ARE CONTEXT ──────────────────────────────
 *
 * `benefit` — recovered plus avoided — is the number this whole system exists to move. Everything
 * else on the board (how many cars are covered, how many reviews are open) is a leading indicator of
 * it. It is reported as TWO figures added at the last moment rather than one stored total, because
 * they are genuinely different facts: money that came back versus money we never had to spend. A
 * dealer replacing a gearbox for free recovers nothing and avoids a great deal.
 *
 * ── THE TILE NOBODY ASKS FOR, AND WHY IT IS HERE ANYWAY ────────────────────────────────────────
 *
 * `overrides` counts the purchases somebody made in the face of a live warranty. It is the only
 * number on the board that measures the system's own failure, and it is exactly the one a feature
 * like this quietly omits. If it climbs, the honest readings are "the reviews are too slow" or "the
 * gate is being used as a speed bump", and both are things the warranty desk needs to SEE rather
 * than infer from a claim they lost six months later.
 *
 * ── WHY THE COVER COUNTS ARE COMPUTED IN PHP ───────────────────────────────────────────────────
 *
 * "How many cars are under warranty" cannot be a COUNT(*) with a WHERE on expires_on, because a
 * warranty ends on months OR kilometres and the second leg depends on each car's own odometer. A
 * SQL-only tile would systematically overstate cover on precisely the hardest-driven cars — the ones
 * whose warranties are worth the most and lapse the soonest. So the rows are loaded and judged. That
 * is affordable here and would not be at ten times the size; the trade is made deliberately and is
 * noted so a future reader knows it was a choice.
 */
class WarrantyReportService
{
    /**
     * @return array{cover:array, cases:array, benefit:array, generated_at:string}
     */
    public function dashboard(): array
    {
        return [
            'cover'   => $this->coverCounts(),
            'cases'   => $this->caseCounts(),
            'benefit' => $this->benefit(),
            // Every surface in this codebase that derives a number says when it derived it, so a
            // stalled page reads as ageing data rather than as figures that quietly drift.
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * How much of the fleet is protected, judged on both legs.
     *
     * `expiring_soon` is a SUBSET of `active`, deliberately: something expiring in three weeks is
     * still live today, and a board where the two tiles are exclusive invites the reading that cover
     * has already gone. `distance_unknown` is surfaced rather than folded into either — a km-bounded
     * warranty judged with no odometer is not evidence of cover, and a dashboard that pretends
     * otherwise is worse than one that admits the gap.
     */
    private function coverCounts(): array
    {
        $rows = Warranty::query()
            ->vehicleCover()
            ->where('status', Warranty::STATUS_ACTIVE)
            ->with('vehicle:id,odometer')
            ->get();

        $counts = [
            'vehicles_active' => 0, 'expiring_soon' => 0, 'expired' => 0,
            'distance_unknown' => 0, 'total_recorded' => $rows->count(),
        ];

        $activeVehicles = [];
        $soonVehicles   = [];
        $expiredVehicles = [];

        foreach ($rows as $w) {
            $verdict = $w->evaluate(null, $w->vehicle?->odometer !== null ? (int) $w->vehicle->odometer : null);

            if ($verdict['distance_unknown']) {
                $counts['distance_unknown']++;
            }

            // Counted by CAR, not by warranty row: a vehicle with two live warranties is one covered
            // car, and counting rows would inflate the headline in a way nobody could reconcile
            // against the vehicle list.
            if ($verdict['state'] === Warranty::STATE_ACTIVE) {
                $activeVehicles[$w->vehicle_id] = true;
                if ($verdict['expiring_soon']) {
                    $soonVehicles[$w->vehicle_id] = true;
                }
            } elseif ($verdict['state'] === Warranty::STATE_EXPIRED) {
                $expiredVehicles[$w->vehicle_id] = true;
            }
        }

        $counts['vehicles_active'] = count($activeVehicles);
        $counts['expiring_soon']   = count($soonVehicles);
        // A car with one expired warranty and one live one is COVERED, not expired. Subtracting the
        // active set is what keeps the three tiles from summing to more than the fleet.
        $counts['expired'] = count(array_diff_key($expiredVehicles, $activeVehicles));

        return $counts;
    }

    /** What is waiting on somebody, and who. */
    private function caseCounts(): array
    {
        $byStage = WarrantyClaim::query()
            ->selectRaw('stage, COUNT(*) as n')
            ->groupBy('stage')
            ->pluck('n', 'stage');

        $open = collect(WarrantyClaim::OPEN_STAGES)->sum(fn ($s) => (int) ($byStage[$s] ?? 0));
        $awaitingProvider = collect(WarrantyClaim::AWAITING_PROVIDER_STAGES)->sum(fn ($s) => (int) ($byStage[$s] ?? 0));

        $byOutcome = WarrantyClaim::query()
            ->selectRaw('outcome, COUNT(*) as n')
            ->groupBy('outcome')
            ->pluck('n', 'outcome');

        return [
            'coverage_reviews_pending' => (int) ($byStage[WarrantyClaim::STAGE_COVERAGE_REVIEW] ?? 0),
            'open'                     => $open,
            'awaiting_provider'        => $awaitingProvider,
            'claims_approved'          => (int) ($byOutcome[WarrantyClaim::OUTCOME_ACCEPTED] ?? 0)
                                          + (int) ($byOutcome[WarrantyClaim::OUTCOME_PARTIAL] ?? 0),
            'claims_rejected'          => (int) ($byOutcome[WarrantyClaim::OUTCOME_REJECTED] ?? 0),
            // Reviews that ended "ours to pay" are a SUCCESS of the gate, not a failure of it: the
            // question was asked before the money moved. Shown so the board does not read as if every
            // review that did not end in a claim was wasted effort.
            'reviews_cleared'          => (int) ($byStage[WarrantyClaim::STAGE_NOT_COVERED] ?? 0),
            'by_stage'                 => $byStage->all(),
        ];
    }

    /**
     * What the feature has been worth, and what it has cost us when it was bypassed.
     */
    private function benefit(): array
    {
        $money = WarrantyClaim::query()
            ->selectRaw('COALESCE(SUM(recovered_amount),0) as recovered, COALESCE(SUM(avoided_amount),0) as avoided')
            ->first();

        $recovered = (float) ($money->recovered ?? 0);
        $avoided   = (float) ($money->avoided ?? 0);

        return [
            'recovered' => $recovered,
            'avoided'   => $avoided,
            // Added HERE and nowhere else — see the class note on why they are two columns.
            'total'     => $recovered + $avoided,
            'currency'  => 'AED',
            // The self-critical tile. @see the class note.
            'overrides' => PartRequest::query()->whereNotNull('warranty_override_at')->count(),
            // Requests raised while a review was still open or cover was confirmed — the population
            // the override count is drawn from, so the ratio is readable rather than a bare number.
            'requests_blocked_then_bought' => PartRequest::query()
                ->whereIn('warranty_verdict', WarrantyCoverage::BLOCKING)
                ->count(),
        ];
    }

    /**
     * The register, sliced the way a person browsing asks for it: what is ending, soonest first.
     *
     * @return Collection<int,array>
     */
    public function expiringSoon(int $days = 60, int $limit = 100): Collection
    {
        return Warranty::query()
            ->where('status', Warranty::STATUS_ACTIVE)
            ->with(['vehicle:id,plate_no,make,model,odometer', 'provider:id,name'])
            ->get()
            ->map(fn (Warranty $w) => [
                'warranty' => $w,
                'verdict'  => $w->evaluate(null, $w->vehicle?->odometer !== null ? (int) $w->vehicle->odometer : null),
            ])
            ->filter(fn ($r) => $r['verdict']['state'] === Warranty::STATE_ACTIVE
                && $r['verdict']['days_remaining'] !== null
                && $r['verdict']['days_remaining'] <= $days)
            ->sortBy(fn ($r) => $r['verdict']['days_remaining'])
            ->take($limit)
            ->values();
    }
}
