<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\FuelFill;
use App\Models\LogisticsTask;
use App\Models\Vehicle;
use App\Models\VehicleRegistration;
use App\Models\VehicleWashJob;
use App\Services\VehicleOperatingCostService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recording the four everyday running costs: fuel, car wash, registration renewal and the driver's fare.
 *
 * Each endpoint hangs off the OPERATIONAL record it belongs to — a vehicle for a fill or a wash, a
 * registration for a renewal, a movement for a fare — rather than off a finance collection. That is
 * §6 expressed as a URL: there is no `/expenses` here to visit, because there is no expenses module.
 *
 * The financial obligation is raised by the service as a side effect of recording the operational fact,
 * so a user never enters a cost twice and never has to remember a second screen.
 */
class VehicleOperatingCostController extends Controller
{
    /** Receipts are ordinary documents — an image or a PDF, ≤ 8 MB, matching the workflow's own limit. */
    private const RECEIPT_RULES = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'];

    public function __construct(private VehicleOperatingCostService $costs)
    {
    }

    // ── Fuel ──────────────────────────────────────────────────────────────────────────────────────

    public function fuelIndex(Vehicle $vehicle)
    {
        try {
            $fills = FuelFill::with(['vendor:id,name', 'recordedBy:id,name'])
                ->forVehicle($vehicle->id)
                ->orderByDesc('filled_at')
                ->limit(100)
                ->get();

            return ResponseHelper::SuccessResponse($fills, 'Fuel fills');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function storeFuel(Request $request, Vehicle $vehicle)
    {
        try {
            $data = $request->validate([
                'filled_at'      => ['nullable', 'date'],
                // The two facts that make this more than a receipt. Optional because a receipt keyed
                // days later may genuinely not have them, and refusing the cost over a missing litre
                // count would lose the money to save the analytics.
                'litres'         => ['nullable', 'numeric', 'gt:0', 'max:2000'],
                'odometer_km'    => ['nullable', 'integer', 'min:0', 'max:9999999'],
                'fuel_grade'     => ['nullable', 'string', 'max:32'],
                'vendor_id'      => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                'station_name'   => ['nullable', 'string', 'max:191'],
                'cost'           => ['required', 'numeric', 'gte:0', 'max:9999999'],
                'currency'       => ['nullable', 'string', 'max:8'],
                'receipt_no'     => ['nullable', 'string', 'max:128'],
                'receipt_date'   => ['nullable', 'date'],
                'maintenance_id' => ['nullable', 'integer', Rule::exists('maintenances', 'id')],
                'notes'          => ['nullable', 'string', 'max:2000'],
                'receipt'        => self::RECEIPT_RULES,
            ]);

            $fill = $this->costs->recordFuelFill($vehicle, $data, $request->user(), $request->file('receipt'));

            return ResponseHelper::SuccessResponse($fill->fresh(['vendor:id,name']), 'Fuel recorded', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function updateFuel(Request $request, FuelFill $fill)
    {
        try {
            $data = $request->validate([
                'filled_at'      => ['sometimes', 'date'],
                'litres'         => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:2000'],
                'odometer_km'    => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999999'],
                'fuel_grade'     => ['sometimes', 'nullable', 'string', 'max:32'],
                'vendor_id'      => ['sometimes', 'nullable', 'integer', Rule::exists('vendors', 'id')],
                'station_name'   => ['sometimes', 'nullable', 'string', 'max:191'],
                'cost'           => ['sometimes', 'numeric', 'gte:0', 'max:9999999'],
                'currency'       => ['sometimes', 'nullable', 'string', 'max:8'],
                'receipt_no'     => ['sometimes', 'nullable', 'string', 'max:128'],
                'receipt_date'   => ['sometimes', 'nullable', 'date'],
                'maintenance_id' => ['sometimes', 'nullable', 'integer', Rule::exists('maintenances', 'id')],
                'notes'          => ['sometimes', 'nullable', 'string', 'max:2000'],
                'receipt'        => self::RECEIPT_RULES,
            ]);

            $updated = $this->costs->updateFuelFill($fill, $data, $request->user(), $request->file('receipt'));

            return ResponseHelper::SuccessResponse($updated->fresh(['vendor:id,name']), 'Fuel fill updated');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── Car wash ──────────────────────────────────────────────────────────────────────────────────

    public function washIndex(Vehicle $vehicle)
    {
        try {
            $washes = VehicleWashJob::with(['vendor:id,name', 'recordedBy:id,name'])
                ->where('vehicle_id', $vehicle->id)
                ->orderByDesc('washed_at')
                ->limit(100)
                ->get();

            return ResponseHelper::SuccessResponse($washes, 'Wash history');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Record a wash. Also moves the car's cleaning status to clean by default — recording a wash and
     * marking the car clean are the same act, and making a user do both would be the bug.
     */
    public function storeWash(Request $request, Vehicle $vehicle)
    {
        try {
            $data = $request->validate([
                'washed_at'         => ['nullable', 'date'],
                'wash_type'         => ['nullable', Rule::in(VehicleWashJob::TYPES)],
                'performed_by_kind' => ['nullable', Rule::in(VehicleWashJob::PERFORMERS)],
                'vendor_id'         => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                // Nullable, not required: an internal wash was never billed, and the service forces
                // both the cost and the vendor away for one.
                'cost'              => ['nullable', 'numeric', 'gte:0', 'max:999999'],
                'currency'          => ['nullable', 'string', 'max:8'],
                'invoice_no'        => ['nullable', 'string', 'max:128'],
                'invoice_date'      => ['nullable', 'date'],
                'notes'             => ['nullable', 'string', 'max:2000'],
                'set_clean'         => ['nullable', 'boolean'],
                'receipt'           => self::RECEIPT_RULES,
            ]);

            $wash = $this->costs->recordWash($vehicle, $data, $request->user(), $request->file('receipt'));

            return ResponseHelper::SuccessResponse($wash->fresh(['vendor:id,name']), 'Wash recorded', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── Registration renewal ──────────────────────────────────────────────────────────────────────

    /**
     * Record what a renewal cost. Touches only the renewal_* columns — the expiry, status and insurance
     * facts belong to the authority and arrive through the OfficeManager sync.
     */
    public function storeRenewal(Request $request, VehicleRegistration $registration)
    {
        try {
            $data = $request->validate([
                'renewal_cost'      => ['required', 'numeric', 'gt:0', 'max:9999999'],
                'renewal_currency'  => ['nullable', 'string', 'max:8'],
                'renewal_date'      => ['nullable', 'date'],
                'renewal_vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                'renewal_reference' => ['nullable', 'string', 'max:128'],
                'receipt'           => self::RECEIPT_RULES,
            ]);

            $updated = $this->costs->recordRegistrationRenewal(
                $registration,
                $data,
                $request->user(),
                $request->file('receipt')
            );

            return ResponseHelper::SuccessResponse(
                $updated->fresh(['vehicle:id,plate_no', 'renewalVendor:id,name']),
                'Registration renewal recorded'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── Taxi fare ─────────────────────────────────────────────────────────────────────────────────

    /** Record the driver's fare on a movement — the journey that caused it owns the cost. */
    public function storeTaxiFare(Request $request, LogisticsTask $task)
    {
        try {
            $data = $request->validate([
                'taxi_fare'      => ['required', 'numeric', 'gt:0', 'max:99999'],
                'taxi_currency'  => ['nullable', 'string', 'max:8'],
                'taxi_date'      => ['nullable', 'date'],
                'taxi_leg'       => ['nullable', Rule::in(LogisticsTask::TAXI_LEGS)],
                'taxi_from'      => ['nullable', 'string', 'max:191'],
                'taxi_to'        => ['nullable', 'string', 'max:191'],
                'taxi_reference' => ['nullable', 'string', 'max:128'],
                'receipt'        => self::RECEIPT_RULES,
            ]);

            $updated = $this->costs->recordTaxiFare($task, $data, $request->user(), $request->file('receipt'));

            return ResponseHelper::SuccessResponse($updated->fresh(['vehicle:id,plate_no']), 'Taxi fare recorded');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
