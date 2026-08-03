<?php

namespace App\Services;

use App\Models\MaintenanceTask;
use App\Models\PartInvestigation;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use Illuminate\Support\Carbon;

/**
 * The brain of the Parts Purchase + Repair Intelligence workflow. Three pure, side-effect-free reads that
 * the controllers/services consult before they commit anything:
 *
 *   classify()        — is this a consumable, a standard part, or a major component? (drives alert priority)
 *   detectDuplicate() — has the same part been bought for this vehicle recently? (Parts 4 & 7)
 *   detectRecurrence()— has this fault been fixed before and come back? (Parts 5 & 6)
 *
 * All thresholds/keywords live in config/parts_intelligence.php so the rules are tunable without a code
 * change. The engine is deliberately source-agnostic: a part is the same part whether it came from the
 * garage or an external supplier.
 */
class PartIntelligenceService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('parts_intelligence');
    }

    // ───────────────────────────── classification ─────────────────────────────

    /**
     * Resolve a part's class from its category_key (fast path) then a keyword scan over name + number.
     * Unknown parts default to 'standard' so old/sparse records still classify without crashing (edge case 7).
     */
    public function classify(?string $partName, ?string $partNumber = null, ?string $categoryKey = null): string
    {
        $categoryKey = $categoryKey ? strtolower(trim($categoryKey)) : null;
        if ($categoryKey && isset($this->cfg['category_class'][$categoryKey])) {
            return $this->cfg['category_class'][$categoryKey];
        }

        $haystack = strtolower(trim(($partName ?? '') . ' ' . ($partNumber ?? '')));
        if ($haystack === '') {
            return PartRequest::CLASS_STANDARD;
        }

        // Consumable is checked BEFORE major so "brake pad" (consumable) never trips a "brake" major match.
        foreach ($this->cfg['consumable_keywords'] as $kw) {
            if (str_contains($haystack, strtolower($kw))) {
                return PartRequest::CLASS_CONSUMABLE;
            }
        }
        foreach ($this->cfg['major_keywords'] as $kw) {
            if (str_contains($haystack, strtolower($kw))) {
                return PartRequest::CLASS_MAJOR;
            }
        }

        return PartRequest::CLASS_STANDARD;
    }

    /** Normalise a part identity for matching: prefer the SKU, else the collapsed lower-cased name. */
    public function identityKey(?string $partNumber, ?string $partName): ?string
    {
        $pn = $partNumber ? strtolower(preg_replace('/\s+/', '', $partNumber)) : '';
        if ($pn !== '') {
            return 'pn:' . $pn;
        }
        $name = $partName ? strtolower(preg_replace('/\s+/', ' ', trim($partName))) : '';

        return $name !== '' ? 'nm:' . $name : null;
    }

    // ───────────────────────────── duplicate purchase ─────────────────────────────

    /**
     * Has this vehicle already received this part recently? Returns a verdict:
     *   ['duplicate' => bool, 'priority' => low|medium|high|null, 'previous' => PartPurchase|null,
     *    'days_between' => int|null, 'window_days' => int|null, 'part_class' => string]
     *
     * The caller runs this INSIDE a transaction with the prior rows locked, so two concurrent buys can't
     * both miss the other (edge case 6). Consumables never raise a duplicate (edge case 3); a repeat outside
     * every window is not a duplicate (edge case 2).
     *
     * @param int|null $excludePurchaseId skip this purchase id (when re-checking an already-inserted row)
     */
    public function detectDuplicate(
        int $vehicleId,
        ?string $partName,
        ?string $partNumber,
        ?string $categoryKey,
        ?string $partClass = null,
        ?int $excludePurchaseId = null,
        bool $lock = false,
        ?string $faultCategoryKey = null,
        ?string $faultSymptom = null
    ): array {
        $partClass = $partClass ?: $this->classify($partName, $partNumber, $categoryKey);
        $identity  = $this->identityKey($partNumber, $partName);

        $base = ['duplicate' => false, 'priority' => null, 'previous' => null, 'days_between' => null,
                 'window_days' => null, 'part_class' => $partClass, 'same_fault' => false];

        if ($identity === null) {
            return $base; // nothing to match on
        }

        // STRONGEST signal first: same PART bought for the same FAULT (category/symptom) on this vehicle,
        // within the recurrence window. That's not just a repeat buy — it's a failed repair coming back, so
        // it is ALWAYS High priority regardless of the part's class/value.
        if ($faultCategoryKey || $faultSymptom) {
            $sameFault = $this->priorSameFaultPurchase($vehicleId, $partNumber, $partName, $faultCategoryKey, $faultSymptom, $excludePurchaseId, $lock);
            if ($sameFault) {
                $prevAt = $sameFault->purchased_at ?: $sameFault->created_at;
                $days   = $prevAt ? (int) $prevAt->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay()) : null;

                return [
                    'duplicate'    => true,
                    'priority'     => PartInvestigation::PRIORITY_HIGH,
                    'previous'     => $sameFault,
                    'days_between' => $days,
                    'window_days'  => (int) $this->cfg['recurrence']['window_days'],
                    'part_class'   => $partClass,
                    'same_fault'   => true,
                ];
            }
        }

        $previous = $this->priorPurchaseQuery($vehicleId, $partNumber, $partName, $excludePurchaseId, $lock)->first();
        if (! $previous) {
            return $base; // no prior history → clean (edge cases 2 & 7 both land here for old/sparse data)
        }

        $prevAt = $previous->purchased_at ?: $previous->created_at;
        $days   = $prevAt ? (int) $prevAt->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay()) : null;

        $priority = $this->duplicatePriority($partClass, $days);
        if ($priority === null) {
            return $base + ['previous' => $previous, 'days_between' => $days]; // outside windows / consumable
        }

        return [
            'duplicate'    => true,
            'priority'     => $priority,
            'previous'     => $previous,
            'days_between' => $days,
            'window_days'  => $this->windowForClass($partClass),
            'part_class'   => $partClass,
            'same_fault'   => false,
        ];
    }

    /**
     * The newest prior purchase of the SAME part on this vehicle whose linked fault matches the given fault
     * (same category_key, else same normalized symptom), within the recurrence window. Null when there's no
     * such repeat — this is the Vehicle + Part + Fault signal (a part bought twice for the same problem).
     */
    private function priorSameFaultPurchase(
        int $vehicleId, ?string $partNumber, ?string $partName,
        ?string $faultCategoryKey, ?string $faultSymptom, ?int $excludeId, bool $lock
    ): ?PartPurchase {
        $since = Carbon::now()->subDays((int) $this->cfg['recurrence']['window_days']);

        return $this->priorPurchaseQuery($vehicleId, $partNumber, $partName, $excludeId, $lock)
            ->where(fn ($q) => $q->where('purchased_at', '>=', $since)->orWhere('created_at', '>=', $since))
            ->whereHas('task', function ($q) use ($faultCategoryKey, $faultSymptom) {
                $q->affectingReliability();
                if ($faultCategoryKey) {
                    $q->whereRaw('LOWER(TRIM(category_key)) = ?', [strtolower(trim($faultCategoryKey))]);
                } elseif ($faultSymptom) {
                    $q->whereRaw('LOWER(TRIM(symptom)) = ?', [strtolower(trim($faultSymptom))]);
                }
            })
            ->first();
    }

    /**
     * The priority a repeat buy earns, or null for "no alert". Consumables never alert. Any non-consumable
     * repeat inside the short window is HIGH regardless of value (a real part bought twice in days = a bad
     * fix). Otherwise majors alert HIGH within the long window; standards alert MEDIUM within the medium one.
     */
    private function duplicatePriority(string $partClass, ?int $days): ?string
    {
        if ($days === null || $partClass === PartRequest::CLASS_CONSUMABLE) {
            return null;
        }
        $w = $this->cfg['windows'];

        if ($days <= $w['short_days']) {
            return PartInvestigation::PRIORITY_HIGH;            // edge case 1
        }
        if ($partClass === PartRequest::CLASS_MAJOR && $days <= $w['major_days']) {
            return PartInvestigation::PRIORITY_HIGH;            // edge case 4 (ECU twice/month)
        }
        if ($partClass === PartRequest::CLASS_STANDARD && $days <= $w['standard_days']) {
            return PartInvestigation::PRIORITY_MEDIUM;          // Part 7 medium
        }

        return null;                                           // outside every window (edge case 2)
    }

    private function windowForClass(string $partClass): int
    {
        $w = $this->cfg['windows'];

        return match ($partClass) {
            PartRequest::CLASS_MAJOR    => $w['major_days'],
            PartRequest::CLASS_STANDARD => $w['standard_days'],
            default                     => $w['short_days'],
        };
    }

    /** Prior purchases of the same part identity on the same vehicle, newest first (optionally locked). */
    private function priorPurchaseQuery(int $vehicleId, ?string $partNumber, ?string $partName, ?int $excludeId, bool $lock)
    {
        $q = PartPurchase::query()
            ->where('vehicle_id', $vehicleId)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));

        $pn = $partNumber ? preg_replace('/\s+/', '', $partNumber) : '';
        if ($pn !== '') {
            // Match on SKU when we have one — the stable identity.
            $q->whereRaw("REPLACE(LOWER(part_number),' ','') = ?", [strtolower($pn)]);
        } else {
            $name = $partName ? strtolower(preg_replace('/\s+/', ' ', trim($partName))) : '';
            $q->whereRaw("LOWER(TRIM(part_name)) = ?", [$name]);
        }

        $q->orderByDesc('purchased_at')->orderByDesc('id');

        return $lock ? $q->lockForUpdate() : $q;
    }

    /** Human-readable snapshot of the prior purchase for the alert / investigation context. */
    public function duplicateContext(array $verdict): array
    {
        $prev = $verdict['previous'] ?? null;

        return [
            'part_class'   => $verdict['part_class'] ?? null,
            'priority'     => $verdict['priority'] ?? null,
            // Vehicle + Part + Fault repeat — the same part bought again for the SAME problem (a failed fix),
            // which is graver than merely buying the same part. The UI headlines this differently.
            'same_fault'   => $verdict['same_fault'] ?? false,
            'days_between' => $verdict['days_between'] ?? null,
            'window_days'  => $verdict['window_days'] ?? null,
            'previous'     => $prev ? [
                'purchase_id'   => $prev->id,
                'part_name'     => $prev->part_name,
                'part_number'   => $prev->part_number,
                'purchased_at'  => optional($prev->purchased_at)->toDateString(),
                'purchase_price' => $prev->purchase_price,
                'currency'      => $prev->currency,
                'source'        => $prev->purchase_source,
                // The concrete vendor for the "Supplier: ABC Parts" line — resolved vendor name, else free text.
                'source_name'   => optional($prev->sourceVendor)->name ?: $prev->source_name,
                // The earlier ticket this part was bought under, for the "Maintenance Ticket: #170510" line.
                'maintenance_id' => $prev->maintenance_id,
                'purchased_by'  => $prev->purchased_by_name,
            ] : null,
        ];
    }

    // ───────────────────────────── fault recurrence ─────────────────────────────

    /**
     * Has this fault been repaired before and come back? Looks for a previously-COMPLETED task on the same
     * vehicle with the same category_key (or symptom) within the recurrence window, excluding the current
     * task. Returns null when there's no prior repair (edge cases 7 & 8 land here safely).
     *
     * Returns ['previous_task'=>MaintenanceTask, 'days_ago'=>int, 'technician'=>?string,
     *          'parts'=>array, 'cost'=>float, 'open_investigation'=>bool]
     */
    public function detectRecurrence(int $vehicleId, ?string $categoryKey, ?string $symptom, ?int $excludeTaskId = null): ?array
    {
        $windowDays = (int) $this->cfg['recurrence']['window_days'];
        $since      = Carbon::now()->subDays($windowDays);

        $q = MaintenanceTask::query()
            ->where('vehicle_id', $vehicleId)
            ->where('status', MaintenanceTask::STATUS_COMPLETED)
            // Event Type layer: fault recurrence counts prior FAULTS, not prior planned services.
            ->affectingReliability()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $since)
            ->when($excludeTaskId, fn ($q) => $q->where('id', '!=', $excludeTaskId));

        if ($categoryKey) {
            $q->where('category_key', $categoryKey);
        } elseif ($symptom) {
            $q->whereRaw('LOWER(TRIM(symptom)) = ?', [strtolower(trim($symptom))]);
        } else {
            return null;
        }

        $previous = $q->with(['lineItems', 'resolvedBy:id,name', 'currentVendor:id,name'])
            ->orderByDesc('resolved_at')->first();
        if (! $previous) {
            return null;
        }

        $daysAgo = (int) $previous->resolved_at->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay());
        $parts   = $previous->lineItems
            ->where('kind', 'part')
            ->map(fn ($li) => ['description' => $li->description, 'part_number' => $li->part_number])
            ->values()->all();

        return [
            'previous_task'      => $previous,
            'days_ago'           => $daysAgo,
            'technician'         => $previous->resolvedBy?->name,
            'parts'              => $parts,
            'cost'               => (float) ($previous->parts_cost + $previous->labor_cost),
            'open_investigation' => $daysAgo <= (int) $this->cfg['recurrence']['investigation_days'],
        ];
    }
}
