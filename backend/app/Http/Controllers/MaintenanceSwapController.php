<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\MaintenanceSwap;
use App\Services\MaintenanceForesightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Maintenance Swap Dashboard — the Kanban board for assigning a replacement car when a RENTED vehicle
 * needs urgent maintenance.
 *
 *   - board()   : the three feeds the UI renders — the maintenance QUEUE (Maintenance Foresight's
 *                 'Fix now' cars, the SAME urgent list the Foresight page shows, each tagged With
 *                 Customer / Available), the available POOL (status 'ready'), and the ACTIVE swaps.
 *   - store()   : the "Assign Replacement" transaction — records a replacement attached to an original
 *                 rental. Guarded so a car can't be double-booked.
 *   - release() : ends a swap (the original car is back).
 */
class MaintenanceSwapController extends Controller
{
    /** Full board state in one call, so the UI renders all columns + the attached links together. */
    public function board(MaintenanceForesightService $foresight)
    {
        try {
            // Active swaps, indexed both ways so we can annotate the queue (by original) and the pool
            // (by replacement) without N extra queries.
            $swaps = MaintenanceSwap::active()->orderByDesc('created_at')->get();
            $byOriginal    = $swaps->keyBy('original_vehicle_id');
            $byReplacement = $swaps->keyBy('replacement_vehicle_id');

            // QUEUE — the cars Maintenance Foresight flags 'Fix now' (tier 'act_now'): the exact same
            // urgent list the Foresight page shows, so the two pages never disagree. Each is tagged
            // WITH CUSTOMER (on an active rental → needs a swap to free it) or AVAILABLE (no rental →
            // can go straight to the workshop).
            $today  = now()->toDateString();
            $actNow = collect($foresight->report()['cars'] ?? [])->where('tier', 'act_now')->values();

            // Active rental (customer + contract) for the on-rent ones, fetched in a single query.
            $rentedIds = $actNow->where('operational_status', 'rented')->pluck('vehicle_id')->all();
            $activeRental = [];
            if (! empty($rentedIds)) {
                foreach (DB::table('contracts as c')->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
                    ->where('c.contract_type', 'C')->whereNull('c.deleted_at')->whereIn('c.vehicle_id', $rentedIds)
                    ->whereNotNull('c.out_date')->whereDate('c.out_date', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('c.in_date')->orWhereDate('c.in_date', '>=', $today))
                    ->orderByDesc('c.out_date')
                    ->get(['c.id', 'c.contract_no', 'c.vehicle_id', 'cu.name_en as customer']) as $r) {
                    $activeRental[(int) $r->vehicle_id] ??= $r;   // first seen = latest out_date
                }
            }

            $queue = $actNow->map(function ($c) use ($byOriginal, $activeRental) {
                $onRent = ($c['operational_status'] ?? null) === 'rented';
                $rc     = $activeRental[$c['vehicle_id']] ?? null;
                $swap   = $byOriginal->get($c['vehicle_id']);

                return [
                    'vehicle_id'  => $c['vehicle_id'],
                    'plate'       => $c['plate'],
                    'code'        => $c['code'] ?? null,
                    'car'         => $c['car'],
                    'year'        => $c['year'],
                    'status'      => $onRent ? 'with_customer' : 'available',  // the tag the UI distinguishes on
                    'customer'    => $rc->customer ?? null,
                    'contract_id' => $rc->id ?? null,
                    'contract_no' => $rc->contract_no ?? null,
                    'reason'      => $c['primary_issue'] ?? null,
                    'tier'        => $c['tier'],          // 'act_now' = Fix now
                    'swap'        => $swap ? $this->swapDigest($swap) : null,
                ];
            })->values();

            // POOL — cars genuinely available to deploy, decided by CONTRACTS, not the stale
            // vehicles.status field (≈half of 'ready' cars actually have an open rental right now). A car
            // is available only if no open 'C' rental AND no open 'U' maintenance contract spans today,
            // and it isn't sitting in the workshop. ('FASTER' drops the non-car "license" placeholder.)
            $openContract = DB::table('contracts')->whereIn('contract_type', ['C', 'U'])->whereNull('deleted_at')
                ->whereNotNull('out_date')->whereDate('out_date', '<=', $today)
                ->where(fn ($q) => $q->whereNull('in_date')->orWhereDate('in_date', '>=', $today))
                ->distinct()->pluck('vehicle_id')->filter()->map(fn ($x) => (int) $x)->all();

            $pool = DB::table('vehicles')->where('status', 'ready')
                ->where('day_rent_value', '>', 0)
                ->where('make', '!=', 'FASTER')
                ->where('operational_status', '!=', 'maintenance')
                ->when(! empty($openContract), fn ($q) => $q->whereNotIn('id', $openContract))
                ->orderBy('plate_no')
                ->get(['id', 'code', 'plate_no', 'make', 'model', 'year', 'day_rent_value', 'is_deferred_maintenance', 'deferred_maintenance_reason'])
                ->map(function ($v) use ($byReplacement) {
                    $swap = $byReplacement->get((int) $v->id);

                    return [
                        'vehicle_id'     => (int) $v->id,
                        'plate'          => $v->plate_no,
                        'code'           => $v->code,
                        'car'            => trim($v->make . ' ' . $v->model) ?: null,
                        'year'           => $v->year,
                        'day_rent_value' => $v->day_rent_value ? (float) $v->day_rent_value : null,
                        // Deferred Maintenance — a pool car that's free but still owes the workshop.
                        'owes_maintenance' => (bool) $v->is_deferred_maintenance,
                        'owes_maintenance_note' => $v->deferred_maintenance_reason,
                        'attached'       => $swap ? $this->swapDigest($swap) : null,
                    ];
                })->values();

            // WORKSHOP — cars actually in the shop right now (operational_status 'maintenance').
            $workshopRows = DB::table('vehicles')->where('operational_status', 'maintenance')
                ->where('make', '!=', 'FASTER')
                ->orderBy('plate_no')
                ->get(['id', 'code', 'plate_no', 'make', 'model', 'year']);
            // Best-effort "why it's in" from the latest sheet workshop event for those cars.
            $reasonByVeh = [];
            if ($workshopRows->isNotEmpty()) {
                foreach (DB::table('maintenances')->whereIn('origin', \App\Models\Maintenance::WORKSHOP_LOG_ORIGINS)
                    ->whereIn('vehicle_id', $workshopRows->pluck('id')->all())->whereNotNull('out_date')
                    ->orderBy('out_date')->orderBy('id')
                    ->get(['vehicle_id', 'service_main', 'maintenance_type']) as $m) {
                    $reasonByVeh[(int) $m->vehicle_id] = $m->service_main ?: $m->maintenance_type;   // last = latest
                }
            }
            $workshop = $workshopRows->map(fn ($v) => [
                'vehicle_id' => (int) $v->id,
                'plate'      => $v->plate_no,
                'code'       => $v->code,
                'car'        => trim($v->make . ' ' . $v->model) ?: null,
                'year'       => $v->year,
                'reason'     => $reasonByVeh[(int) $v->id] ?? 'In repair',
            ])->values();

            return ResponseHelper::SuccessResponse([
                'queue'    => $queue,
                'workshop' => $workshop,
                'pool'     => $pool,
                'swaps'    => $swaps->map(fn ($s) => $this->swapDigest($s))->values(),
                'summary' => [
                    'queue_count'      => $queue->count(),                                  // = Foresight 'Fix now'
                    'with_customer'    => $queue->where('status', 'with_customer')->count(),
                    'available'        => $queue->where('status', 'available')->count(),
                    'swapped_count'    => $queue->whereNotNull('swap')->count(),
                    'workshop_count'   => $workshop->count(),
                    'pool_count'       => $pool->count(),
                    'pool_free'        => $pool->whereNull('attached')->count(),
                    'active_swaps'     => $swaps->count(),
                ],
            ], 'Maintenance swap board retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The "Assign Replacement" transaction. Records the replacement ↔ original link.
     * body: { original_vehicle_id, replacement_vehicle_id, original_contract_id?, tenant_name?, reason?, notes? }
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'original_vehicle_id'    => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'replacement_vehicle_id' => ['required', 'integer', 'different:original_vehicle_id', Rule::exists('vehicles', 'id')],
                'original_contract_id'   => ['nullable', 'integer'],
                'tenant_name'            => ['nullable', 'string', 'max:255'],
                'reason'                 => ['nullable', 'string', 'max:255'],
                'notes'                  => ['nullable', 'string', 'max:1000'],
            ]);

            // Guard against double-booking either car.
            if (MaintenanceSwap::active()->where('replacement_vehicle_id', $data['replacement_vehicle_id'])->exists()) {
                return ResponseHelper::FailureResponse(null, 'That replacement car is already attached to another rental.', 422);
            }
            if (MaintenanceSwap::active()->where('original_vehicle_id', $data['original_vehicle_id'])->exists()) {
                return ResponseHelper::FailureResponse(null, 'This rental already has a replacement assigned.', 422);
            }

            // Snapshot the human-readable details server-side so the record is self-contained.
            $orig = DB::table('vehicles')->where('id', $data['original_vehicle_id'])->first(['plate_no', 'make', 'model']);
            $repl = DB::table('vehicles')->where('id', $data['replacement_vehicle_id'])->first(['plate_no', 'make', 'model']);
            $contractNo = $data['original_contract_id']
                ? DB::table('contracts')->where('id', $data['original_contract_id'])->value('contract_no')
                : null;

            $swap = MaintenanceSwap::create([
                'original_vehicle_id'    => $data['original_vehicle_id'],
                'original_plate'         => $orig->plate_no ?? null,
                'original_car'           => $orig ? (trim($orig->make . ' ' . $orig->model) ?: null) : null,
                'original_contract_id'   => $data['original_contract_id'] ?? null,
                'original_contract_no'   => $contractNo,
                'tenant_name'            => $data['tenant_name'] ?? null,
                'reason'                 => $data['reason'] ?? null,
                'replacement_vehicle_id' => $data['replacement_vehicle_id'],
                'replacement_plate'      => $repl->plate_no ?? null,
                'replacement_car'        => $repl ? (trim($repl->make . ' ' . $repl->model) ?: null) : null,
                'status'                 => MaintenanceSwap::STATUS_ACTIVE,
                'assigned_by'            => $request->user()?->name ?? $request->user()?->email,
                'notes'                  => $data['notes'] ?? null,
            ]);

            return ResponseHelper::SuccessResponse($this->swapDigest($swap), 'Replacement assigned', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse(null, $e->validator->errors()->first(), 422);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** End a swap — the original car is back, the replacement returns to the pool. */
    public function release(Request $request, MaintenanceSwap $maintenanceSwap)
    {
        try {
            if ($maintenanceSwap->status === MaintenanceSwap::STATUS_RELEASED) {
                return ResponseHelper::FailureResponse(null, 'This swap is already released.', 422);
            }

            $maintenanceSwap->update([
                'status'      => MaintenanceSwap::STATUS_RELEASED,
                'released_by' => $request->user()?->name ?? $request->user()?->email,
                'released_at' => now(),
            ]);

            return ResponseHelper::SuccessResponse($this->swapDigest($maintenanceSwap), 'Swap released', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Compact, UI-ready shape of a swap (used in every response). */
    private function swapDigest(MaintenanceSwap $s): array
    {
        return [
            'id'                     => $s->id,
            'original_vehicle_id'    => $s->original_vehicle_id,
            'original_plate'         => $s->original_plate,
            'original_car'           => $s->original_car,
            'original_contract_id'   => $s->original_contract_id,
            'original_contract_no'   => $s->original_contract_no,
            'tenant_name'            => $s->tenant_name,
            'reason'                 => $s->reason,
            'replacement_vehicle_id' => $s->replacement_vehicle_id,
            'replacement_plate'      => $s->replacement_plate,
            'replacement_car'        => $s->replacement_car,
            'status'                 => $s->status,
            'assigned_by'            => $s->assigned_by,
            'assigned_at'            => optional($s->created_at)->toDateTimeString(),
        ];
    }
}
