<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\ServiceContract;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The service contract on a car — "5 Lube Service / 5 Yrs", recorded from the car's own page.
 *
 * NO try/catch: the global handler in bootstrap/app.php shapes uncaught exceptions into the standard
 * envelope and lets ValidationException reach Laravel's native {message, errors} renderer, which is
 * the shape the form reads to highlight fields.
 *
 * Permissions ride with the warranty pair — `warranty.view` / `warranty.manage`. A service contract
 * is the same KIND of fact as a warranty (a promise bought with the car, recorded on the car, read
 * by whoever is deciding where to send it), and the person who records one records the other. A
 * third permission would be a distinction nobody in this fleet makes.
 */
class ServiceContractController extends Controller
{
    /**
     * Every contract on one car, each judged against the car's CURRENT odometer.
     *
     * The verdict is computed per read and never stored, for the same reason a warranty's is: the
     * distance leg depends on a number that changes every time the car is driven.
     */
    public function forVehicle(Vehicle $vehicle)
    {
        $odometer = $vehicle->odometer !== null ? (int) $vehicle->odometer : null;

        $rows = ServiceContract::forVehicle($vehicle->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ServiceContract $c) => array_merge($this->present($c), [
                'verdict' => $c->evaluate(null, $odometer),
            ]));

        return ResponseHelper::SuccessResponse([
            'vehicle'   => ['id' => $vehicle->id, 'plate_no' => $vehicle->plate_no, 'odometer' => $vehicle->odometer],
            'contracts' => $rows,
        ], 'Service contracts retrieved');
    }

    public function store(Request $request, Vehicle $vehicle)
    {
        $data = $this->validated($request);

        $contract = new ServiceContract($data + ['vehicle_id' => $vehicle->id]);
        $contract->created_by      = $request->user()?->id;
        $contract->created_by_name = $request->user()?->name;
        $contract->save();

        return ResponseHelper::SuccessResponse($this->present($contract->fresh()), 'Service contract recorded', 201);
    }

    public function update(Request $request, ServiceContract $contract)
    {
        $contract->fill($this->validated($request));
        $contract->updated_by      = $request->user()?->id;
        $contract->updated_by_name = $request->user()?->name;
        $contract->save();

        return ResponseHelper::SuccessResponse($this->present($contract->fresh()), 'Service contract updated');
    }

    /**
     * Record that one covered service has been used.
     *
     * A deliberate, explicit act rather than a count derived from service_records — because not every
     * service on a car is one of the five. A puncture repaired at a roadside garage is a service
     * record and is NOT a contract service, and a counter that quietly disagreed with the dealer's
     * would be worse than one somebody owns. @see ServiceContract::$services_used
     */
    public function useService(Request $request, ServiceContract $contract)
    {
        $data = $request->validate([
            'odometer'   => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'service_on' => ['nullable', 'date'],
        ]);

        $contract->services_used          = (int) $contract->services_used + 1;
        $contract->last_service_odometer  = $data['odometer'] ?? $contract->vehicle?->odometer ?? $contract->last_service_odometer;
        $contract->last_service_on        = $data['service_on'] ?? now()->toDateString();
        $contract->updated_by             = $request->user()?->id;
        $contract->updated_by_name        = $request->user()?->name;
        $contract->save();

        return ResponseHelper::SuccessResponse($this->present($contract->fresh()), 'Service recorded against the contract');
    }

    /** End it deliberately — the car was sold, the contract cancelled. The row is kept. */
    public function end(Request $request, ServiceContract $contract)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $contract->status = ServiceContract::STATUS_ENDED;
        if (! empty($data['reason'])) {
            $contract->notes = trim(($contract->notes ? $contract->notes . "\n" : '') . $data['reason']);
        }
        $contract->updated_by      = $request->user()?->id;
        $contract->updated_by_name = $request->user()?->name;
        $contract->save();

        return ResponseHelper::SuccessResponse($this->present($contract->fresh()), 'Service contract ended');
    }

    /**
     * Shape only. Every field is optional except the car, because the paperwork arrives incomplete
     * far more often than not — a contract with nothing but "5 Lube Service/5Yrs" and a provider is
     * still worth recording, and refusing it would mean the fact never gets written down at all.
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'coverage_label'        => ['nullable', 'string', 'max:200'],
            'provider_name'         => ['nullable', 'string', 'max:200'],
            'contact_phone'         => ['nullable', 'string', 'max:60'],
            'services_total'        => ['nullable', 'integer', 'min:0', 'max:99'],
            'services_used'         => ['nullable', 'integer', 'min:0', 'max:99'],
            'interval_km'           => ['nullable', 'integer', 'min:0', 'max:200000'],
            'starts_on'             => ['nullable', 'date'],
            'ends_on'               => ['nullable', 'date'],
            // An ABSOLUTE odometer reading, as the fleet's report states it (50000) — not a distance.
            'ends_at_km'            => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'last_service_odometer' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'last_service_on'       => ['nullable', 'date'],
            'status'                => ['nullable', Rule::in(ServiceContract::STATUSES)],
            'notes'                 => ['nullable', 'string', 'max:4000'],
        ]);
    }

    /** The row as the card reads it. Flat, and the label is always the words on the document. */
    private function present(ServiceContract $c): array
    {
        return [
            'id'                    => $c->id,
            'vehicle_id'            => $c->vehicle_id,
            'coverage_label'        => $c->coverage_label,
            'provider_name'         => $c->provider_name,
            'contact_phone'         => $c->contact_phone,
            'services_total'        => $c->services_total,
            'services_used'         => (int) $c->services_used,
            'services_remaining'    => $c->servicesRemaining(),
            'interval_km'           => $c->interval_km,
            'starts_on'             => $c->starts_on?->toDateString(),
            'ends_on'               => $c->ends_on?->toDateString(),
            'ends_at_km'            => $c->ends_at_km,
            'last_service_odometer' => $c->last_service_odometer,
            'last_service_on'       => $c->last_service_on?->toDateString(),
            'next_service_due_at_km' => $c->nextServiceDueAtKm(),
            'status'                => $c->status,
            'notes'                 => $c->notes,
        ];
    }
}
