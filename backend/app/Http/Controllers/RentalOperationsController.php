<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Services\VehicleReadinessService;

/**
 * Rental Operations Hub — the Rental Manager's check-in / check-out surface.
 *
 * Lists the cars that are currently OUT on a rental (open type-C) or reserved on an active/upcoming
 * booking (type-R), and stamps each with the same live 9-check readiness verdict the contract detail
 * page shows in full (VehicleReadinessService::evaluate — condition, maintenance, damage, registration,
 * insurance, check-in, cleaning, service, GPS). This is the ONE place a manager decides whether a car
 * is fit to hand over or take back.
 *
 * Deliberately NOT an event feed: the maintenance workflow trail lives in the Maintenance Hub. Readiness
 * is evaluated once per DISTINCT vehicle so a customer holding two contracts on the same car is costed
 * only once.
 */
class RentalOperationsController extends Controller
{
    public function __construct(private VehicleReadinessService $readiness)
    {
    }

    public function index()
    {
        try {
            // Active rentals (car physically out) + active/upcoming bookings. Maintenance (type-U) is
            // excluded — those cars belong to the shop, not the rental desk.
            $contracts = Contract::query()
                ->with(['customer', 'vehicle.registration'])
                ->where(function ($q) {
                    $q->where(fn ($sub) => $sub->where('contract_type', 'C')->currentlyOpen())
                      ->orWhere(fn ($sub) => $sub->upcomingReservation());
                })
                ->orderByDesc('out_date')
                ->orderByDesc('id')
                ->get();

            $cache = [];  // vehicle_id => compact readiness verdict (dedup live evaluations)
            $rows = $contracts->map(function (Contract $c) use (&$cache) {
                $readiness = null;
                if ($c->vehicle) {
                    $vid = $c->vehicle->id;
                    if (! array_key_exists($vid, $cache)) {
                        $cache[$vid] = $this->compactReadiness($this->readiness->evaluate($c->vehicle), $c->vehicle);
                    }
                    $readiness = $cache[$vid];
                }

                return [
                    'id'            => $c->id,
                    'contract_no'   => $c->contract_no,
                    'contract_type' => $c->contract_type,
                    'state'         => $c->state,
                    'out_date'      => $c->out_date,
                    'in_date'       => $c->in_date,
                    'days'          => $c->days,
                    'customer'      => $c->customer ? [
                        'id'          => $c->customer->id,
                        'customer_no' => $c->customer->customer_no,
                        'name_en'     => $c->customer->name_en,
                    ] : null,
                    'vehicle'       => $c->vehicle ? [
                        'id'       => $c->vehicle->id,
                        'plate_no' => $c->vehicle->plate_no,
                        'make'     => $c->vehicle->make,
                        'model'    => $c->vehicle->model,
                    ] : null,
                    'readiness'     => $readiness,
                ];
            })->values();

            return ResponseHelper::SuccessResponse([
                'items'   => $rows,
                'total'   => $rows->count(),
                'blocked' => $rows->filter(fn ($r) => $r['readiness']['blocked'] ?? false)->count(),
            ], 'Rental operations retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Trim the full evaluation down to the at-a-glance verdict the hub list needs. */
    private function compactReadiness(array $ev, $vehicle): array
    {
        return [
            'ready'           => $ev['ready'],
            'blocked'         => $ev['blocked'],
            'blocker_count'   => count($ev['blockers']),
            'warn_count'      => count(array_filter($ev['checks'], fn ($c) => $c['status'] === 'warn')),
            'pass_count'      => count(array_filter($ev['checks'], fn ($c) => $c['status'] === 'pass')),
            'total'           => count($ev['checks']),
            'summary'         => $ev['summary'],
            'condition_grade' => $vehicle->condition_grade,
        ];
    }
}
