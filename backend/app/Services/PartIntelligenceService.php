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

        $this->applyIdentity($q, $partNumber, $partName);

        $q->orderByDesc('purchased_at')->orderByDesc('id');

        return $lock ? $q->lockForUpdate() : $q;
    }

    /**
     * The part-identity match, shared by every read: the SKU when we have one (the stable identity),
     * otherwise the collapsed lower-cased name. Kept in one place so the duplicate verdict and the full
     * history can never disagree about what counts as "the same part".
     */
    private function applyIdentity($q, ?string $partNumber, ?string $partName)
    {
        $pn = $partNumber ? preg_replace('/\s+/', '', $partNumber) : '';
        if ($pn !== '') {
            return $q->whereRaw("REPLACE(LOWER(part_number),' ','') = ?", [strtolower($pn)]);
        }

        $name = $partName ? strtolower(preg_replace('/\s+/', ' ', trim($partName))) : '';

        return $q->whereRaw('LOWER(TRIM(part_name)) = ?', [$name]);
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

    // ───────────────────────────── full purchase record ─────────────────────────────

    /**
     * EVERY time this part was bought for this vehicle — the whole record, not just the window the alert
     * cares about. detectDuplicate() answers "should I warn?"; this answers "what has actually happened
     * with this part on this car?", which is the question the buyer is really asking. No date cutoff: a
     * purchase from two years ago is still a purchase, it simply carries `within_alert_window = false`.
     *
     * Returns ['records'=>[…newest first…], 'summary'=>[…], 'fleet'=>[…]|null, 'truncated'=>bool].
     * `fleet` is the same part on OTHER vehicles — what it normally costs and how often the fleet buys it.
     */
    public function partHistory(
        int $vehicleId,
        ?string $partName,
        ?string $partNumber,
        ?string $categoryKey = null,
        ?int $excludePurchaseId = null,
        ?string $partClass = null
    ): array {
        $empty = ['records' => [], 'summary' => null, 'fleet' => null, 'truncated' => false];

        if ($this->identityKey($partNumber, $partName) === null) {
            return $empty; // nothing to match on
        }

        $partClass = $partClass ?: $this->classify($partName, $partNumber, $categoryKey);
        $window    = $this->windowForClass($partClass);
        $limit     = (int) ($this->cfg['history']['max_records'] ?? 50);

        $q         = $this->priorPurchaseQuery($vehicleId, $partNumber, $partName, $excludePurchaseId, false);
        $total     = (clone $q)->count();
        $matchedBy = trim((string) $partNumber) !== '' ? 'part_number' : 'part_name';

        // A SKU that matches nothing is NOT evidence the part is new. Part numbers are hand-typed, differ
        // between suppliers for the same component, and are sometimes placeholders ("1111"). Matching on
        // the SKU alone would then report "never bought" for a car that has had the part twice — a false
        // clean bill, which is the one answer this feature must never give. So fall back to the name, and
        // report which identity actually answered so the UI can say so.
        if ($total === 0 && $matchedBy === 'part_number' && trim((string) $partName) !== '') {
            $q         = $this->priorPurchaseQuery($vehicleId, null, $partName, $excludePurchaseId, false);
            $total     = (clone $q)->count();
            $matchedBy = 'part_name';
            $partNumber = null;   // the fleet roll-up below must use the SAME identity that found these
        }

        if ($total === 0) {
            return $empty;
        }

        $rows = $q->with([
            'sourceVendor:id,name',
            'task:id,symptom,category_key,root_cause,status,resolved_at',
        ])->limit($limit)->get();

        $today   = Carbon::now()->startOfDay();
        $records = $rows->map(function (PartPurchase $p) use ($today, $window) {
            $at   = $p->purchased_at ?: $p->created_at;
            $days = $at ? (int) $at->copy()->startOfDay()->diffInDays($today) : null;
            $qty  = (float) ($p->quantity ?: 1);

            return [
                'purchase_id'     => $p->id,
                'purchased_at'    => optional($at)->toDateString(),
                'days_ago'        => $days,
                // Whether THIS row is inside the window that would raise an alert. Rows outside it are the
                // history the old check threw away — shown, but visibly not the reason for any warning.
                'within_alert_window' => $days !== null && $days <= $window,
                'part_name'       => $p->part_name,
                'part_number'     => $p->part_number,
                'part_class'      => $p->part_class,
                'quantity'        => $qty,
                'unit_price'      => $p->purchase_price === null ? null : (float) $p->purchase_price,
                'total_price'     => $p->purchase_price === null ? null : round((float) $p->purchase_price * $qty, 2),
                'currency'        => $p->currency,
                'purchase_source' => $p->purchase_source,                       // garage | supplier
                'source_name'     => $p->sourceVendor?->name ?: $p->source_name,
                'po_number'       => $p->po_number,
                'repair_location' => $p->repair_location,                       // garage | onsite
                'maintenance_id'  => $p->maintenance_id,
                'fault'           => $p->task?->symptom,
                'fault_category'  => $p->task?->category_key,
                'root_cause'      => $p->task?->root_cause,
                'purchased_by'    => $p->purchased_by_name,
                'installed_by'    => $p->installed_by_name,
                'installed_at'    => optional($p->installed_at)->toDateString(),
                'installed_odometer' => $p->installed_odometer,
                'delivered_at'    => optional($p->delivered_at)->toDateString(),
                // The outcome of the fix this part was bought for — a 'failed' row is the single most
                // useful thing on this list: the same part is about to be bought again after it did not work.
                'result'          => $p->result,
                'flagged'         => (bool) $p->requires_review,
                'notes'           => $p->notes,
            ];
        })->values()->all();

        return [
            'records'   => $records,
            'summary'   => $this->historySummary($records, $total, $partClass, $window) + ['matched_by' => $matchedBy],
            'fleet'     => $this->fleetPartStats($vehicleId, $partNumber, $partName, $excludePurchaseId),
            'truncated' => $total > count($records),
        ];
    }

    /** Roll the record list up into the one-line verdict above it ("bought 4 times, 1 350 AED, 1 failed"). */
    private function historySummary(array $records, int $total, string $partClass, int $window): array
    {
        // Spend sums only the rows we returned, and only those in the base currency — adding a dirham to a
        // dollar would invent a number. It is therefore reported alongside the row count it actually covers.
        $base   = (string) $this->cfg['base_currency'];
        $priced = array_values(array_filter(
            $records,
            fn ($r) => $r['total_price'] !== null && ($r['currency'] === null || $r['currency'] === $base)
        ));
        $spend  = array_sum(array_column($priced, 'total_price'));
        $first  = $records ? end($records) : null;
        $last   = $records[0] ?? null;

        return [
            'total_purchases'  => $total,
            'shown'            => count($records),
            'part_class'       => $partClass,
            'alert_window_days' => $window,
            'in_alert_window'  => count(array_filter($records, fn ($r) => $r['within_alert_window'])),
            'first_purchased_at' => $first['purchased_at'] ?? null,
            'last_purchased_at'  => $last['purchased_at'] ?? null,
            'days_since_last'    => $last['days_ago'] ?? null,
            'total_spend'      => $priced ? round($spend, 2) : null,
            'spend_covers'     => count($priced),
            'currency'         => $base,
            'failed'           => count(array_filter($records, fn ($r) => $r['result'] === PartPurchase::RESULT_FAILED)),
            'flagged'          => count(array_filter($records, fn ($r) => $r['flagged'])),
            'never_installed'  => count(array_filter($records, fn ($r) => $r['installed_at'] === null)),
            'sources'          => array_values(array_unique(array_filter(array_column($records, 'source_name')))),
        ];
    }

    /**
     * The same part across the REST of the fleet — how often it is bought and what it normally costs, so
     * the buyer can sanity-check today's price. Null when no other vehicle has ever had it.
     */
    private function fleetPartStats(int $vehicleId, ?string $partNumber, ?string $partName, ?int $excludeId): ?array
    {
        $q = PartPurchase::query()
            ->where('vehicle_id', '!=', $vehicleId)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));
        $this->applyIdentity($q, $partNumber, $partName);

        $base = (string) $this->cfg['base_currency'];
        $row  = (clone $q)->selectRaw('COUNT(*) as c, COUNT(DISTINCT vehicle_id) as v, MAX(purchased_at) as last_at')->first();
        if (! $row || (int) $row->c === 0) {
            return null;
        }

        // Price stats stay in the base currency only — averaging mixed currencies would invent a number.
        $price = (clone $q)->where('currency', $base)->whereNotNull('purchase_price')
            ->selectRaw('COUNT(*) as c, AVG(purchase_price) as avg_p, MIN(purchase_price) as min_p, MAX(purchase_price) as max_p')
            ->first();

        return [
            'purchases'    => (int) $row->c,
            'vehicles'     => (int) $row->v,
            'last_purchased_at' => $row->last_at ? Carbon::parse($row->last_at)->toDateString() : null,
            'currency'     => $base,
            'priced_rows'  => (int) ($price->c ?? 0),
            'avg_price'    => ($price && $price->c) ? round((float) $price->avg_p, 2) : null,
            'min_price'    => ($price && $price->c) ? round((float) $price->min_p, 2) : null,
            'max_price'    => ($price && $price->c) ? round((float) $price->max_p, 2) : null,
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
