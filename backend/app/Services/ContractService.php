<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\ContractEligibilityService;
use App\Services\MaintenanceAnalyticsService;
use Illuminate\Support\Facades\DB;

class ContractService
{
    public function __construct(
        private ContractEligibilityService $eligibility,
        private OperationsService $operations,
    ) {
    }
    /**
     * Paginated contracts with optional filters.
     * $filters: ['contract_type' => C|U|R, 'state' => open|closed, 'search' => string]
     */
    public function index(array $filters = [])
    {
        return Contract::with(['customer', 'vehicle'])
            ->when($filters['vehicle_id'] ?? null, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when($filters['contract_type'] ?? null, fn ($q, $t) => $q->where('contract_type', $t))
            ->when($filters['state'] ?? null, fn ($q, $s) => $q->where('state', $s))
            ->when($filters['balance'] ?? null, fn ($q, $b) => match ($b) {
                'owes'    => $q->where('contract_balance', '>', 0),   // customer still owes
                'credit'  => $q->where('contract_balance', '<', 0),   // overpaid / credit
                'settled' => $q->where(fn ($w) => $w->where('contract_balance', 0)->orWhereNull('contract_balance')),
                default   => $q,
            })
            // Find contracts with no OfficeManager serial (e.g. maintenance rows imported
            // from the sheet) vs. those that came from the API and do carry one.
            ->when($filters['contract_serial'] ?? null, fn ($q, $v) => match ($v) {
                'missing' => $q->where(fn ($w) => $w->whereNull('contract_serial')->orWhere('contract_serial', '')),
                'present' => $q->whereNotNull('contract_serial')->where('contract_serial', '<>', ''),
                default   => $q,
            })
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($w) use ($search) {
                    $w->where('contract_no', 'like', "%{$search}%")
                        ->orWhereHas('vehicle', fn ($v) => $v->where('plate_no', 'like', "%{$search}%")->orWhere('vin', 'like', "%{$search}%"))
                        ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', "%{$search}%"))
                        // maintenance keywords / responsible / garage now live on the maintenance header
                        ->orWhereHas('maintenance', fn ($m) => $m
                            ->where('maintenance_tags', 'like', "%{$search}%")
                            ->orWhere('responsible', 'like', "%{$search}%")
                            ->orWhereHas('vendor', fn ($vn) => $vn->where('name', 'like', "%{$search}%")))
                        ->orWhereHas('items', fn ($it) => $it->where('service_name', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw('out_date IS NULL, out_date DESC')   // newest contracts first (nulls last)
            ->orderByDesc('id')
            ->paginate(50);
    }

    /** Maintenance header fields that live on the `maintenances` table, not on contracts. */
    private const MAINTENANCE_FIELDS = [
        'vendor_id', 'maintenance_tags', 'responsible',
        'approved_by', 'expected_return_date', 'maintenance_notes',
    ];

    public function store(array $data)
    {
        // Booking gate: Red and Yellow are refused; Orange requires (and records) a customer-
        // condition acknowledgment. Returns the acknowledgment columns to stamp on the contract.
        $ack = $this->eligibility->assertEligibleForContract($data);
        unset(
            $data['condition_acknowledged'], $data['condition_ack_by'],
            $data['manager_override'], $data['override_by'], $data['override_reason'],
            $data['pull_from_maintenance'],
        );
        $data = array_merge($data, $ack);

        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? null;
            unset($data['items']);
            $maint = $this->extractMaintenance($data);

            // For a maintenance visit, the bill = sum of its line items.
            if (is_array($items)) {
                $data['contract_debit'] = $this->itemsTotal($items);
            }

            $contract = Contract::create($data);
            $this->syncMaintenance($contract, $maint);
            $this->syncItems($contract, $items);
            $this->evaluateApproval($contract);
            $this->syncDeferredMaintenance($contract);

            return $contract->load(['maintenance.vendor', 'items', 'customer', 'vehicle']);
        });
    }

    public function update(array $data, Contract $contract)
    {
        return DB::transaction(function () use ($data, $contract) {
            $items = array_key_exists('items', $data) ? $data['items'] : null;
            unset($data['items']);
            $maint = $this->extractMaintenance($data);

            if (is_array($items)) {
                $data['contract_debit'] = $this->itemsTotal($items);
            }

            $contract->update($data);
            $this->syncMaintenance($contract, $maint);
            $this->syncItems($contract, $items);
            $this->evaluateApproval($contract);

            return $contract->refresh()->load(['maintenance.vendor', 'items', 'customer', 'vehicle']);
        });
    }

    public function destroy(Contract $contract)
    {
        $contract->delete();
    }

    /**
     * The garage the car is in for this maintenance visit.
     *
     * A maintenance contract's own `vendor_id` is frequently empty, while the live
     * workshop log (the N-Maintenance sheet events on the `maintenances` table) records
     * the car moving through several garages. So we surface the LATEST garage from that
     * log, scoped to this visit's window: while the contract is open that's "where the car
     * is now"; once it's closed that's "the last garage it was in". Returns null when there
     * is nothing to show.
     *
     * @return array{name:string,status:?string,as_of:?string,returned:bool}|null
     */
    public function resolveCurrentGarage(Contract $contract): ?array
    {
        if (! $contract->vehicle_id) {
            return null;
        }

        // The most recent date an event carries (returned > followed-up > sent-out).
        $dateOf = function (Maintenance $e) {
            $dates = array_filter([$e->actual_in_date, $e->follow_date, $e->out_date]);
            if (empty($dates)) {
                return null;
            }
            usort($dates, fn ($a, $b) => $a <=> $b);
            return end($dates);
        };

        $events = Maintenance::workshopEvents()
            ->where('vehicle_id', $contract->vehicle_id)
            ->whereNotNull('garage')
            ->where('garage', '<>', '')
            ->get();

        // Keep only events inside this visit: on/after the car was sent out and (once it's
        // back) on/before it returned. If the window matches nothing — e.g. the log dates
        // don't line up with the contract — fall back to every garage event for the car.
        $out = $contract->out_date?->copy()->startOfDay();
        $in  = $contract->in_date?->copy()->endOfDay();
        $windowed = $events->filter(function ($e) use ($dateOf, $out, $in) {
            $d = $dateOf($e);
            if (! $d) {
                return false;
            }
            if ($out && $d->lt($out)) {
                return false;
            }
            if ($in && $d->gt($in)) {
                return false;
            }
            return true;
        });
        $pool = $windowed->isNotEmpty() ? $windowed : $events;

        if ($pool->isEmpty()) {
            // No workshop log for this car — fall back to the contract header's linked garage.
            $name = $contract->maintenance?->vendor?->name;
            return $name ? ['name' => $name, 'status' => null, 'as_of' => null, 'returned' => false] : null;
        }

        // Latest by date, then by id so the most recently recorded row wins on ties
        // (e.g. a "Follow up" and an "IN" stamped the same day → take the final "IN").
        $latest = $pool->sort(function ($a, $b) use ($dateOf) {
            $da = optional($dateOf($a))->getTimestamp() ?? 0;
            $db = optional($dateOf($b))->getTimestamp() ?? 0;
            return ($da <=> $db) ?: ($a->id <=> $b->id);
        })->last();
        $d = $dateOf($latest);

        return [
            'name'     => $latest->garage,
            'status'   => $latest->event_status,
            'as_of'    => $d ? $d->toDateString() : null,
            // 'IN' means the car already came back from that garage.
            'returned' => strtoupper((string) $latest->event_status) === 'IN',
        ];
    }

    /**
     * Suggest the next contract number for a web-created contract.
     *
     * OfficeManager owns the authoritative integer sequences (rentals ~88k, maintenance
     * ~4.9k, bookings ~0.5k) and re-imports them, so we must NOT hand out a bare integer
     * that a future OM import could claim. Instead web contracts get a distinct "W-" prefix
     * and a single global running sequence — unique across every type and obviously
     * FleetView-originated. Returns e.g. "W-00001".
     */
    public function nextWebContractNo(): string
    {
        $prefix = 'W-';
        // withTrashed(): the (contract_no, contract_type) unique index still holds soft-deleted rows,
        // so a number we skip here would collide at insert. Count trashed rows to stay strictly ahead.
        $max = (int) Contract::withTrashed()
            ->where('contract_no', 'like', $prefix.'%')
            ->pluck('contract_no')
            ->map(fn ($no) => (int) preg_replace('/\D/', '', $no))
            ->max();

        return $prefix.str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Flag a maintenance job for approval when its cost (total or any single item)
     * exceeds the threshold. Keeps an existing approval as long as the cost hasn't
     * risen above the approved amount; otherwise re-flags it as pending.
     */
    protected function evaluateApproval(Contract $contract): void
    {
        if ($contract->contract_type !== 'U') {
            return;
        }

        $threshold = (float) config('fleet.maintenance_approval_threshold', 500);

        $itemsTotal = (float) $contract->items()->sum('cost');
        $maxItem    = (float) $contract->items()->max('cost');
        $cost = max($itemsTotal, (float) $contract->contract_debit, $maxItem);

        $m = $contract->maintenance()->first();
        $current  = $m?->approval_status ?? 'not_required';
        $approved = (float) ($m?->approved_amount ?? 0);

        if ($cost <= $threshold) {
            $status = 'not_required';
        } elseif ($current === 'approved' && $cost <= $approved) {
            $status = 'approved'; // still covered by the earlier approval
        } else {
            $status = 'pending';
        }

        // nothing to record for a sub-threshold job with no maintenance row
        if ($status === 'not_required' && ! $m) {
            return;
        }

        $contract->maintenance()->updateOrCreate(
            ['contract_id' => $contract->id],
            ['approval_status' => $status]
        );
    }

    /**
     * Keep the vehicle's Deferred Maintenance flag in step with a newly-created contract, while
     * preserving the single-open-contract invariant (no simultaneous Rental + Maintenance):
     *   • a Rental (C) written for a car currently in the workshop pulls it out early for a customer
     *     → CLOSE the maintenance ticket first, then raise the deferred-maintenance flag (the whole
     *       [Maintenance] → [Closed + Flagged] → [Rental] transition lives in OperationsService);
     *   • a Maintenance (U) contract means the car is (back) in the shop → clear any pending flag.
     */
    protected function syncDeferredMaintenance(Contract $contract): void
    {
        if (! $contract->vehicle_id) {
            return;
        }
        $vehicle = Vehicle::find($contract->vehicle_id);
        if (! $vehicle) {
            return;
        }

        if ($contract->contract_type === 'U') {
            $this->operations->resolveDeferredMaintenance($vehicle);
        } elseif ($contract->contract_type === 'C' && $this->operations->vehicleInMaintenance($vehicle->id)) {
            // Close the open maintenance ticket and flag the car — no dual open contracts.
            $this->operations->deferMaintenanceForRental($vehicle, $contract->opened_by);
        }
    }

    /** Pull the maintenance-header fields out of the contract payload (by reference). */
    protected function extractMaintenance(array &$data): array
    {
        $m = [];
        foreach (self::MAINTENANCE_FIELDS as $k) {
            if (array_key_exists($k, $data)) {
                $m[$k] = $data[$k];
                unset($data[$k]);
            }
        }
        return $m;
    }

    /**
     * Upsert the contract's maintenance header. Skips entirely when there's nothing
     * to store and no existing header (e.g. a plain rental), so rent contracts never
     * get an empty maintenance row.
     */
    protected function syncMaintenance(Contract $contract, array $m): void
    {
        $hasData = collect($m)->contains(fn ($v) => $v !== null && $v !== '' && $v !== []);
        if (! $hasData && ! $contract->maintenance()->exists()) {
            return;
        }

        // Link the situation to the controlled reason vocabulary whenever tags/notes
        // are supplied, so the priority/status follows the chosen reason.
        if (array_key_exists('maintenance_tags', $m) || array_key_exists('maintenance_notes', $m)) {
            $reason = app(MaintenanceAnalyticsService::class)
                ->reasonFor($m['maintenance_tags'] ?? [], $m['maintenance_notes'] ?? null);
            $m['maintenance_reason_id'] = $reason?->id;
        }

        $contract->maintenance()->updateOrCreate(['contract_id' => $contract->id], $m);
    }

    /** Sum of the maintenance line-item costs. */
    protected function itemsTotal(array $items): float
    {
        return round(collect($items)->sum(fn ($i) => (float) ($i['cost'] ?? 0)), 2);
    }

    /**
     * Replace a contract's maintenance items with the supplied list.
     * Null = "not provided" -> leave existing items untouched (e.g. a rental edit).
     * Blank service names are skipped (empty invoice rows).
     */
    protected function syncItems(Contract $contract, ?array $items): void
    {
        if ($items === null) {
            return;
        }

        $contract->items()->delete();

        foreach ($items as $i) {
            if (trim((string) ($i['service_name'] ?? '')) === '') {
                continue;
            }
            $contract->items()->create([
                'service_name' => $i['service_name'],
                'cost'         => (float) ($i['cost'] ?? 0),
                'notes'        => $i['notes'] ?? null,
            ]);
        }
    }
}
