<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Services\RealProfitService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quick Cost Input — closes the "structurally understated spend" gap. Most workshop repairs
 * arrive from the sheet with no cost, so vehicle Repair-Spend (and therefore Negative-Yield)
 * is under-counted. This lists recent repairs that carry NO cost and lets a cost be entered in
 * one tap, then re-computes the vehicle's Real-Net-Profit yield on the spot.
 *
 * Safe write: the sheet importer only sets `cost` when the sheet cell is non-empty and `cost`
 * is excluded from the row-hash, so a manually-entered cost survives re-imports untouched.
 */
class CostCaptureController extends Controller
{
    public function __construct(private RealProfitService $realProfit)
    {
    }

    /**
     * Recent repair VISITS (grouped vehicle|out_date) within the yield window that carry no cost
     * on ANY of their rows — newest first. Each entry points at the single row to write cost to,
     * so multi-row ping-pong visits are costed once.
     */
    public function index(Request $request)
    {
        try {
            $cutoff = Carbon::today()->subMonths(RealProfitService::YIELD_MONTHS)->toDateString();

            $rows = DB::table('maintenances as m')
                ->leftJoin('vehicles as v', 'v.id', '=', 'm.vehicle_id')
                ->leftJoin('vendors as vd', 'vd.id', '=', 'm.vendor_id')
                ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                ->whereNotNull('m.vehicle_id')
                ->whereNotNull('m.out_date')
                ->whereDate('m.out_date', '>=', $cutoff)
                ->orderByDesc('m.out_date')
                ->get([
                    'm.id', 'm.vehicle_id', 'm.out_date', 'm.actual_in_date', 'm.event_status',
                    'm.service_main', 'm.maintenance_type', 'm.cost', 'm.garage',
                    'v.plate_no', 'v.make', 'v.model', 'v.code', 'vd.name as vendor',
                ]);

            // Group rows into visits; a visit is "uncosted" only when NONE of its rows has a cost.
            $visits = [];
            foreach ($rows as $r) {
                $key = $r->vehicle_id . '|' . substr((string) $r->out_date, 0, 10);
                $visits[$key] ??= ['rows' => [], 'has_cost' => false, 'rep' => null];
                $visits[$key]['rows'][] = $r;
                if ((float) $r->cost > 0) {
                    $visits[$key]['has_cost'] = true;
                }
                // Representative row to write cost to: prefer the 'IN' (return) row, else newest id.
                $rep = $visits[$key]['rep'];
                if ($rep === null
                    || ($r->event_status === 'IN' && $rep->event_status !== 'IN')
                    || ($r->event_status === $rep->event_status && $r->id > $rep->id)) {
                    $visits[$key]['rep'] = $r;
                }
            }

            $out = [];
            foreach ($visits as $vis) {
                if ($vis['has_cost'] || count($out) >= 200) {
                    continue;
                }
                $rep = $vis['rep'];

                $problems = [];
                foreach ($vis['rows'] as $r) {
                    foreach (preg_split('/\s*,\s*/', (string) $r->service_main, -1, PREG_SPLIT_NO_EMPTY) as $p) {
                        if (! in_array($p, $problems, true)) {
                            $problems[] = $p;
                        }
                    }
                }

                $out[] = [
                    'id'         => $rep->id,
                    'vehicle_id' => $rep->vehicle_id,
                    'plate'      => $rep->plate_no,
                    'code'       => $rep->code,
                    'car'        => trim($rep->make . ' ' . $rep->model) ?: null,
                    'out_date'   => substr((string) $rep->out_date, 0, 10),
                    'in_date'    => $rep->actual_in_date ? substr((string) $rep->actual_in_date, 0, 10) : null,
                    'garage'     => $rep->vendor ?: $rep->garage,
                    'type'       => $rep->maintenance_type,
                    'problem'    => implode(', ', array_slice($problems, 0, 4)) ?: null,
                    'events'     => count($vis['rows']),
                ];
            }

            return ResponseHelper::SuccessResponse([
                'repairs'       => $out,
                'window_months' => RealProfitService::YIELD_MONTHS,
            ], 'Uncosted repairs retrieved successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Save a repair cost on a workshop row, then re-compute that vehicle's yield so the UI can
     * reflect the new Repair-Spend and any Negative-Yield status change immediately.
     */
    public function store(Request $request, Maintenance $workshopEvent)
    {
        try {
            $data = $request->validate(['cost' => 'required|numeric|min:0']);

            if (! in_array($workshopEvent->origin, Maintenance::WORKSHOP_LOG_ORIGINS, true)) {
                return ResponseHelper::FailureResponse(null, 'This is not a workshop repair event.', 422);
            }

            $vid = $workshopEvent->vehicle_id;
            $before = $vid ? ($this->realProfit->vehicleYield([$vid])[$vid] ?? null) : null;

            $workshopEvent->cost = round((float) $data['cost'], 2);
            $workshopEvent->save();

            $after = $vid ? ($this->realProfit->vehicleYield([$vid])[$vid] ?? null) : null;

            $wasNeg = (bool) ($before['negative_yield'] ?? false);
            $isNeg  = (bool) ($after['negative_yield'] ?? false);

            return ResponseHelper::SuccessResponse([
                'id'              => $workshopEvent->id,
                'cost'            => (float) $workshopEvent->cost,
                'vehicle_id'      => $vid,
                'yield'           => $after,
                'status_changed'  => $wasNeg !== $isNeg,
                'became_negative' => ! $wasNeg && $isNeg,
            ], 'Repair cost saved successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
