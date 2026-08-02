<?php

namespace App\Services\Knowledge;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\RecurringFaultReview;
use App\Models\RepairInspection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Layer 2 — the retrieval spine of the Fleet Knowledge Engine. Given a fault (existing or being typed),
 * it finds comparable PAST repairs across the fleet and groups them into the four confidence tiers:
 *
 *   Tier 1  same vehicle + same fault      Tier 3  same manufacturer + same fault
 *   Tier 2  same make/model + same fault   Tier 4  fleet-wide same fault
 *
 * It READS the maintenance tables live (L1) — NO projection, NO new table. It generalises
 * RecurringFaultService::detectPriorFix from "same-vehicle recurrence blame" to "tiered, all-outcome
 * advisory retrieval". The tier-assignment + display bucketing are pure static helpers so they are
 * lockable without a DB.
 */
class RepairHistoryQueryService
{
    /**
     * BROADER HISTORY — the same area of the car, out of the fleet's real repair record.
     *
     * `similarRepairs` above asks "have we repaired THIS fault before" against `maintenance_tasks`, the
     * structured workflow. That table is ~100 rows old. Meanwhile `maintenance_signatures` holds ~49k
     * classified events derived from the historical sheet, and the result was a panel reporting "no
     * comparable repairs" for a brake fault on a fleet with 1,405 recorded brake repairs. It was not
     * wrong about its own corpus; it was consulting the wrong one.
     *
     * DELIBERATELY A SECOND, WEAKER ANSWER. Signatures are 22 area buckets, not 105 faults, and the rows
     * behind them carry no re-inspection outcome and usually no cost. So this returns a COUNT and a few
     * dated examples — never a recommendation, never a success rate, and it is kept out of the
     * confidence band entirely. Presenting it as equivalent evidence would let a thousand "BRAKES"
     * events argue for a conclusion about one specific brake fault, which they cannot support.
     *
     * @return array{signatures:array<int,string>, total:int, by_tier:array<string,int>,
     *               examples:array<int,array<string,mixed>>, category:?string}
     */
    public function broaderHistory(SimilarRepairQuery $q): array
    {
        $category   = $this->categoryFor($q);
        $signatures = $category
            ? (array) config("knowledge.broader_history.category_signatures.{$category}", [])
            : [];

        $empty = ['signatures' => [], 'total' => 0, 'by_tier' => [], 'examples' => [], 'category' => $category];

        if ($signatures === []) {
            return $empty;
        }

        // TIER RESOLVED IN SQL, not in PHP over a capped page.
        //
        // The first cut of this counted tiers by looping the newest 2,000 rows — which silently reported
        // "2000" for an area with 2,521 repairs, i.e. the page size masquerading as a fleet total. The
        // count has to be unbounded and the examples separately limited; they are two questions.
        $tierCase = 'CASE
            WHEN ? IS NOT NULL AND ms.vehicle_id = ? THEN 1
            WHEN ? IS NOT NULL AND ? IS NOT NULL AND v.make = ? AND v.model = ? THEN 2
            WHEN ? IS NOT NULL AND v.make = ? THEN 3
            ELSE 4 END';

        $tierBindings = [
            $q->vehicleId, $q->vehicleId,
            $q->make, $q->model, $q->make, $q->model,
            $q->make, $q->make,
        ];

        // `maintenance_signatures` denormalises vehicle_id and occurred_at precisely so this join stays
        // cheap. `is_exposure` rows (BODY / RIM customer damage) are KEPT — unlike the workshop-quality
        // metrics that must exclude them, "how often have we worked on this area" is a fair question to
        // answer with them.
        $base = fn () => \Illuminate\Support\Facades\DB::table('maintenance_signatures as ms')
            ->join('maintenances as m', 'm.id', '=', 'ms.maintenance_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'ms.vehicle_id')
            // LIVE query: retired tickets are excluded. A deleted ticket is one the fleet decided did not
            // happen, and it must not come back as precedent ([[softdelete-bypassed-by-raw-queries]]).
            ->whereNull('m.deleted_at')
            ->whereIn('ms.signature', $signatures)
            ->when($q->excludeMaintenanceId, fn ($w) => $w->where('ms.maintenance_id', '!=', $q->excludeMaintenanceId));

        // COUNT(DISTINCT maintenance_id), not COUNT(*). A category can map to several signatures
        // (`suspension` → SUSPENSION + STEERING) and one ticket can carry both, so counting rows would
        // report two repairs where the workshop did one job. The question is "how many times have we
        // dealt with this area", and the unit of that is the visit.
        $counts = $base()
            ->selectRaw("{$tierCase} AS tier, COUNT(DISTINCT ms.maintenance_id) AS n", $tierBindings)
            ->groupBy('tier')
            ->pluck('n', 'tier');

        $total = (int) $counts->sum();

        if ($total === 0) {
            return ['signatures' => $signatures] + $empty;
        }

        $byTier = [];
        foreach ($counts as $tier => $n) {
            $byTier[self::TIER_LABELS[(int) $tier]] = (int) $n;
        }

        // Examples favour the NARROWEST tier available, so a supervisor sees this car's own history
        // before the fleet's when both exist, then the most recent within that.
        $examples = $base()
            ->leftJoin('vendors as vd', 'vd.id', '=', 'm.vendor_id')
            ->selectRaw(
                "ms.maintenance_id, ms.vehicle_id, ms.occurred_at, ms.signature, v.make, v.model,
                 v.plate_no, vd.name AS garage, m.service_main, m.service_sup, {$tierCase} AS tier",
                $tierBindings,
            )
            ->orderBy('tier')
            ->orderByDesc('ms.occurred_at')
            // Over-fetch, then keep one row per ticket: the same visit can appear under two signatures
            // and a list showing the same repair three times reads as three separate precedents.
            ->limit(((int) config('knowledge.broader_history.examples', 5)) * 4)
            ->get()
            ->unique('maintenance_id')
            ->take((int) config('knowledge.broader_history.examples', 5))
            ->values()
            ->map(fn ($row) => [
                'maintenance_id' => (int) $row->maintenance_id,
                'vehicle_id'     => $row->vehicle_id,
                'plate'          => $row->plate_no,
                'make'           => $row->make,
                'model'          => $row->model,
                'signature'      => $row->signature,
                'work'           => trim((string) $row->service_main.' '.(string) $row->service_sup) ?: null,
                'garage'         => $row->garage,
                'occurred_at'    => $row->occurred_at ? substr((string) $row->occurred_at, 0, 10) : null,
                'tier'           => (int) $row->tier,
                'tier_label'     => self::TIER_LABELS[(int) $row->tier],
            ])
            ->all();

        return [
            'signatures' => $signatures,
            'total'      => $total,
            'by_tier'    => $byTier,
            'examples'   => $examples,
            'category'   => $category,
        ];
    }

    /**
     * Which findings category this query is about.
     *
     * Prefers the category already on the task, then falls back to looking the symptom up in the
     * findings vocabulary — `maintenance_tasks.category_key` is NULL on every row written so far, so
     * without the fallback this feature would resolve nothing at all on real tickets.
     */
    private function categoryFor(SimilarRepairQuery $q): ?string
    {
        if (filled($q->categoryKey)) {
            return $q->categoryKey;
        }

        if (blank($q->symptom)) {
            return null;
        }

        $needle = \App\Support\TextNormalizer::key($q->symptom);

        return \App\Models\FindingKeyword::query()
            ->get(['id', 'keyword', 'category_key'])
            ->first(fn ($k) => \App\Support\TextNormalizer::key($k->keyword) === $needle)
            ?->category_key;
    }

    /** Run the tiered retrieval for a query. */
    public function similarRepairs(SimilarRepairQuery $q): SimilarRepairResult
    {
        [$matchedOn, $apply] = $this->resolveMatch($q);
        if ($apply === null) {
            return new SimilarRepairResult(
                ['vehicle' => [], 'model' => [], 'make' => [], 'fleet' => []], [], 'none', 4
            );
        }

        $windowDays   = (int) config('knowledge.retrieval.window_days', 1095);
        $perTierLimit = (int) config('knowledge.retrieval.per_tier_limit', 10);

        /** @var Collection<int,MaintenanceTask> $tasks */
        $tasks = MaintenanceTask::query()
            ->where('status', MaintenanceTask::STATUS_COMPLETED)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', Carbon::now()->subDays($windowDays))
            // Faults + legacy-unclassified rows; never planned service / inspections.
            ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', MaintenanceTask::KIND_FAULT))
            ->when($q->excludeTaskId, fn ($w) => $w->where('id', '!=', $q->excludeTaskId))
            ->when($q->excludeMaintenanceId, fn ($w) => $w->where('maintenance_id', '!=', $q->excludeMaintenanceId))
            ->where($apply)
            ->with([
                'vehicle:id,make,model,plate_no',
                'currentVendor:id,name',
                'lineItems:id,maintenance_task_id,kind,description,part_number',
            ])
            ->orderByDesc('resolved_at')
            ->get();

        if ($tasks->isEmpty()) {
            return new SimilarRepairResult(
                ['vehicle' => [], 'model' => [], 'make' => [], 'fleet' => []], [], $matchedOn, 4
            );
        }

        // Batch the outcome + recurrence signals (no N+1).
        $ids          = $tasks->pluck('id')->all();
        $inspections  = $this->outcomesByFault($ids);
        $recurredIds  = $this->recurredFaultIds($ids);

        $flat = $tasks->map(fn (MaintenanceTask $t) => $this->project($t, $q, $inspections, $recurredIds))->all();

        $tiers    = self::bucketize($flat, $perTierLimit);
        $bestTier = $this->bestTier($flat);

        return new SimilarRepairResult($tiers, $flat, $matchedOn, $bestTier);
    }

    // ── Matching ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the fault-match predicate in precedence order (catalog → category → symptom→category →
     * raw symptom). Returns [matched_on label, Closure|null predicate].
     *
     * @return array{0:string, 1:?\Closure}
     */
    private function resolveMatch(SimilarRepairQuery $q): array
    {
        if (! $q->hasFaultIdentity()) {
            return ['none', null];
        }

        // A category is the sweet spot for breadth. Prefer an explicit one; else derive from the symptom.
        $category = $q->categoryKey ?: ($q->symptom ? Maintenance::categoryForKeyword($q->symptom) : null);

        if ($q->faultCatalogId !== null && $category) {
            // Catalog OR its category — widens to legacy rows that predate the catalog id.
            return ['fault_catalog+category', function ($w) use ($q, $category) {
                $w->where(fn ($x) => $x->where('fault_catalog_id', $q->faultCatalogId)->orWhere('category_key', $category));
            }];
        }
        if ($q->faultCatalogId !== null) {
            return ['fault_catalog', fn ($w) => $w->where('fault_catalog_id', $q->faultCatalogId)];
        }
        if ($category) {
            $label = $q->categoryKey ? 'category_key' : 'symptom→category';
            return [$label, fn ($w) => $w->where('category_key', $category)];
        }
        // Last resort — normalised exact symptom (mirrors detectPriorFix's fallback).
        $needle = mb_strtolower(trim((string) $q->symptom));
        return ['symptom', fn ($w) => $w->whereRaw('LOWER(TRIM(symptom)) = ?', [$needle])];
    }

    // ── Signal batching ───────────────────────────────────────────────────────────────────────────

    /**
     * Outcome per fault id: 'verified_fixed' (a fixed post-repair inspection) > 'failed' (still_exists) >
     * 'fixed' (completed, never re-inspected).
     *
     * @param  array<int,int>  $faultIds
     * @return array<int,string>
     */
    private function outcomesByFault(array $faultIds): array
    {
        $rows = RepairInspection::query()
            ->whereIn('fault_id', $faultIds)
            ->get(['fault_id', 'result']);

        $out = [];
        foreach ($rows as $r) {
            $prev = $out[$r->fault_id] ?? null;
            if ($r->result === RepairInspection::RESULT_FIXED) {
                $out[$r->fault_id] = 'verified_fixed';                     // strongest — always wins
            } elseif ($r->result === RepairInspection::RESULT_STILL_EXISTS && $prev !== 'verified_fixed') {
                $out[$r->fault_id] = 'failed';
            }
        }
        return $out;
    }

    /**
     * The set of fault ids that later RECURRED (a recurring-fault review points back at them).
     *
     * @param  array<int,int>  $faultIds
     * @return array<int,bool>
     */
    private function recurredFaultIds(array $faultIds): array
    {
        return RecurringFaultReview::query()
            ->whereIn('previous_task_id', $faultIds)
            ->pluck('previous_task_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    // ── Projection ──────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int,string>  $inspections
     * @param  array<int,bool>    $recurredIds
     * @return array<string,mixed>
     */
    private function project(MaintenanceTask $t, SimilarRepairQuery $q, array $inspections, array $recurredIds): array
    {
        $veh = $t->vehicle;

        $start    = $t->started_at ?? $t->identified_at;
        $duration = ($start && $t->resolved_at)
            ? (int) $start->copy()->startOfDay()->diffInDays($t->resolved_at->copy()->startOfDay())
            : null;

        $cost      = round((float) $t->parts_cost + (float) $t->labor_cost, 2);
        $costKnown = $cost > 0;

        $parts = $t->lineItems
            ->where('kind', 'part')
            ->map(fn ($li) => ['part_number' => $li->part_number, 'name' => $li->description])
            ->values()
            ->all();

        $tier = self::assignTier(
            $q->vehicleId, $q->make, $q->model,
            $t->vehicle_id, $veh?->make, $veh?->model
        );

        return [
            'maintenance_task_id' => $t->id,
            'maintenance_id'      => $t->maintenance_id,
            'vehicle_id'          => $t->vehicle_id,
            'plate'               => $veh?->plate_no,
            'make'                => $veh?->make,
            'model'               => $veh?->model,
            'symptom'             => $t->symptom,
            'root_cause'          => $t->root_cause,
            'garage'              => $t->currentVendor?->name,
            'garage_vendor_id'    => $t->current_vendor_id,
            'total_cost'          => $costKnown ? $cost : null,
            'cost_known'          => $costKnown,
            'duration_days'       => $duration,
            'parts'               => $parts,
            'outcome'             => $inspections[$t->id] ?? 'fixed',   // completed but un-reinspected ⇒ workshop-fixed
            'recurred'            => isset($recurredIds[$t->id]),
            'resolved_at'         => optional($t->resolved_at)->toDateString(),
            'tier'                => $tier,
            'tier_label'          => self::TIER_LABELS[$tier],
        ];
    }

    // ── Pure helpers (DB-free, unit-tested) ───────────────────────────────────────────────────────────

    public const TIER_LABELS = [1 => 'vehicle', 2 => 'model', 3 => 'make', 4 => 'fleet'];

    /**
     * Which tier a matched repair belongs to relative to the subject vehicle. Each repair maps to exactly
     * ONE tier (its vehicle is either the same, same model, same make, or none), so there is no cross-tier
     * duplication to reconcile.
     */
    public static function assignTier(
        ?int $subjectVehicleId, ?string $subjectMake, ?string $subjectModel,
        ?int $rowVehicleId, ?string $rowMake, ?string $rowModel
    ): int {
        if ($subjectVehicleId !== null && $rowVehicleId !== null && $subjectVehicleId === $rowVehicleId) {
            return 1;
        }
        $sameModel = $subjectModel !== null && $rowModel !== null
            && mb_strtolower(trim($subjectModel)) === mb_strtolower(trim($rowModel));
        $sameMake = $subjectMake !== null && $rowMake !== null
            && mb_strtolower(trim($subjectMake)) === mb_strtolower(trim($rowMake));

        if ($sameModel && ($subjectMake === null || $rowMake === null || $sameMake)) {
            return 2;
        }
        if ($sameMake) {
            return 3;
        }
        return 4;
    }

    /**
     * Group the flat rows into the four display tiers, each recency-sorted and capped.
     *
     * @param  array<int,array<string,mixed>>  $flat
     * @return array{vehicle:array, model:array, make:array, fleet:array}
     */
    public static function bucketize(array $flat, int $perTierLimit): array
    {
        $buckets = ['vehicle' => [], 'model' => [], 'make' => [], 'fleet' => []];
        foreach ($flat as $row) {
            $buckets[self::TIER_LABELS[$row['tier']] ?? 'fleet'][] = $row;
        }
        foreach ($buckets as $k => $rows) {
            usort($rows, fn ($a, $b) => strcmp((string) ($b['resolved_at'] ?? ''), (string) ($a['resolved_at'] ?? '')));
            $buckets[$k] = array_slice($rows, 0, $perTierLimit);
        }
        return $buckets;
    }

    /** The strongest (lowest) tier present in the cohort. */
    private function bestTier(array $flat): int
    {
        $best = 4;
        foreach ($flat as $row) {
            $best = min($best, (int) $row['tier']);
        }
        return $best;
    }
}
