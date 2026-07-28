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
